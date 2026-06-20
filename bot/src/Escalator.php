<?php

declare(strict_types=1);

namespace Akapack\Bot;

use Akapack\Bot\Memory\ConversationMemory;
use Akapack\Bot\Store\Store;

/**
 * Handoff ke admin (dipakai Worker untuk keyword "admin"/"cs" dan oleh tool
 * eskalasi_ke_admin). Notifikasi admin DULU, baru set mode=HUMAN, supaya tidak
 * ada user nyangkut tanpa admin tahu. Routing per cabang bila diketahui.
 */
final class Escalator
{
    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly WhatsApp $whatsapp,
        private readonly Logger $logger,
        private readonly ConversationMemory $memory,
    ) {
    }

    /**
     * @param string      $reason  intent (mis. nego_harga|order_partai|komplain|pembayaran|lainnya|permintaan_langsung)
     * @param string      $summary ringkasan kebutuhan pelanggan
     * @param string|null $cabang  'bandung'|'garut'|null (null = semua admin)
     * @param bool        $ackUser kirim balasan "disambungkan" ke user (false bila model yang balas)
     * @return bool true bila handoff berhasil (admin ternotifikasi / tak ada admin dikonfigurasi)
     */
    public function escalate(string $waId, string $reason, string $summary, ?string $cabang = null, bool $ackUser = true): bool
    {
        $admins = $this->pickAdmins($cabang);
        $notice = $this->buildNotice($waId, $reason, $summary, $cabang);

        $delivered = $admins === []; // tanpa admin terkonfigurasi: tetap lanjut handoff
        foreach ($admins as $admin) {
            if ($this->whatsapp->sendText($admin, $notice)) {
                $delivered = true;
            }
        }

        if (!$delivered) {
            $this->logger->warn('Handoff: notifikasi admin gagal, ditunda (retry)', ['waId' => $waId, 'reason' => $reason]);
            return false;
        }

        $this->store->setMode($waId, 'HUMAN');
        $this->logger->info('Eskalasi ke admin', ['waId' => $waId, 'reason' => $reason, 'cabang' => $cabang]);

        if ($ackUser) {
            $this->whatsapp->sendText(
                $waId,
                "Baik kak 🙏 aku sambungkan ke admin ya. Mohon tunggu sebentar, "
                . "nanti dibalas langsung sama tim kami."
            );
        }
        return true;
    }

    /** @return string[] nomor admin tujuan (unik). */
    private function pickAdmins(?string $cabang): array
    {
        $cabang = $cabang !== null ? strtolower($cabang) : null;
        $list = match ($cabang) {
            'bandung' => [$this->config->adminWaBandung],
            'garut' => [$this->config->adminWaGarut],
            default => [$this->config->adminWaBandung, $this->config->adminWaGarut],
        };
        return array_values(array_unique(array_filter($list)));
    }

    private function buildNotice(string $waId, string $reason, string $summary, ?string $cabang): string
    {
        $lines = [
            "🔔 Handoff {$this->config->companyName}",
            "Dari: {$waId}",
            'Cabang: ' . ($cabang !== null ? ucfirst($cabang) : 'belum jelas'),
            "Alasan: {$reason}",
        ];
        if (trim($summary) !== '') {
            $lines[] = 'Ringkasan: ' . mb_substr($summary, 0, 500);
        }

        $history = $this->memory->recent($waId, 6);
        if ($history !== []) {
            $lines[] = '';
            $lines[] = 'Riwayat terakhir:';
            foreach ($history as $turn) {
                $who = $turn['role'] === 'assistant' ? 'Bot' : 'Pelanggan';
                $lines[] = "• {$who}: " . mb_substr($turn['content'], 0, 160);
            }
        }

        return implode("\n", $lines);
    }
}
