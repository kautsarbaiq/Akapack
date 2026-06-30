<?php

declare(strict_types=1);

/**
 * Uji offline GeminiClient (pembentukan request) — tanpa jaringan/kuota.
 * Menjaga perbaikan bug: functionCall.args kosong HARUS jadi {} bukan [] (Gemini
 * menolak list pada proto Struct).
 *
 *   php tools/test_gemini.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

use Akapack\Bot\Llm\GeminiClient;
use Akapack\Bot\Supabase\SupabaseTools;
use Akapack\Bot\Tool\RequestTools;

require dirname(__DIR__) . '/bootstrap.php';
$fails = [];

echo "== toContents (role mapping) ==\n";
$c = GeminiClient::toContents([
    ['role' => 'user', 'content' => 'halo'],
    ['role' => 'assistant', 'content' => 'hai'],
]);
report($fails, 'user→user, assistant→model', ($c[0]['role'] ?? '') === 'user' && ($c[1]['role'] ?? '') === 'model');
report($fails, 'text masuk parts', ($c[0]['parts'][0]['text'] ?? '') === 'halo');

echo "\n== toFunctionDeclarations ==\n";
$decls = GeminiClient::toFunctionDeclarations(array_merge(SupabaseTools::definitions(), [RequestTools::escalationDefinition()]));
report($fails, '5 functionDeclarations', count($decls) === 5);
$byName = [];
foreach ($decls as $d) {
    $byName[$d['name']] = $d;
}
report($fails, 'cari_produk punya parameters object', ($byName['cari_produk']['parameters']['type'] ?? '') === 'object');
report($fails, 'daftar_kategori TANPA parameters (no-arg)', !isset($byName['daftar_kategori']['parameters']));

echo "\n== normalizeArgs (regресi bug args kosong) ==\n";
// functionCall tanpa args → harus serialize {} bukan []
$part = GeminiClient::normalizeArgs(['functionCall' => ['name' => 'daftar_kategori', 'args' => []]]);
$json = json_encode(['parts' => [$part]]);
report($fails, 'args kosong → {} di JSON', str_contains($json, '"args":{}'));
report($fails, 'args kosong BUKAN []', !str_contains($json, '"args":[]'));
// args berisi → tetap object apa adanya
$part2 = GeminiClient::normalizeArgs(['functionCall' => ['name' => 'cek_stok', 'args' => ['cabang' => 'bandung']]]);
report($fails, 'args berisi dipertahankan', ($part2['functionCall']['args']['cabang'] ?? '') === 'bandung');
// thoughtSignature dipertahankan
$part3 = GeminiClient::normalizeArgs(['functionCall' => ['name' => 'x', 'args' => []], 'thoughtSignature' => 'abc']);
report($fails, 'thoughtSignature dipertahankan', ($part3['thoughtSignature'] ?? '') === 'abc');

echo "\n========================================\n";
if ($fails === []) {
    echo "✅ SEMUA LULUS — GeminiClient request builder benar.\n";
    exit(0);
}
echo '❌ GAGAL: ' . count($fails) . " assertion.\n";
foreach ($fails as $f) {
    echo "  - {$f}\n";
}
exit(1);

function report(array &$fails, string $name, bool $ok): void
{
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    if (!$ok) {
        $fails[] = $name;
    }
}
