<?php

declare(strict_types=1);

/**
 * Bootstrap bersama untuk semua entry point (webhook, worker, simulator).
 * Memuat autoloader (composer bila ada, jika tidak pakai fallback PSR-4) dan
 * mengembalikan instance Bot yang sudah dirakit.
 *
 * Catatan: driver Redis butuh predis (composer install). Driver file (default
 * dev) berjalan tanpa dependency apa pun.
 */

$base = __DIR__;
$composer = $base . '/vendor/autoload.php';

if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class) use ($base): void {
        $prefix = 'Akapack\\Bot\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $base . '/src/' . $rel . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

return new \Akapack\Bot\Bot($base);
