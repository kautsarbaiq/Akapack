<?php

declare(strict_types=1);

/**
 * Uji Fase 3 (memori percakapan + eskalasi admin) tanpa kredensial/jaringan.
 * Memverifikasi: round-trip memori, riwayat diumpan ke LLM, eskalasi per-cabang
 * (intent+ringkasan+riwayat ke admin yang tepat), dan tool eskalasi_ke_admin.
 *
 *   php tools/test_memory.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

use Akapack\Bot\Escalator;
use Akapack\Bot\Handler\ClaudeToolHandler;
use Akapack\Bot\Memory\ConversationMemory;
use Akapack\Bot\Supabase\Db;
use Akapack\Bot\Supabase\SupabaseTools;
use Akapack\Bot\Tool\RequestTools;
use Akapack\Bot\Tool\ToolExecutor;
use Akapack\Bot\Tool\ToolRunner;

$base = dirname(__DIR__);
$BDG_ADMIN = '628111000001';
$GRT_ADMIN = '628222000002';

putenv('QUEUE_DRIVER=file');
putenv('WA_SEND_DRIVER=log');
putenv('DATA_DIR=' . $base . '/var/test_mem');
putenv('WA_APP_SECRET=');
putenv('WA_ALLOW_INSECURE_WEBHOOK=1');
putenv('ADMIN_WA_BANDUNG=' . $BDG_ADMIN);
putenv('ADMIN_WA_GARUT=' . $GRT_ADMIN);
rrmdir($base . '/var/test_mem');

/** @var \Akapack\Bot\Bot $bot */
$bot = require $base . '/bootstrap.php';
$outFile = $base . '/var/test_mem/outgoing.log';
$fails = [];

/** Memori palsu (in-memory). */
$mkMem = static fn (): ConversationMemory => new class implements ConversationMemory {
    public array $store = [];
    public function recent(string $waId, int $limit): array
    {
        return array_slice($this->store[$waId] ?? [], -$limit);
    }
    public function append(string $waId, string $role, string $content): void
    {
        $this->store[$waId][] = ['role' => $role, 'content' => $content];
    }
};

/** ToolRunner palsu (merekam input). */
$runner = new class implements ToolRunner {
    public array $lastMessages = [];
    public int $lastToolCount = 0;
    public array $next = ['text' => '', 'refused' => false];
    public function run(string $system, array $messages, array $tools, ToolExecutor $executor): array
    {
        $this->lastMessages = $messages;
        $this->lastToolCount = count($tools);
        return $this->next;
    }
};

/** DB palsu untuk SupabaseTools (delegasi non-eskalasi). */
$fakeDb = new class implements Db {
    public array $byTable = ['categories' => [['name' => 'MESIN']]];
    public function select(string $table, array $query): array
    {
        return $this->byTable[$table] ?? [];
    }
};
$supa = new SupabaseTools($fakeDb, $bot->config->tenantId, $bot->config->outletBandung, $bot->config->outletGarut);

echo "== Memori round-trip ==\n";
$m = $mkMem();
$m->append('u0', 'user', 'halo');
$m->append('u0', 'assistant', 'hai kak');
$r = $m->recent('u0', 10);
report($fails, 'recent urut lama→baru', $r === [['role' => 'user', 'content' => 'halo'], ['role' => 'assistant', 'content' => 'hai kak']]);

echo "\n== ClaudeToolHandler + riwayat ==\n";
$escMem = $mkMem();
$escalator = new Escalator($bot->config, $bot->store, $bot->whatsapp, $bot->logger, $escMem);

$handlerMem = $mkMem();
$handlerMem->append('u1', 'user', 'sebelumnya nanya plastik');
$handlerMem->append('u1', 'assistant', 'oke kak');
$handler = new ClaudeToolHandler($runner, 'SYS', $supa, $escalator, $handlerMem);

$runner->next = ['text' => 'Siap kak, ada kok 👍', 'refused' => false];
$out = $handler->handle('u1', 'ada stok ga?');
report($fails, 'handler passthrough', $out === 'Siap kak, ada kok 👍');
report($fails, 'riwayat + pesan baru diumpan (3 turn)', count($runner->lastMessages) === 3);
report($fails, 'turn pertama = riwayat lama', ($runner->lastMessages[0]['content'] ?? '') === 'sebelumnya nanya plastik');
report($fails, 'turn terakhir = pesan baru', ($runner->lastMessages[2]['content'] ?? '') === 'ada stok ga?');
report($fails, '5 tool diteruskan (4 supabase + eskalasi)', $runner->lastToolCount === 5);
$last2 = array_slice($handlerMem->store['u1'], -2);
report($fails, 'memori menyimpan user+assistant baru', $last2 === [['role' => 'user', 'content' => 'ada stok ga?'], ['role' => 'assistant', 'content' => 'Siap kak, ada kok 👍']]);

$runner->next = ['text' => '', 'refused' => true];
$out = $handler->handle('u1', 'sesuatu');
report($fails, 'refusal → fallback admin', str_contains($out ?? '', 'admin'));

echo "\n== Escalator per-cabang ==\n";
$escMem->append('e1', 'user', 'mau beli plastik banyak');
$escMem->append('e1', 'assistant', 'boleh kak');
$ok = $escalator->escalate('e1', 'nego_harga', 'mau nego 1000 dus', 'bandung', ackUser: false);
$byTo = groupByTo($outFile);
report($fails, 'eskalasi bandung berhasil', $ok === true);
report($fails, 'admin Bandung dapat notice', isset($byTo[$BDG_ADMIN]) && str_contains($byTo[$BDG_ADMIN][0], 'Handoff'));
report($fails, 'notice memuat intent + ringkasan', str_contains($byTo[$BDG_ADMIN][0], 'nego_harga') && str_contains($byTo[$BDG_ADMIN][0], 'mau nego 1000 dus'));
report($fails, 'notice memuat riwayat', str_contains($byTo[$BDG_ADMIN][0], 'Riwayat'));
report($fails, 'admin Garut TIDAK dapat (routing cabang)', !isset($byTo[$GRT_ADMIN]));
report($fails, 'ackUser=false → user TIDAK dibalas', !isset($byTo['e1']));
report($fails, 'mode e1 = HUMAN', $bot->store->getMode('e1') === 'HUMAN');

$escalator->escalate('e2', 'komplain', 'barang rusak', null, ackUser: true);
$byTo = groupByTo($outFile);
report($fails, 'cabang null → kedua admin dapat', isset($byTo[$BDG_ADMIN][1]) && isset($byTo[$GRT_ADMIN]));
report($fails, 'ackUser=true → user dibalas', isset($byTo['e2']) && str_contains($byTo['e2'][0], 'sambungkan ke admin'));

echo "\n== Tool eskalasi_ke_admin (RequestTools) ==\n";
$rt = new RequestTools('r1', $supa, $escalator);
$res = json_decode($rt->execute('eskalasi_ke_admin', ['alasan' => 'order_partai', 'ringkasan' => '500 dus', 'cabang' => 'garut']), true);
report($fails, 'tool eskalasi → status escalated', ($res['status'] ?? '') === 'escalated' && ($res['mode'] ?? '') === 'HUMAN');
report($fails, 'tool eskalasi set mode HUMAN', $bot->store->getMode('r1') === 'HUMAN');
$byTo = groupByTo($outFile);
report($fails, 'tool eskalasi → admin Garut dapat (bukan Bandung baru)', count($byTo[$GRT_ADMIN] ?? []) === 2);

$res2 = json_decode($rt->execute('daftar_kategori', []), true);
report($fails, 'tool non-eskalasi → delegasi ke SupabaseTools', ($res2['kategori'] ?? []) === ['MESIN']);

rrmdir($base . '/var/test_mem');

echo "\n========================================\n";
if ($fails === []) {
    echo "✅ SEMUA LULUS — Fase 3 (memori + eskalasi admin per-cabang) bekerja.\n";
    exit(0);
}
echo '❌ GAGAL: ' . count($fails) . " assertion.\n";
foreach ($fails as $f) {
    echo "  - {$f}\n";
}
exit(1);

// ---------- helpers ----------

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
