<?php

declare(strict_types=1);

namespace Akapack\Bot\Store;

/**
 * Abstraksi antrian + state untuk pipeline async (semantik at-least-once).
 *
 * Model keandalan:
 *  - Receiver TIDAK menandai pesan "seen". Ia hanya appendBuffer + scheduleDue.
 *  - Worker membaca buffer secara NON-destruktif (peekBuffer), memproses, lalu
 *    HANYA setelah balasan terkirim sukses: markSeen(id) + ackBuffer(id).
 *  - Gagal kirim / crash -> buffer tetap utuh, due di-arm ulang -> dicoba lagi.
 *  - "seen" mencegah balasan ganda saat Meta mengirim ulang message_id yang
 *    SUDAH dibalas (idempotent reply).
 *
 * RedisStore (produksi) & FileStore (dev) WAJIB bersemantik identik.
 */
interface Store
{
    /** Apakah message_id ini sudah pernah DIBALAS dengan sukses. */
    public function seen(string $id): bool;

    /** Tandai daftar message_id sebagai sudah dibalas (commit-on-success). */
    public function markSeen(array $ids, int $ttl): void;

    /** Tambahkan satu fragmen pesan ke buffer pengirim. */
    public function appendBuffer(string $waId, array $message): void;

    /**
     * Baca buffer TANPA menghapus.
     * @return array<int,array> fragmen pesan (urutan masuk).
     */
    public function peekBuffer(string $waId): array;

    /** Hapus fragmen dengan id tertentu dari buffer (ack setelah sukses). */
    public function ackBuffer(string $waId, array $ids): void;

    /**
     * Set/refresh waktu jatuh tempo debounce (menimpa nilai lama — pesan baru
     * mendorong tenggat maju; juga dipakai untuk arm-ulang saat retry).
     */
    public function scheduleDue(string $waId, float $dueAt): void;

    /**
     * Ambil & hapus (klaim) daftar pengirim yang dueAt <= now.
     * @return string[] daftar waId yang siap diproses.
     */
    public function popDue(float $now): array;

    /** Mode percakapan: 'BOT' (default) atau 'HUMAN'. */
    public function getMode(string $waId): string;

    public function setMode(string $waId, string $mode): void;
}
