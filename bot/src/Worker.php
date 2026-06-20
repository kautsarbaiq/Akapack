<?php

declare(strict_types=1);

namespace Akapack\Bot;

use Akapack\Bot\Handler\Handler;
use Akapack\Bot\Store\Store;

/**
 * WORKER: proses async di luar request webhook (semantik at-least-once).
 *
 * Loop: ambil pengirim yang lewat debounce -> peekBuffer (non-destruktif) ->
 * buang fragmen yang SUDAH dibalas (idempotent) -> ROUTER (mode BOT/HUMAN,
 * kata kunci eskalasi) -> Handler -> kirim. HANYA setelah sukses: markSeen +
 * ackBuffer. Gagal kirim / error -> buffer dibiarkan, due di-arm ulang (retry).
 */
final class Worker
{
    private bool $running = true;

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly WhatsApp $whatsapp,
        private readonly Handler $handler,
        private readonly Logger $logger,
    ) {
    }

    /** Jalankan sebagai daemon (systemd). Berhenti rapi pada SIGTERM/SIGINT. */
    public function runDaemon(int $idleSleepMs = 250): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->running = false);
            pcntl_signal(SIGINT, fn () => $this->running = false);
        }

        $this->logger->info('Worker dimulai', ['driver' => $this->config->queueDriver]);
        while ($this->running) {
            try {
                $processed = $this->tick(microtime(true), microtime(true));
            } catch (\Throwable $e) {
                $this->logger->error('Error di loop worker', ['err' => $e->getMessage()]);
                $processed = 0;
            }
            if ($processed === 0) {
                usleep($idleSleepMs * 1000);
            }
        }
        $this->logger->info('Worker berhenti rapi');
    }

    /**
     * Satu siklus (untuk test/cron). $drainAll mengabaikan debounce.
     * @return int jumlah pengirim yang diproses.
     */
    public function runOnce(bool $drainAll = false): int
    {
        $now = microtime(true);
        return $this->tick($drainAll ? PHP_FLOAT_MAX : $now, $now);
    }

    /**
     * @param float $popNow batas waktu untuk memilih due (PHP_FLOAT_MAX = drain)
     * @param float $now    waktu nyata untuk hitung backoff/usia
     */
    private function tick(float $popNow, float $now): int
    {
        $count = 0;
        foreach ($this->store->popDue($popNow) as $waId) {
            // Isolasi per-pengirim: kegagalan satu nomor tidak menelantarkan batch.
            try {
                $this->processUser($waId, $now);
            } catch (\Throwable $e) {
                $this->logger->error('processUser gagal, arm ulang', ['waId' => $waId, 'err' => $e->getMessage()]);
                $this->safeReArm($waId, $now);
            }
            $count++;
        }
        return $count;
    }

    private function processUser(string $waId, float $now): void
    {
        $messages = $this->store->peekBuffer($waId);
        if ($messages === []) {
            return;
        }

        $allIds = array_values(array_unique(array_map(
            static fn ($m) => (string) ($m['id'] ?? ''),
            $messages
        )));

        // Buang fragmen yang sudah pernah dibalas (mis. Meta kirim ulang).
        $fresh = $this->dedupeUnseen($messages);
        if ($fresh === []) {
            $this->store->ackBuffer($waId, $allIds); // semua sudah dibalas -> bersihkan
            return;
        }

        $freshIds = array_map(static fn ($m) => (string) $m['id'], $fresh);

        // Batas usia: menyerah setelah retry berkepanjangan (hindari loop abadi).
        $oldest = min(array_map(static fn ($m) => (int) ($m['ts'] ?? 0), $fresh));
        if ($oldest > 0 && ($now - $oldest) > $this->config->maxRetryAgeSeconds) {
            $this->logger->warn('Menyerah kirim setelah retry (usia melewati batas)', ['waId' => $waId]);
            $this->store->markSeen($freshIds, $this->config->dedupTtl);
            $this->store->ackBuffer($waId, $allIds);
            return;
        }

        // ROUTER: sudah diserahkan ke manusia -> bot diam (tetap konsumsi buffer).
        if ($this->store->getMode($waId) === 'HUMAN') {
            $this->logger->info('Mode HUMAN, bot diam', ['waId' => $waId]);
            $this->store->ackBuffer($waId, $allIds);
            return;
        }

        // Kata kunci eskalasi langsung — dicek PER fragmen (sebelum digabung),
        // agar "halo" lalu "cs" tetap memicu eskalasi.
        if ($this->hasEscalationKeyword($fresh)) {
            if ($this->escalate($waId, 'permintaan_langsung', $this->combineText($fresh))) {
                $this->store->markSeen($freshIds, $this->config->dedupTtl);
                $this->store->ackBuffer($waId, $allIds);
            } else {
                $this->store->scheduleDue($waId, $now + $this->config->retryBackoffSeconds);
            }
            return;
        }

        $text = $this->combineText($fresh);
        if ($text === '') {
            $this->store->markSeen($freshIds, $this->config->dedupTtl);
            $this->store->ackBuffer($waId, $allIds);
            return;
        }

        $reply = $this->handler->handle($waId, $text);
        if ($reply === null || $reply === '') {
            $this->store->markSeen($freshIds, $this->config->dedupTtl);
            $this->store->ackBuffer($waId, $allIds);
            return;
        }

        if ($this->whatsapp->sendText($waId, $reply)) {
            $this->store->markSeen($freshIds, $this->config->dedupTtl);
            $this->store->ackBuffer($waId, $allIds);
        } else {
            // Gagal kirim -> jangan ack, coba lagi nanti.
            $this->logger->warn('Kirim gagal, dijadwalkan ulang', ['waId' => $waId]);
            $this->store->scheduleDue($waId, $now + $this->config->retryBackoffSeconds);
        }
    }

    /**
     * Eskalasi: notifikasi admin DULU, baru set mode=HUMAN setelah terkirim,
     * supaya tidak ada user yang "nyangkut" di mode HUMAN tanpa admin tahu.
     * @return bool true bila handoff berhasil (boleh di-ack), false -> retry.
     */
    private function escalate(string $waId, string $reason, string $text): bool
    {
        $admins = array_values(array_unique(array_filter([
            $this->config->adminWaBandung,
            $this->config->adminWaGarut,
        ])));

        $notice = "🔔 Handoff {$this->config->companyName}\n"
            . "Dari: {$waId}\n"
            . "Alasan: {$reason}\n"
            . "Pesan terakhir:\n" . mb_substr($text, 0, 500);

        $delivered = $admins === []; // tanpa admin dikonfigurasi: lanjut handoff
        foreach ($admins as $admin) {
            if ($this->whatsapp->sendText($admin, $notice)) {
                $delivered = true;
            }
        }

        if (!$delivered) {
            $this->logger->warn('Notifikasi admin gagal, eskalasi ditunda (retry)', ['waId' => $waId]);
            return false;
        }

        $this->store->setMode($waId, 'HUMAN');
        $this->logger->info('Eskalasi ke admin', ['waId' => $waId, 'reason' => $reason]);

        if (!$this->whatsapp->sendText(
            $waId,
            "Baik kak 🙏 aku sambungkan ke admin ya. Mohon tunggu sebentar, "
            . "nanti dibalas langsung sama tim kami."
        )) {
            $this->logger->warn('Balasan eskalasi ke user gagal (admin sudah diberi tahu)', ['waId' => $waId]);
        }
        return true;
    }

    /** Fragmen yang message_id-nya BELUM pernah dibalas, unik per-id. */
    private function dedupeUnseen(array $messages): array
    {
        $out = [];
        $seenLocal = [];
        foreach ($messages as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '' || isset($seenLocal[$id]) || $this->store->seen($id)) {
                continue;
            }
            $seenLocal[$id] = true;
            $out[] = $m;
        }
        return $out;
    }

    private function hasEscalationKeyword(array $messages): bool
    {
        foreach ($messages as $m) {
            $cmd = strtolower(trim((string) ($m['text'] ?? '')));
            if ($cmd === 'admin' || $cmd === 'cs') {
                return true;
            }
        }
        return false;
    }

    /** Gabungkan beberapa fragmen pesan yang masuk dalam jendela debounce. */
    private function combineText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $m) {
            $t = trim((string) ($m['text'] ?? ''));
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        return implode("\n", $parts);
    }

    private function safeReArm(string $waId, float $now): void
    {
        try {
            $this->store->scheduleDue($waId, $now + $this->config->retryBackoffSeconds);
        } catch (\Throwable $e) {
            $this->logger->error('Gagal arm ulang due', ['waId' => $waId, 'err' => $e->getMessage()]);
        }
    }
}
