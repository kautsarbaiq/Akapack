<?php

declare(strict_types=1);

namespace Akapack\Bot\Memory;

use Akapack\Bot\Logger;
use Akapack\Bot\Supabase\SupabaseClient;

/**
 * Memori percakapan di tabel Supabase `wa_messages` (lihat sql/wa_messages.sql).
 * Best-effort: bila tabel belum dibuat / Supabase error, degrade tanpa
 * menggagalkan balasan (log saja).
 */
final class SupabaseMemory implements ConversationMemory
{
    private const TABLE = 'wa_messages';

    public function __construct(
        private readonly SupabaseClient $db,
        private readonly Logger $logger,
        private readonly string $tenantId,
    ) {
    }

    public function recent(string $waId, int $limit): array
    {
        try {
            $rows = $this->db->select(self::TABLE, [
                'wa_id' => 'eq.' . $waId,
                'select' => 'role,content',
                'order' => 'created_at.desc',
                'limit' => max(1, $limit),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warn('Memori: gagal baca (degrade)', ['err' => $e->getMessage()]);
            return [];
        }

        $turns = [];
        foreach (array_reverse($rows) as $r) { // desc → kembalikan lama→baru
            $role = ($r['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = (string) ($r['content'] ?? '');
            if ($content !== '') {
                $turns[] = ['role' => $role, 'content' => $content];
            }
        }
        return $turns;
    }

    public function append(string $waId, string $role, string $content): void
    {
        $role = $role === 'assistant' ? 'assistant' : 'user';
        try {
            $this->db->insert(self::TABLE, [
                'tenant_id' => $this->tenantId,
                'wa_id' => $waId,
                'role' => $role,
                'content' => $content,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warn('Memori: gagal tulis (degrade)', ['err' => $e->getMessage()]);
        }
    }
}
