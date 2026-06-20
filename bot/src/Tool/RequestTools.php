<?php

declare(strict_types=1);

namespace Akapack\Bot\Tool;

use Akapack\Bot\Escalator;
use Akapack\Bot\Supabase\SupabaseTools;

/**
 * Eksekutor tool per-request (terikat ke satu nomor WA). Menggabungkan tools
 * Supabase (read) dengan eskalasi_ke_admin (butuh konteks waId). Dibuat ulang
 * tiap pesan masuk karena membawa waId.
 */
final class RequestTools implements ToolExecutor
{
    public function __construct(
        private readonly string $waId,
        private readonly SupabaseTools $supabase,
        private readonly Escalator $escalator,
    ) {
    }

    /** Definisi tool eskalasi (digabung dengan SupabaseTools::definitions()). */
    public static function escalationDefinition(): array
    {
        return [
            'name' => 'eskalasi_ke_admin',
            'description' => 'Serahkan percakapan ke admin manusia untuk: nego harga, order partai besar, '
                . 'komplain, pembayaran, atau hal di luar kemampuanmu. Setelah memanggil tool ini, balas '
                . 'pelanggan singkat bahwa kamu sudah menyambungkan ke admin (jangan janji waktu balas).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'alasan' => [
                        'type' => 'string',
                        'enum' => ['nego_harga', 'order_partai', 'komplain', 'pembayaran', 'lainnya'],
                    ],
                    'ringkasan' => ['type' => 'string', 'description' => 'Ringkasan singkat kebutuhan pelanggan untuk admin'],
                    'cabang' => ['type' => 'string', 'enum' => ['bandung', 'garut'], 'description' => 'Opsional: cabang terkait bila jelas'],
                ],
                'required' => ['alasan', 'ringkasan'],
            ],
        ];
    }

    public function execute(string $name, array $input): string
    {
        if ($name === 'eskalasi_ke_admin') {
            $ok = $this->escalator->escalate(
                $this->waId,
                (string) ($input['alasan'] ?? 'lainnya'),
                (string) ($input['ringkasan'] ?? ''),
                isset($input['cabang']) ? (string) $input['cabang'] : null,
                ackUser: false, // model yang membalas ke pelanggan
            );
            return json_encode(['status' => $ok ? 'escalated' : 'gagal', 'mode' => $ok ? 'HUMAN' : 'BOT'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return $this->supabase->execute($name, $input);
    }
}
