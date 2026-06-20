<?php

declare(strict_types=1);

namespace Akapack\Bot;

use Akapack\Bot\Handler\EchoHandler;
use Akapack\Bot\Handler\Handler;
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

        // Fase 0: echo. Ganti ke ClaudeHandler di Fase 1.
        $this->handler = new EchoHandler($this->config->botName);
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
