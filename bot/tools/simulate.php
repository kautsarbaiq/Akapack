<?php

declare(strict_types=1);

/**
 * Simulator webhook lokal + uji integrasi pipeline async (tanpa kredensial WA).
 * Membuktikan: enqueue -> debounce (gabung) -> dedup idempotent -> router ->
 * eskalasi -> kirim, PLUS keandalan: retry saat kirim gagal, mode HUMAN diam,
 * dan keamanan tanda tangan (fail-closed + valid/invalid signature).
 *
 *   php tools/simulate.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$base = dirname(__DIR__);

// Konfigurasi mode-uji utama SEBELUM Bot pertama dibangun.
applyEnv([
    'QUEUE_DRIVER' => 'file',
    'WA_SEND_DRIVER' => 'log',
    'DATA_DIR' => $base . '/var/test',
    'DEBOUNCE_SECONDS' => '4',
    'WA_APP_SECRET' => '',
    'WA_ALLOW_INSECURE_WEBHOOK' => '1',
    'WA_VERIFY_TOKEN' => 'test-token',
    'ADMIN_WA_BANDUNG' => '628000000001',
    'RETRY_BACKOFF_SECONDS' => '30',
    'MAX_RETRY_AGE_SECONDS' => '600',
]);
rrmdir($base . '/var/test');
rrmdir($base . '/var/test_sig');

/** @var \Akapack\Bot\Bot $bot */
$bot = require $base . '/bootstrap.php';
$receiver = $bot->receiver();
$worker = $bot->worker();

$U1 = '628111111111';
$U2 = '628222222222';
$U3 = '628333333333';
$U5 = '628555555555';
$U6 = '628666666666';
$ADMIN = '628000000001';

$fails = [];
$outFile = $base . '/var/test/outgoing.log';

echo "== 1) Enqueue + debounce + dedup + eskalasi ==\n";
post($receiver, payload($U1, 'm1', 'halo bos'));
post($receiver, payload($U1, 'm2', 'ada plastik PE 60x100 ga?'));
post($receiver, payload($U1, 'm1', 'halo bos'));   // duplikat id -> diabaikan
post($receiver, payload($U2, 'm3', 'menu'));
post($receiver, payload($U3, 'm4', 'cs'));         // eskalasi langsung

report($fails, 'Debounce menahan pesan (runOnce normal = 0)', $worker->runOnce(false) === 0);
report($fails, 'Drain memproses 3 pengirim (U1,U2,U3)', $worker->runOnce(true) === 3);

$byTo = groupByTo($outFile);
report($fails, 'U1 1 balasan (gabung + dedup id)', count($byTo[$U1] ?? []) === 1, 'n=' . count($byTo[$U1] ?? []));
report($fails, 'Balasan U1 memuat pesan ke-1', str_contains($byTo[$U1][0] ?? '', 'halo bos'));
report($fails, 'Balasan U1 memuat pesan ke-2', str_contains($byTo[$U1][0] ?? '', 'plastik PE 60x100'));
report($fails, 'U2 1 balasan echo "menu"', count($byTo[$U2] ?? []) === 1 && str_contains($byTo[$U2][0] ?? '', 'menu'));
report($fails, 'U3 balasan eskalasi', str_contains($byTo[$U3][0] ?? '', 'sambungkan ke admin'));
report($fails, 'Admin dapat notifikasi handoff', str_contains($byTo[$ADMIN][0] ?? '', 'Handoff'));
report($fails, 'Mode U3 = HUMAN', $bot->store->getMode($U3) === 'HUMAN');

echo "\n== 2) Idempotent: pesan yang SUDAH dibalas dikirim ulang oleh Meta ==\n";
post($receiver, payload($U1, 'm2', 'ada plastik PE 60x100 ga?')); // id sudah dibalas
$worker->runOnce(true);
report($fails, 'Tidak ada balasan ganda ke U1', count(groupByTo($outFile)[$U1] ?? []) === 1);

echo "\n== 3) Mode HUMAN: bot diam ==\n";
post($receiver, payload($U3, 'm9', 'halo masih disitu?'));
$worker->runOnce(true);
report($fails, 'Bot diam saat HUMAN (U3 tetap 1 balasan)', count(groupByTo($outFile)[$U3] ?? []) === 1);

echo "\n== 4) Eskalasi per-fragmen: 'halo' lalu 'cs' dalam jendela debounce ==\n";
post($receiver, payload($U5, 'm10', 'halo'));
post($receiver, payload($U5, 'm11', 'cs'));
$worker->runOnce(true);
$byTo = groupByTo($outFile);
report($fails, 'U5 tereskalasi walau "cs" digabung dgn "halo"', str_contains($byTo[$U5][0] ?? '', 'sambungkan ke admin'));
report($fails, 'Mode U5 = HUMAN', $bot->store->getMode($U5) === 'HUMAN');

echo "\n== 5) Retry: kirim gagal -> buffer ditahan -> sukses di percobaan berikut ==\n";
$failBot = newBot($base, ['WA_SEND_DRIVER' => 'fail']);
post($failBot->receiver(), payload($U6, 'm12', 'test retry'));
$failBot->worker()->runOnce(true);
report($fails, 'Saat gagal: belum ada balasan ke U6', count(groupByTo($outFile)[$U6] ?? []) === 0);
report($fails, 'Saat gagal: buffer U6 dipertahankan', $failBot->store->peekBuffer($U6) !== []);

$okBot = newBot($base, ['WA_SEND_DRIVER' => 'log']);
$okBot->worker()->runOnce(true);
report($fails, 'Setelah retry sukses: U6 dapat 1 balasan', count(groupByTo($outFile)[$U6] ?? []) === 1);
report($fails, 'Setelah retry sukses: buffer U6 kosong', $okBot->store->peekBuffer($U6) === []);

echo "\n== 6) Keamanan tanda tangan webhook ==\n";
$secret = 'app-secret-xyz';
$secureBot = newBot($base, [
    'DATA_DIR' => $base . '/var/test_sig',
    'WA_APP_SECRET' => $secret,
    'WA_ALLOW_INSECURE_WEBHOOK' => '',
]);
$rec = $secureBot->receiver();
$body = payload('628999', 'ms1', 'hai');
$goodSig = 'sha256=' . hash_hmac('sha256', $body, $secret);
report($fails, 'Tanpa tanda tangan -> 403', $rec->handlePost($body, null)->code === 403);
report($fails, 'Tanda tangan salah -> 403', $rec->handlePost($body, 'sha256=deadbeef')->code === 403);
report($fails, 'Tanda tangan valid -> 200', $rec->handlePost($body, $goodSig)->code === 200);

$closedBot = newBot($base, [
    'DATA_DIR' => $base . '/var/test_sig',
    'WA_APP_SECRET' => '',
    'WA_ALLOW_INSECURE_WEBHOOK' => '',
]);
report($fails, 'Secret kosong + insecure OFF -> 403 (fail-closed)', $closedBot->receiver()->handlePost($body, null)->code === 403);

echo "\n========================================\n";
if ($fails === []) {
    echo "✅ SEMUA LULUS — pipeline async Fase 0 (at-least-once) bekerja.\n";
    exit(0);
}
echo '❌ GAGAL: ' . count($fails) . " assertion.\n";
foreach ($fails as $f) {
    echo "  - {$f}\n";
}
exit(1);

// ---------- helpers ----------

function applyEnv(array $env): void
{
    foreach ($env as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
}

function newBot(string $base, array $overrides): \Akapack\Bot\Bot
{
    $defaults = [
        'QUEUE_DRIVER' => 'file',
        'WA_SEND_DRIVER' => 'log',
        'DATA_DIR' => $base . '/var/test',
        'DEBOUNCE_SECONDS' => '4',
        'WA_APP_SECRET' => '',
        'WA_ALLOW_INSECURE_WEBHOOK' => '1',
        'WA_VERIFY_TOKEN' => 'test-token',
        'ADMIN_WA_BANDUNG' => '628000000001',
        'RETRY_BACKOFF_SECONDS' => '30',
        'MAX_RETRY_AGE_SECONDS' => '600',
    ];
    applyEnv(array_merge($defaults, $overrides));
    return new \Akapack\Bot\Bot($base);
}

function payload(string $from, string $id, string $body): string
{
    return json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['wa_id' => $from]],
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

function post(\Akapack\Bot\Receiver $receiver, string $raw): void
{
    $res = $receiver->handlePost($raw, null);
    echo "  POST -> {$res->code} {$res->body}\n";
}

function groupByTo(string $file): array
{
    $byTo = [];
    if (!is_file($file)) {
        return $byTo;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $d = json_decode($line, true);
        if (is_array($d) && isset($d['to'])) {
            $byTo[$d['to']][] = (string) ($d['text'] ?? '');
        }
    }
    return $byTo;
}

function report(array &$fails, string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok ? '' : "  [{$detail}]") . "\n";
    if (!$ok) {
        $fails[] = $name . ($detail !== '' ? " ({$detail})" : '');
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
