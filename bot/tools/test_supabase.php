<?php

declare(strict_types=1);

/**
 * Uji Fase 2 (tools Supabase). Bagian unit pakai DB palsu (tanpa jaringan);
 * bagian "LIVE" konek Supabase asli pakai anon key dari ~/Documents/akapack/.env.local.
 *
 *   php tools/test_supabase.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

use Akapack\Bot\Handler\ClaudeToolHandler;
use Akapack\Bot\Logger;
use Akapack\Bot\Supabase\Db;
use Akapack\Bot\Supabase\SupabaseClient;
use Akapack\Bot\Supabase\SupabaseTools;
use Akapack\Bot\Tool\ToolExecutor;
use Akapack\Bot\Tool\ToolRunner;

$base = dirname(__DIR__);
putenv('DATA_DIR=' . $base . '/var/test_supa');
require $base . '/bootstrap.php';

$TENANT = '00000000-0000-0000-0000-000000000001';
$BDG = '00000000-0000-0000-0000-000000000002';
$GRT = '00000000-0000-0000-0000-000000000003';
$fails = [];

/** DB palsu yang bisa diprogram per-tabel. */
$fakeDb = new class implements Db {
    public array $byTable = [];
    public array $lastQuery = [];
    public function select(string $table, array $query): array
    {
        $this->lastQuery[$table] = $query;
        return $this->byTable[$table] ?? [];
    }
};

$tools = new SupabaseTools($fakeDb, $TENANT, $BDG, $GRT);

echo "== cari_produk ==\n";
$fakeDb->byTable['products'] = [
    ['id' => 'p1', 'name' => 'Plastik PE 60x100', 'sku' => 'AKA-1', 'unit' => 'pack', 'category' => ['name' => 'Kemasan Flexi']],
];
$r = json_decode($tools->execute('cari_produk', ['nama' => 'plastik']), true);
report($fails, 'cari_produk shaping (id/nama/kategori)', ($r['produk'][0]['id'] ?? '') === 'p1' && ($r['produk'][0]['kategori'] ?? '') === 'Kemasan Flexi');
report($fails, 'cari_produk filter tenant', str_contains($fakeDb->lastQuery['products']['tenant_id'] ?? '', $TENANT));
report($fails, 'cari_produk pakai ilike', str_contains($fakeDb->lastQuery['products']['name'] ?? '', 'ilike.*plastik*'));
report($fails, 'cari_produk TIDAK select cost_price', !str_contains($fakeDb->lastQuery['products']['select'] ?? '', 'cost'));

echo "\n== cari_produk + kategori ==\n";
$fakeDb->byTable['categories'] = [['id' => 'cat1']];
$tools->execute('cari_produk', ['nama' => 'plastik', 'kategori' => 'flexi']);
report($fails, 'kategori → filter category_id in(...)', ($fakeDb->lastQuery['products']['category_id'] ?? '') === 'in.(cat1)');

echo "\n== cek_stok ==\n";
$fakeDb->byTable['inventory'] = [
    ['outlet_id' => $BDG, 'stock' => 5],
    ['outlet_id' => $GRT, 'stock' => 0],
];
$r = json_decode($tools->execute('cek_stok', ['product_id' => 'p1', 'cabang' => 'semua']), true);
report($fails, 'cek_stok label cabang', ($r['stok'][0]['cabang'] ?? '') === 'Bandung' && ($r['stok'][1]['cabang'] ?? '') === 'Garut');
$tools->execute('cek_stok', ['product_id' => 'p1', 'cabang' => 'garut']);
report($fails, 'cek_stok cabang=garut → filter outlet', ($fakeDb->lastQuery['inventory']['outlet_id'] ?? '') === 'eq.' . $GRT);

echo "\n== cek_harga (tier logic) ==\n";
$fakeDb->byTable['products'] = [['name' => 'X', 'unit' => 'pcs', 'units' => [], 'price' => 1000, 'price_market' => 1000, 'price_online' => 1000, 'price_tiers' => []]];
$r = json_decode($tools->execute('cek_harga', ['product_id' => 'p1', 'qty' => 10]), true);
report($fails, 'tanpa tier → harga base, butuh_admin=false', $r['harga_satuan'] === 1000 && $r['butuh_admin'] === false && $r['subtotal'] === 10000);
report($fails, 'cek_harga TIDAK select cost_price', !str_contains($fakeDb->lastQuery['products']['select'] ?? '', 'cost'));

$fakeDb->byTable['products'] = [['name' => 'X', 'unit' => 'pcs', 'units' => [], 'price' => 1000, 'price_market' => 1000, 'price_online' => 1000, 'price_tiers' => [['min_qty' => 12, 'price' => 900], ['min_qty' => 50, 'price' => 800]]]];
$r = json_decode($tools->execute('cek_harga', ['product_id' => 'p1', 'qty' => 20]), true);
report($fails, 'qty 20 → tier min_qty 12 (Rp900), butuh_admin=false', $r['harga_satuan'] === 900 && $r['butuh_admin'] === false);
$r = json_decode($tools->execute('cek_harga', ['product_id' => 'p1', 'qty' => 60]), true);
report($fails, 'qty 60 (di atas tier tertinggi 50) → butuh_admin=true', $r['harga_satuan'] === 800 && $r['butuh_admin'] === true);
$r = json_decode($tools->execute('cek_harga', ['product_id' => 'p1', 'qty' => 5]), true);
report($fails, 'qty 5 (di bawah tier) → harga base 1000', $r['harga_satuan'] === 1000);

echo "\n== daftar_kategori ==\n";
$fakeDb->byTable['categories'] = [['name' => 'MESIN'], ['name' => 'Dus']];
$r = json_decode($tools->execute('daftar_kategori', []), true);
report($fails, 'daftar_kategori → list nama', $r['kategori'] === ['MESIN', 'Dus']);

echo "\n== Guardrail cost_price (offline) ==\n";
$realDb = new SupabaseClient('https://example.invalid', 'k', new Logger($base . '/var/test_supa/g.log'));
$threw = false;
try {
    $realDb->select('products', ['select' => 'id,cost_price']);
} catch (\RuntimeException $e) {
    $threw = true;
}
report($fails, 'select cost_price → ditolak guardrail (sebelum jaringan)', $threw);

echo "\n== ClaudeToolHandler (fake runner) ==\n";
$fakeRunner = new class implements ToolRunner {
    public array $next = ['text' => '', 'refused' => false];
    public int $toolCount = 0;
    public function run(string $system, array $messages, array $tools, ToolExecutor $executor): array
    {
        $this->toolCount = count($tools);
        return $this->next;
    }
};
$h = new ClaudeToolHandler($fakeRunner, 'SYS', SupabaseTools::definitions(), $tools);
$fakeRunner->next = ['text' => 'Stok Plastik PE di Bandung 5 pack kak 👍', 'refused' => false];
report($fails, 'handler passthrough', $h->handle('628', 'stok plastik?') === 'Stok Plastik PE di Bandung 5 pack kak 👍');
report($fails, '4 tool diteruskan', $fakeRunner->toolCount === 4);
$fakeRunner->next = ['text' => '', 'refused' => true];
report($fails, 'refusal → fallback admin', str_contains($h->handle('628', 'x') ?? '', 'admin'));
report($fails, 'input kosong → null', $h->handle('628', '  ') === null);

// ---------- LIVE ----------
[$liveUrl, $liveKey] = loadEnvLocal();
if ($liveUrl === '' || $liveKey === '') {
    echo "\n== LIVE: dilewati (kredensial Supabase tidak ditemukan) ==\n";
} else {
    echo "\n== LIVE Supabase ==\n";
    $db = new SupabaseClient($liveUrl, $liveKey, new Logger($base . '/var/test_supa/live.log'));
    $live = new SupabaseTools($db, $TENANT, $BDG, $GRT);

    $kat = json_decode($live->execute('daftar_kategori', []), true);
    report($fails, 'LIVE daftar_kategori non-kosong', ($kat['jumlah'] ?? 0) > 5);

    $cari = json_decode($live->execute('cari_produk', ['nama' => 'plastik']), true);
    $pid = $cari['produk'][0]['id'] ?? '';
    report($fails, 'LIVE cari_produk("plastik") dapat hasil', $pid !== '');
    echo "    contoh: " . ($cari['produk'][0]['nama'] ?? '-') . " [" . ($cari['produk'][0]['kategori'] ?? '-') . "]\n";

    if ($pid !== '') {
        $stok = json_decode($live->execute('cek_stok', ['product_id' => $pid, 'cabang' => 'semua']), true);
        report($fails, 'LIVE cek_stok mengembalikan stok', isset($stok['stok']) && count($stok['stok']) >= 1);
        $harga = json_decode($live->execute('cek_harga', ['product_id' => $pid, 'qty' => 10]), true);
        report($fails, 'LIVE cek_harga ada harga_satuan & tanpa error', !isset($harga['error']) && isset($harga['harga_satuan']));
        echo "    harga_satuan: Rp" . number_format((float) ($harga['harga_satuan'] ?? 0), 0, ',', '.') . "\n";
    }

    // GUARDRAIL paling penting: select=* TIDAK boleh membocorkan cost_price.
    $raw = $db->select('products', ['tenant_id' => 'eq.' . $TENANT, 'select' => '*', 'limit' => 1]);
    $hasCost = $raw !== [] && array_key_exists('cost_price', $raw[0]);
    report($fails, 'LIVE select=* → cost_price ter-scrub (tidak bocor)', !$hasCost);
}

rrmdir($base . '/var/test_supa');

echo "\n========================================\n";
if ($fails === []) {
    echo "✅ SEMUA LULUS — Fase 2 (tools Supabase + guardrail cost_price) bekerja.\n";
    exit(0);
}
echo '❌ GAGAL: ' . count($fails) . " assertion.\n";
foreach ($fails as $f) {
    echo "  - {$f}\n";
}
exit(1);

// ---------- helpers ----------

function loadEnvLocal(): array
{
    $path = getenv('HOME') . '/Documents/akapack/.env.local';
    if (!is_file($path)) {
        return ['', ''];
    }
    $url = '';
    $key = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with($line, 'NEXT_PUBLIC_SUPABASE_URL=')) {
            $url = rtrim(substr($line, strlen('NEXT_PUBLIC_SUPABASE_URL=')), '/');
        } elseif (str_starts_with($line, 'NEXT_PUBLIC_SUPABASE_ANON_KEY=')) {
            $key = substr($line, strlen('NEXT_PUBLIC_SUPABASE_ANON_KEY='));
        }
    }
    return [trim($url), trim($key)];
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
