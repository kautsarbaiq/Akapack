<?php

declare(strict_types=1);

namespace Akapack\Bot\Handler;

use Akapack\Bot\Tool\ToolExecutor;
use Akapack\Bot\Tool\ToolRunner;

/**
 * Handler Fase 2: Claude + tools Supabase (produk/stok/harga/kategori real-time).
 * Model memutuskan kapan memanggil tool; eksekusi query ada di ToolExecutor.
 */
final class ClaudeToolHandler implements Handler
{
    public function __construct(
        private readonly ToolRunner $llm,
        private readonly string $systemPrompt,
        private readonly array $tools,
        private readonly ToolExecutor $executor,
    ) {
    }

    public function handle(string $waId, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $res = $this->llm->run(
            $this->systemPrompt,
            [['role' => 'user', 'content' => $text]],
            $this->tools,
            $this->executor,
        );

        if ($res['refused'] || trim($res['text']) === '') {
            return "Maaf kak, untuk yang ini aku sambungkan ke admin ya 🙏 "
                . "Ketik *admin* untuk terhubung langsung dengan tim kami.";
        }

        return $res['text'];
    }
}
