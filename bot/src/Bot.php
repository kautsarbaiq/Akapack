<?php

declare(strict_types=1);

namespace Akapack\Bot;

use Akapack\Bot\Handler\ClaudeHandler;
use Akapack\Bot\Handler\ClaudeToolHandler;
use Akapack\Bot\Handler\EchoHandler;
use Akapack\Bot\Handler\Handler;
use Akapack\Bot\Llm\AnthropicClient;
use Akapack\Bot\Memory\ConversationMemory;
use Akapack\Bot\Memory\NullMemory;
use Akapack\Bot\Memory\SupabaseMemory;
use Akapack\Bot\Store\FileStore;
use Akapack\Bot\Store\RedisStore;
use Akapack\Bot\Store\Store;
use Akapack\Bot\Supabase\SupabaseClient;
use Akapack\Bot\Supabase\SupabaseTools;

/**
 * Perakitan (composition root). Membaca konfigurasi & menyusun komponen.
 * Satu tempat untuk menukar driver / handler antar fase.
 */
final class Bot
{
    public readonly Config $config;
    public readonly Logger $logger;
    public readonly Store $store;
    public readonly WhatsApp $whatsapp;
    public readonly ConversationMemory $memory;
    public readonly Escalator $escalator;
    public readonly Handler $handler;

    public function __construct(string $baseDir)
    {
        Env::load($baseDir . '/.env');
        $this->config = Config::fromEnv($baseDir);

        if (!is_dir($this->config->dataDir)) {
            @mkdir($this->config->dataDir, 0775, true);
        }

        $this->logger = new Logger(
            $this->config->dataDir . '/bot.log',
            toStderr: PHP_SAPI === 'cli',
        );

        $this->store = $this->buildStore();
        $this->whatsapp = new WhatsApp(
            $this->config,
            $this->logger,
            $this->config->dataDir . '/outgoing.log',
        );

        $db = $this->buildSupabase();
        $this->memory = $db !== null
            ? new SupabaseMemory($db, $this->logger, $this->config->tenantId)
            : new NullMemory();
        $this->escalator = new Escalator($this->config, $this->store, $this->whatsapp, $this->logger, $this->memory);
        $this->handler = $this->buildHandler($db);
    }

    /**
     * Pemilihan handler bertahap:
     *  - Claude + Supabase terisi → ClaudeToolHandler (Fase 2/3, tools + memori)
     *  - Claude saja              → ClaudeHandler (Fase 1, FAQ)
     *  - tidak ada                → EchoHandler (Fase 0, dev)
     */
    private function buildHandler(?SupabaseClient $db): Handler
    {
        $claudeReady = $this->config->anthropicApiKey !== '' && class_exists(\Anthropic\Client::class);
        if (!$claudeReady) {
            $this->logger->warn('ANTHROPIC_API_KEY kosong / SDK absen — pakai EchoHandler (Fase 0)');
            return new EchoHandler($this->config->botName);
        }

        $llm = new AnthropicClient($this->config->anthropicApiKey, $this->config->claudeModel);

        if ($db !== null) {
            $tools = new SupabaseTools($db, $this->config->tenantId, $this->config->outletBandung, $this->config->outletGarut);
            return new ClaudeToolHandler(
                $llm,
                SystemPrompt::withTools($this->config->botName, $this->config->companyName),
                $tools,
                $this->escalator,
                $this->memory,
            );
        }

        $this->logger->info('Supabase belum dikonfigurasi — pakai ClaudeHandler FAQ (Fase 1)');
        return new ClaudeHandler($llm, SystemPrompt::faq($this->config->botName, $this->config->companyName), $this->memory);
    }

    public function receiver(): Receiver
    {
        return new Receiver($this->config, $this->store, $this->logger);
    }

    public function worker(): Worker
    {
        return new Worker($this->config, $this->store, $this->whatsapp, $this->handler, $this->logger, $this->escalator);
    }

    private function buildSupabase(): ?SupabaseClient
    {
        if ($this->config->supabaseUrl === '' || $this->config->supabaseAnonKey === '') {
            return null;
        }
        return new SupabaseClient($this->config->supabaseUrl, $this->config->supabaseAnonKey, $this->logger);
    }

    private function buildStore(): Store
    {
        if ($this->config->queueDriver === 'redis') {
            return RedisStore::fromDsn($this->config->redisDsn);
        }
        return new FileStore($this->config->dataDir);
    }
}
