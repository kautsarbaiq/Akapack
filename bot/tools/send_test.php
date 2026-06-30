<?php

declare(strict_types=1);

/**
 * Kirim 1 pesan WhatsApp untuk menguji kredensial Cloud API (outbound).
 *
 *   php tools/send_test.php <nomor_wa_internasional> ["pesan"]
 *   contoh: php tools/send_test.php 62812xxxxxxx "Halo dari Mbak Aka"
 *
 * Syarat: .env terisi WA_ACCESS_TOKEN + WA_PHONE_NUMBER_ID, dan nomor tujuan
 * sudah PERNAH chat ke nomor bot dalam 24 jam terakhir (jendela 24 jam Meta).
 * Kalau belum, kirim dulu "halo" DARI HP kamu ke nomor bot, baru jalankan ini.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

putenv('WA_SEND_DRIVER=api'); // paksa kirim sungguhan ke Meta

/** @var \Akapack\Bot\Bot $bot */
$bot = require dirname(__DIR__) . '/bootstrap.php';

$to = preg_replace('/[^0-9]/', '', $argv[1] ?? '') ?? '';
$text = $argv[2] ?? 'Halo! Ini pesan tes dari Mbak Aka 🤖 (Akapack)';

if ($to === '') {
    fwrite(STDERR, "Pakai: php tools/send_test.php <nomor_wa_internasional> [pesan]\n");
    fwrite(STDERR, "Contoh: php tools/send_test.php 62812xxxxxxx\n");
    exit(1);
}
if ($bot->config->accessToken === '' || $bot->config->phoneNumberId === '') {
    fwrite(STDERR, "❌ WA_ACCESS_TOKEN / WA_PHONE_NUMBER_ID belum diisi di .env\n");
    exit(1);
}

fwrite(STDOUT, "Mengirim ke {$to} ...\n");
$ok = $bot->whatsapp->sendText($to, $text);
fwrite(STDOUT, $ok
    ? "✅ Terkirim. Cek WhatsApp di HP kamu.\n"
    : "❌ Gagal. Lihat detail error dari Meta di " . $bot->config->dataDir . "/bot.log\n");
exit($ok ? 0 : 1);
