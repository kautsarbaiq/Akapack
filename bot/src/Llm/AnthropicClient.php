<?php

declare(strict_types=1);

namespace Akapack\Bot\Llm;

use Akapack\Bot\Tool\ToolExecutor;
use Akapack\Bot\Tool\ToolRunner;
use Anthropic\Client;

/**
 * Implementasi LlmClient + ToolRunner memakai SDK resmi Anthropic (anthropic-ai/sdk).
 *
 * Sesuai aturan model ini:
 *  - thinking: ['type' => 'adaptive']
 *  - output_config: ['effort' => 'medium']
 *  - TANPA temperature/top_p/top_k/budget_tokens (akan error 400)
 *  - prompt caching pada system prompt (cacheControl ephemeral; tool ikut ter-cache)
 *  - cek stop_reason === 'refusal' SEBELUM membaca content
 */
final class AnthropicClient implements LlmClient, ToolRunner
{
    /** Batas putaran tool-use agar tidak loop tak terbatas. */
    private const MAX_TOOL_ROUNDS = 6;

    private readonly Client $client;

    public function __construct(
        string $apiKey,
        private readonly string $model = 'claude-sonnet-4-6',
        private readonly int $maxTokens = 1024,
    ) {
        $this->client = new Client(apiKey: $apiKey);
    }

    public function reply(string $system, array $messages): array
    {
        $message = $this->client->messages->create(
            model: $this->model,
            maxTokens: $this->maxTokens,
            system: [
                ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            thinking: ['type' => 'adaptive'],
            outputConfig: ['effort' => 'medium'],
            messages: $messages,
        );

        // Guardrail: classifier bisa menolak — JANGAN baca content sebelum cek ini.
        if ($message->stopReason === 'refusal') {
            return ['text' => '', 'refused' => true];
        }

        return ['text' => $this->collectText($message->content), 'refused' => false];
    }

    /**
     * Loop tool-use (Fase 2): panggil model, jalankan tool yang diminta, umpan
     * balik hasilnya, ulangi sampai end_turn / batas putaran.
     */
    public function run(string $system, array $messages, array $tools, ToolExecutor $executor): array
    {
        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $message = $this->client->messages->create(
                model: $this->model,
                maxTokens: $this->maxTokens,
                system: [
                    ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
                ],
                thinking: ['type' => 'adaptive'],
                outputConfig: ['effort' => 'medium'],
                tools: $tools,
                messages: $messages,
            );

            if ($message->stopReason === 'refusal') {
                return ['text' => '', 'refused' => true];
            }

            if ($message->stopReason !== 'tool_use') {
                return ['text' => $this->collectText($message->content), 'refused' => false];
            }

            // Jalankan semua tool yang diminta, kumpulkan hasil.
            $toolResults = [];
            foreach ($message->content as $block) {
                if (($block->type ?? '') === 'tool_use') {
                    $output = $executor->execute((string) $block->name, (array) $block->input);
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $block->id,
                        'content' => $output,
                    ];
                }
            }

            $messages[] = ['role' => 'assistant', 'content' => $message->content];
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        // Putaran tool habis tanpa jawaban final.
        return ['text' => '', 'refused' => false];
    }

    private function collectText(iterable $content): string
    {
        $text = '';
        foreach ($content as $block) {
            if (($block->type ?? '') === 'text') {
                $text .= $block->text;
            }
        }
        return trim($text);
    }
}
