<?php

declare(strict_types=1);

namespace Akapack\Bot\Handler;

use Akapack\Bot\Llm\LlmClient;
use Akapack\Bot\Memory\ConversationMemory;

/**
 * Handler Fase 1: balas FAQ via Claude, dengan memori percakapan (sliding-window).
 *
 * Catatan: error API (rate limit/overload/jaringan) dibiarkan melempar exception
 * agar Worker menjadwalkan ulang (retry). Refusal classifier -> balasan aman.
 */
final class ClaudeHandler implements Handler
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $systemPrompt,
        private readonly ConversationMemory $memory,
        private readonly int $historyTurns = 12,
    ) {
    }

    public function handle(string $waId, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $history = $this->memory->recent($waId, $this->historyTurns);
        $messages = array_merge($history, [['role' => 'user', 'content' => $text]]);

        $res = $this->llm->reply($this->systemPrompt, $messages);

        $reply = ($res['refused'] || trim($res['text']) === '')
            ? "Maaf kak, untuk yang ini aku sambungkan ke admin ya 🙏 Ketik *admin* untuk terhubung langsung dengan tim kami."
            : $res['text'];

        $this->memory->append($waId, 'user', $text);
        $this->memory->append($waId, 'assistant', $reply);
        return $reply;
    }
}
