<?php

declare(strict_types=1);

namespace Akapack\Bot\Handler;

/**
 * Penghasil balasan untuk percakapan dalam mode BOT.
 *
 * Fase 0  -> EchoHandler (echo saja).
 * Fase 1  -> ClaudeHandler (FAQ via Claude API).
 * Fase 2+ -> Claude + tools Supabase.
 *
 * Kembalikan string balasan, atau null bila tidak ada yang perlu dikirim.
 */
interface Handler
{
    public function handle(string $waId, string $text): ?string;
}
