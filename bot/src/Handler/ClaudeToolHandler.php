<?php

declare(strict_types=1);

namespace Akapack\Bot\Handler;

use Akapack\Bot\Escalator;
use Akapack\Bot\Memory\ConversationMemory;
use Akapack\Bot\Supabase\SupabaseTools;
use Akapack\Bot\Tool\RequestTools;
use Akapack\Bot\Tool\ToolRunner;

/**
 * Handler Fase 2/3: Claude + tools Supabase (produk/stok/harga/kategori) +
 * eskalasi_ke_admin, dengan memori percakapan (sliding-window).
 */
final class ClaudeToolHandler implements Handler
{
    /** @var array<int,array<string,mixed>> */
    private readonly array $tools;

    public function __construct(
        private readonly ToolRunner $llm,
        private readonly string $systemPrompt,
        private readonly SupabaseTools $supabase,
        private readonly Escalator $escalator,
        private readonly ConversationMemory $memory,
        private readonly int $historyTurns = 12,
    ) {
        $this->tools = array_merge(
            SupabaseTools::definitions(),
            [RequestTools::escalationDefinition()],
        );
    }

    public function handle(string $waId, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $history = $this->memory->recent($waId, $this->historyTurns);
        $messages = array_merge($history, [['role' => 'user', 'content' => $text]]);

        $executor = new RequestTools($waId, $this->supabase, $this->escalator);
        $res = $this->llm->run($this->systemPrompt, $messages, $this->tools, $executor);

        $reply = ($res['refused'] || trim($res['text']) === '')
            ? "Maaf kak, untuk yang ini aku sambungkan ke admin ya 🙏 Ketik *admin* untuk terhubung langsung."
            : $res['text'];

        $this->memory->append($waId, 'user', $text);
        $this->memory->append($waId, 'assistant', $reply);
        return $reply;
    }
}
