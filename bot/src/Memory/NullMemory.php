<?php

declare(strict_types=1);

namespace Akapack\Bot\Memory;

/** Memori non-aktif (dipakai bila Supabase belum dikonfigurasi). */
final class NullMemory implements ConversationMemory
{
    public function recent(string $waId, int $limit): array
    {
        return [];
    }

    public function append(string $waId, string $role, string $content): void
    {
    }
}
