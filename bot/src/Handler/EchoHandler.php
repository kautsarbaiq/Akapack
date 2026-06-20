<?php

declare(strict_types=1);

namespace Akapack\Bot\Handler;

/**
 * Handler Fase 0: echo. Membuktikan pipeline async (receiver -> queue ->
 * worker -> send) bekerja, tanpa memanggil Claude. Diganti ClaudeHandler di Fase 1.
 */
final class EchoHandler implements Handler
{
    public function __construct(private readonly string $botName)
    {
    }

    public function handle(string $waId, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        return "[{$this->botName} • echo Fase 0]\n" . $text;
    }
}
