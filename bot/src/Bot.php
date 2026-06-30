<?php

declare(strict_types=1);

namespace Akapack\Bot;

use Akapack\Bot\Handler\ClaudeHandler;
use Akapack\Bot\Handler\ClaudeToolHandler;
use Akapack\Bot\Handler\EchoHandler;
use Akapack\Bot\Handler\Handler;
use Akapack\Bot\Llm\AnthropicClient;
use Akapack\Bot\Llm\GeminiClient;
use Akapack\Bot\Llm\LlmClient;
use Akapack\Bot\Memory\ConversationMemory;
use Akapack\Bot\Memory\NullMemory;
use Akapack\Bot\Memory\SupabaseMemory;
use Akapack\Bot\Store\FileStore;
use Akapack\Bot\Store\RedisStore;
use Akapack\Bot\Store\Store;
use Akapack\Bot\Supabase\SupabaseClient;
use Akapack\Bot\Supabase\SupabaseTools;
use Akapack\Bot\Tool\ToolRunner;

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
     *  - LLM (Gemini/Claude) + Supabase → ClaudeToolHandler (Fase 2/3, tools + memori)
     *  - LLM saja                       → ClaudeHandler (Fase 1, FAQ)
     *  - tidak ada LLM                  → EchoHandler (Fase 0, dev)
     */
    private function buildHandler(?SupabaseClient $db): Handler
    {
        $llm = $this->buildLlm();
        if ($llm === null) {
            $this->logger->warn('Tidak ada API key LLM (GEMINI_API_KEY/ANTHROPIC_API_KEY) — EchoHandler (Fase 0)');
            return new EchoHandler($this->config->botName);
        }

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

        $this->logger->info('Supabase belum dikonfigurasi — handler FAQ (Fase 1)');
        return new ClaudeHandler($llm, SystemPrompt::faq($this->config->botName, $this->config->companyName), $this->memory);
    }

    /** Pilih provider LLM. Default auto: Gemini bila ada GEMINI_API_KEY, lalu Claude. */
    private function buildLlm(): (LlmClient&ToolRunner)|null
    {
        $provider = $this->config->llmProvider;
        if ($provider === '') {
            $provider = $this->config->geminiApiKey !== '' ? 'gemini'
                : ($this->config->anthropicApiKey !== '' ? 'claude' : '');
        }

        if ($provider === 'gemini' && $this->config->geminiApiKey !== '') {
            $this->logger->info('LLM provider: gemini', ['model' => $this->config->geminiModel]);
            return new GeminiClient($this->config->geminiApiKey, $this->config->geminiModel, $this->logger);
        }
        if ($provider === 'claude' && $this->config->anthropicApiKey !== '' && class_exists(\Anthropic\Client::class)) {
            $this->logger->info('LLM provider: claude', ['model' => $this->config->claudeModel]);
            return new AnthropicClient($this->config->anthropicApiKey, $this->config->claudeModel);
        }
        return null;
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
