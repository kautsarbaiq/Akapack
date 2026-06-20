<?php

declare(strict_types=1);

/**
 * Uji Fase 1 (ClaudeHandler) memakai LlmClient palsu — tanpa kredensial/jaringan.
 * Membuktikan: passthrough balasan, fallback saat refusal/empty, input kosong,
 * dan integrasi penuh via Worker (enqueue -> drain -> kirim balasan Claude).
 *
 *   php tools/test_claude.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

use Akapack\Bot\Handler\ClaudeHandler;
use Akapack\Bot\Llm\LlmClient;
use Akapack\Bot\SystemPrompt;
use Akapack\Bot\Worker;

$base = dirname(__DIR__);

putenv('QUEUE_DRIVER=file');
putenv('WA_SEND_DRIVER=log');
putenv('DATA_DIR=' . $base . '/var/test_claude');
putenv('DEBOUNCE_SECONDS=4');
putenv('WA_APP_SECRET=');
putenv('WA_ALLOW_INSECURE_WEBHOOK=1');
rrmdir($base . '/var/test_claude');

/** @var \Akapack\Bot\Bot $bot */
$bot = require $base . '/bootstrap.php';

/** LlmClient palsu yang bisa diprogram. */
$fake = new class implements LlmClient {
    public array $lastMessages = [];
    public string $lastSystem = '';
    public array $next = ['text' => '', 'refused' => false];

    public function reply(string $system, array $messages): array
    {
        $this->lastSystem = $system;
        $this->lastMessages = $messages;
        return $this->next;
    }
};

$fails = [];

echo "== System prompt ==\n";
$sys = SystemPrompt::faq('Mbak Aka', 'Akapack');
report($fails, 'System prompt memuat persona', str_contains($sys, 'Mbak Aka'));
report($fails, 'System prompt memuat guardrail harga', str_contains(strtolower($sys), 'jangan menjanjikan harga'));

echo "\n== ClaudeHandler (unit) ==\n";
$handler = new ClaudeHandler($fake, $sys);

$fake->next = ['text' => 'Halo kak! Kami jual aneka kemasan plastik 😊', 'refused' => false];
$out = $handler->handle('628111', 'jual plastik apa aja?');
report($fails, 'Passthrough balasan Claude', $out === 'Halo kak! Kami jual aneka kemasan plastik 😊');
report($fails, 'System prompt diteruskan ke LLM', $fake->lastSystem === $sys);
report($fails, 'Teks user diteruskan sebagai role=user', ($fake->lastMessages[0]['content'] ?? '') === 'jual plastik apa aja?');

$fake->next = ['text' => '', 'refused' => true];
$out = $handler->handle('628111', 'sesuatu yang ditolak');
report($fails, 'Refusal -> fallback arahkan admin', str_contains($out ?? '', 'admin'));

$fake->next = ['text' => '   ', 'refused' => false];
$out = $handler->handle('628111', 'halo');
report($fails, 'Balasan kosong -> fallback arahkan admin', str_contains($out ?? '', 'admin'));

report($fails, 'Input kosong -> null (tidak panggil LLM)', $handler->handle('628111', '   ') === null);

echo "\n== Integrasi via Worker ==\n";
$fake->next = ['text' => 'Cabang Bandung di Jl. Ibrahim Adjie, Kiaracondong kak 📍', 'refused' => false];
$worker = new Worker($bot->config, $bot->store, $bot->whatsapp, new ClaudeHandler($fake, $sys), $bot->logger);

$bot->receiver()->handlePost(payload('628999', 'c1', 'alamat cabang bandung dimana?'), null);
$n = $worker->runOnce(true);
report($fails, 'Worker memproses 1 pengirim', $n === 1);

$out = readOutgoing($base . '/var/test_claude/outgoing.log');
$toUser = array_filter($out, fn ($r) => $r['to'] === '628999');
$first = array_values($toUser)[0]['text'] ?? '';
report($fails, 'Balasan Claude terkirim ke user', str_contains($first, 'Ibrahim Adjie'));

rrmdir($base . '/var/test_claude');

echo "\n========================================\n";
if ($fails === []) {
    echo "✅ SEMUA LULUS — Fase 1 (ClaudeHandler) terpasang & teruji (fake LLM).\n";
    exit(0);
}
echo '❌ GAGAL: ' . count($fails) . " assertion.\n";
foreach ($fails as $f) {
    echo "  - {$f}\n";
}
exit(1);

// ---------- helpers ----------

function payload(string $from, string $id, string $body): string
{
    return json_encode([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => $from,
                        'id' => $id,
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ]],
                ],
            ]],
        ]],
    ], JSON_UNESCAPED_UNICODE);
}

function readOutgoing(string $file): array
{
    $rows = [];
    if (!is_file($file)) {
        return $rows;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $d = json_decode($line, true);
        if (is_array($d)) {
            $rows[] = $d;
        }
    }
    return $rows;
}

function report(array &$fails, string $name, bool $ok): void
{
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    if (!$ok) {
        $fails[] = $name;
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}
