<?php

declare(strict_types=1);

/**
 * Endpoint webhook WhatsApp AKAPACK.
 *
 * Logika async-nya dipindah ke folder `bot/` (receiver -> queue -> worker).
 * File ini sengaja dipertahankan sebagai shim supaya URL webhook lama tetap
 * berfungsi. Arahkan webhook Meta ke sini ATAU langsung ke bot/public/webhook.php.
 *
 * Setup & arsitektur: lihat bot/README.md
 */

require __DIR__ . '/bot/public/webhook.php';
