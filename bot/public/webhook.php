<?php

declare(strict_types=1);

/**
 * RECEIVER endpoint (public). Arahkan URL webhook Meta ke file ini.
 * Hanya verifikasi + enqueue lalu balas cepat. Tidak ada panggilan Claude di sini.
 */

use Akapack\Bot\Response;

try {
    /** @var \Akapack\Bot\Bot $bot */
    $bot = require __DIR__ . '/../bootstrap.php';
    $receiver = $bot->receiver();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $res = $receiver->handleGet($_GET);
    } elseif ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $raw = $raw === false ? '' : $raw;
        $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
        $res = $receiver->handlePost($raw, $sig);
    } else {
        $res = new Response(405, 'Method Not Allowed');
    }
} catch (\Throwable $e) {
    // Kegagalan tak terduga: balas 500 agar Meta MENGIRIM ULANG (tidak ada
    // pesan yang hilang diam-diam). Receiver sendiri tak pernah menandai "seen"
    // sebelum balasan sukses, jadi retry aman.
    error_log('[akapack-bot] webhook fatal: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ERROR';
    return;
}

http_response_code($res->code);
header('Content-Type: text/plain; charset=utf-8');
echo $res->body;

// Di php-fpm: tutup koneksi ke Meta secepatnya (enqueue sudah selesai di atas).
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
