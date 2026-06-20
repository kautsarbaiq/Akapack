<?php

declare(strict_types=1);

namespace Akapack\Bot\Llm;

use Anthropic\Client;

/**
 * Implementasi LlmClient memakai SDK resmi Anthropic (anthropic-ai/sdk).
 *
 * Sesuai aturan model ini:
 *  - thinking: ['type' => 'adaptive']
 *  - output_config: ['effort' => 'medium']
 *  - TANPA temperature/top_p/top_k/budget_tokens (akan error 400)
 *  - prompt caching pada system prompt (cacheControl ephemeral)
 *  - cek stop_reason === 'refusal' SEBELUM membaca content
 */
final class AnthropicClient implements LlmClient
{
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

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return ['text' => trim($text), 'refused' => false];
    }
}
