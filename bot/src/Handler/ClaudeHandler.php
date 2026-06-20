<?php

declare(strict_types=1);

namespace Akapack\Bot\Handler;

use Akapack\Bot\Llm\LlmClient;

/**
 * Handler Fase 1: balas FAQ via Claude. Di Fase 2 ditambah tools Supabase.
 *
 * Catatan: error API (rate limit/overload/jaringan) dibiarkan melempar exception
 * agar Worker menjadwalkan ulang (retry). Refusal classifier -> balasan aman
 * yang mengarahkan ke admin (bukan exception).
 */
final class ClaudeHandler implements Handler
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $systemPrompt,
    ) {
    }

    public function handle(string $waId, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $res = $this->llm->reply($this->systemPrompt, [
            ['role' => 'user', 'content' => $text],
        ]);

        if ($res['refused'] || trim($res['text']) === '') {
            return "Maaf kak, untuk yang ini aku sambungkan ke admin ya 🙏 "
                . "Ketik *admin* untuk terhubung langsung dengan tim kami.";
        }

        return $res['text'];
    }
}
