<?php

declare(strict_types=1);

/**
 * WORKER entry point.
 *   php bin/worker.php           -> jalan sebagai daemon (untuk systemd)
 *   php bin/worker.php --once    -> satu siklus (hormati debounce); untuk cron
 *   php bin/worker.php --drain   -> proses semua antrian (abaikan debounce); test
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

/** @var \Akapack\Bot\Bot $bot */
$bot = require __DIR__ . '/../bootstrap.php';
$worker = $bot->worker();

$args = array_slice($argv, 1);

if (in_array('--once', $args, true)) {
    $n = $worker->runOnce(false);
    fwrite(STDOUT, "Processed: {$n}\n");
} elseif (in_array('--drain', $args, true)) {
    $n = $worker->runOnce(true);
    fwrite(STDOUT, "Processed (drain): {$n}\n");
} else {
    $worker->runDaemon();
}
