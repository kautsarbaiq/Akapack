<?php

declare(strict_types=1);

/**
 * Chat lokal dengan "Mbak Aka" di terminal — untuk menguji otak bot (Gemini/Claude
 * + tools Supabase + memori) TANPA WhatsApp / VPS.
 *
 * Cara pakai:
 *   1) isi bot/.env minimal: LLM_PROVIDER, GEMINI_API_KEY (atau ANTHROPIC_API_KEY),
 *      dan SUPABASE_URL + SUPABASE_ANON_KEY untuk cek produk/stok/harga.
 *   2) jalankan:  php tools/chat.php
 *   3) ketik pesan seperti pelanggan; ketik "exit" untuk keluar.
 *
 * Catatan: kirim ke admin dipaksa ke driver "log" (var/outgoing.log), bukan WA asli.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

putenv('WA_SEND_DRIVER=log'); // aman: notifikasi admin saat eskalasi tidak dikirim ke WA asli

/** @var \Akapack\Bot\Bot $bot */
$bot = require dirname(__DIR__) . '/bootstrap.php';

$handler = (new ReflectionObject($bot->handler))->getShortName();
fwrite(STDOUT, "=== Chat lokal Mbak Aka ===\n");
fwrite(STDOUT, "Handler aktif : {$handler}\n");
if ($handler === 'EchoHandler') {
    fwrite(STDOUT, "⚠️  Belum ada API key LLM — bot hanya echo. Isi GEMINI_API_KEY di .env.\n");
}
fwrite(STDOUT, "Ketik pesan (atau 'exit' untuk keluar).\n\n");

$waId = 'local-tester';
while (true) {
    fwrite(STDOUT, 'Kamu     : ');
    $line = fgets(STDIN);
    if ($line === false) {
        break;
    }
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    if (in_array(strtolower($line), ['exit', 'quit', 'keluar'], true)) {
        break;
    }

    $t0 = microtime(true);
    try {
        $reply = $bot->handler->handle($waId, $line);
    } catch (\Throwable $e) {
        fwrite(STDOUT, '[error] ' . $e->getMessage() . "\n\n");
        continue;
    }
    $ms = (int) round((microtime(true) - $t0) * 1000);
    fwrite(STDOUT, 'Mbak Aka : ' . ($reply ?? '(tidak ada balasan)') . "  ({$ms}ms)\n\n");
}

fwrite(STDOUT, "Sampai jumpa kak 👋\n");
