<?php

declare(strict_types=1);

namespace Akapack\Bot;

use Akapack\Bot\Handler\ClaudeHandler;
use Akapack\Bot\Handler\EchoHandler;
use Akapack\Bot\Handler\Handler;
use Akapack\Bot\Llm\AnthropicClient;
use Akapack\Bot\Store\FileStore;
use Akapack\Bot\Store\RedisStore;
use Akapack\Bot\Store\Store;

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

        $this->handler = $this->buildHandler();
    }

    /**
     * Fase 1: ClaudeHandler bila ANTHROPIC_API_KEY terisi (dan SDK tersedia);
     * jika tidak, fallback ke EchoHandler (mis. dev tanpa kredensial).
     */
    private function buildHandler(): Handler
    {
        if ($this->config->anthropicApiKey !== '' && class_exists(\Anthropic\Client::class)) {
            $llm = new AnthropicClient($this->config->anthropicApiKey, $this->config->claudeModel);
            $system = SystemPrompt::faq($this->config->botName, $this->config->companyName);
            return new ClaudeHandler($llm, $system);
        }

        $this->logger->warn('ANTHROPIC_API_KEY kosong / SDK absen — pakai EchoHandler (Fase 0)');
        return new EchoHandler($this->config->botName);
    }

    public function receiver(): Receiver
    {
        return new Receiver($this->config, $this->store, $this->logger);
    }

    public function worker(): Worker
    {
        return new Worker($this->config, $this->store, $this->whatsapp, $this->handler, $this->logger);
    }

    private function buildStore(): Store
    {
        if ($this->config->queueDriver === 'redis') {
            return RedisStore::fromDsn($this->config->redisDsn);
        }
        return new FileStore($this->config->dataDir);
    }
}
