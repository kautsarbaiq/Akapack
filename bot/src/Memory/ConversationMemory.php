<?php

declare(strict_types=1);

namespace Akapack\Bot\Memory;

/**
 * Memori percakapan (sliding-window) per nomor WA. Best-effort: kegagalan
 * baca/tulis tidak boleh menggagalkan balasan bot.
 */
interface ConversationMemory
{
    /**
     * Ambil beberapa turn terakhir (urut lama → baru).
     * @return array<int,array{role:string,content:string}>
     */
    public function recent(string $waId, int $limit): array;

    /** Simpan satu turn. role: 'user' | 'assistant'. */
    public function append(string $waId, string $role, string $content): void;
}
