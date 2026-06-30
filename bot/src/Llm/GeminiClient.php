<?php

declare(strict_types=1);

namespace Akapack\Bot\Llm;

use Akapack\Bot\Logger;
use Akapack\Bot\Tool\ToolExecutor;
use Akapack\Bot\Tool\ToolRunner;

/**
 * Implementasi LlmClient + ToolRunner memakai Google Gemini API (generateContent REST).
 * Dipasang di belakang seam yang sama dengan AnthropicClient, jadi handler/tools/
 * memori/eskalasi tidak berubah.
 *
 * Referensi: POST {BASE}/models/{model}:generateContent, header x-goog-api-key.
 */
final class GeminiClient implements LlmClient, ToolRunner
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';
    private const MAX_TOOL_ROUNDS = 6;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-2.5-flash',
        private readonly ?Logger $logger = null,
        private readonly int $maxOutputTokens = 2048,
        private readonly int $timeout = 30,
    ) {
    }

    public function reply(string $system, array $messages): array
    {
        $resp = $this->generate($system, self::toContents($messages), []);
        if ($this->isBlocked($resp)) {
            return ['text' => '', 'refused' => true];
        }
        $parts = $resp['candidates'][0]['content']['parts'] ?? [];
        return ['text' => $this->collectText($parts), 'refused' => false];
    }

    public function run(string $system, array $messages, array $tools, ToolExecutor $executor): array
    {
        $contents = self::toContents($messages);
        $decls = self::toFunctionDeclarations($tools);

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $resp = $this->generate($system, $contents, $decls);
            if ($this->isBlocked($resp)) {
                return ['text' => '', 'refused' => true];
            }

            $parts = $resp['candidates'][0]['content']['parts'] ?? [];
            $calls = array_values(array_filter($parts, static fn ($p) => isset($p['functionCall'])));

            if ($calls === []) {
                return ['text' => $this->collectText($parts), 'refused' => false];
            }

            // Turn model: pertahankan parts (termasuk thoughtSignature), tapi pastikan
            // functionCall.args = OBJECT — args kosong harus {} bukan [] (Gemini tolak list).
            $contents[] = ['role' => 'model', 'parts' => array_map([self::class, 'normalizeArgs'], $parts)];

            $responseParts = [];
            foreach ($calls as $c) {
                $name = (string) ($c['functionCall']['name'] ?? '');
                $args = (array) ($c['functionCall']['args'] ?? []);
                $output = $executor->execute($name, $args);
                $decoded = json_decode($output, true);
                // response juga harus OBJECT (Struct), bukan list/empty-array.
                $response = (is_array($decoded) && $decoded !== []) ? $decoded : ['result' => $output];
                $responseParts[] = [
                    'functionResponse' => ['name' => $name, 'response' => $response],
                ];
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        return ['text' => '', 'refused' => false];
    }

    /** @return array<int,array{role:string,parts:array}> */
    public static function toContents(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $out[] = ['role' => $role, 'parts' => [['text' => (string) ($m['content'] ?? '')]]];
        }
        return $out;
    }

    /** Konversi definisi tool (name/description/inputSchema) → functionDeclarations. */
    public static function toFunctionDeclarations(array $tools): array
    {
        $decls = [];
        foreach ($tools as $t) {
            $decl = ['name' => $t['name'], 'description' => (string) ($t['description'] ?? '')];
            $schema = $t['inputSchema'] ?? null;
            $props = is_array($schema) && is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            if ($props !== []) {
                $decl['parameters'] = $schema; // JSON Schema tipe lowercase diterima Gemini
            }
            $decls[] = $decl;
        }
        return $decls;
    }

    /** Pastikan functionCall.args berupa object {} (bukan list []) saat di-echo balik. */
    public static function normalizeArgs(array $part): array
    {
        if (isset($part['functionCall'])) {
            $args = $part['functionCall']['args'] ?? null;
            if (!is_array($args) || $args === []) {
                $part['functionCall']['args'] = new \stdClass();
            }
        }
        return $part;
    }

    private function isBlocked(array $resp): bool
    {
        if (!empty($resp['promptFeedback']['blockReason'])) {
            return true;
        }
        $fr = $resp['candidates'][0]['finishReason'] ?? '';
        return in_array($fr, ['SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true);
    }

    private function collectText(array $parts): string
    {
        $text = '';
        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $text .= $p['text'];
            }
        }
        return trim($text);
    }

    private function generate(string $system, array $contents, array $functionDeclarations): array
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $this->maxOutputTokens],
        ];
        if ($functionDeclarations !== []) {
            $body['tools'] = [['functionDeclarations' => $functionDeclarations]];
        }

        $url = self::BASE . '/models/' . rawurlencode($this->model) . ':generateContent';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            $this->logger?->error('Gemini curl gagal', ['err' => $err]);
            throw new \RuntimeException('Koneksi Gemini gagal');
        }
        if ($code >= 400) {
            $this->logger?->error('Gemini HTTP error', ['code' => $code, 'body' => mb_substr((string) $res, 0, 300)]);
            throw new \RuntimeException("Gemini error HTTP {$code}");
        }

        $data = json_decode((string) $res, true);
        return is_array($data) ? $data : [];
    }
}
