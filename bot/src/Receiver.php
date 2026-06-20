<?php

declare(strict_types=1);

namespace Akapack\Bot;

use Akapack\Bot\Store\Store;

/**
 * RECEIVER: titik masuk webhook.
 * Tugasnya HANYA cepat: verifikasi tanda tangan, enqueue, lalu balas 200 (<5 dtk).
 * TIDAK memanggil Claude / Send API di sini, dan TIDAK menandai pesan "seen"
 * (dedup dilakukan worker setelah balasan sukses -> at-least-once, anti pesan hilang).
 */
final class Receiver
{
    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Logger $logger,
    ) {
    }

    /** Verifikasi webhook saat setup (GET hub.challenge). */
    public function handleGet(array $query): Response
    {
        $mode = $query['hub_mode'] ?? $query['hub.mode'] ?? null;
        $token = $query['hub_verify_token'] ?? $query['hub.verify_token'] ?? null;
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        if ($mode === 'subscribe' && $token !== null && hash_equals($this->config->verifyToken, (string) $token)) {
            return new Response(200, $challenge);
        }

        $this->logger->warn('Verifikasi webhook gagal', ['mode' => $mode]);
        return new Response(403, 'Forbidden');
    }

    /**
     * Terima event (POST). Setelah tanda tangan valid, SELALU balas 200 walau
     * enqueue gagal — supaya Meta mengirim ulang (pesan tidak hilang diam-diam).
     */
    public function handlePost(string $rawBody, ?string $signatureHeader): Response
    {
        if (!$this->verifySignature($rawBody, $signatureHeader)) {
            $this->logger->warn('Tanda tangan webhook tidak valid / ditolak');
            return new Response(403, 'invalid signature');
        }

        try {
            $this->enqueuePayload($rawBody);
        } catch (\Throwable $e) {
            // Jangan 500 — biarkan Meta retry. Tidak ada dedup yang memblokir retry.
            $this->logger->error('Gagal enqueue, minta Meta retry', ['err' => $e->getMessage()]);
        }

        return new Response(200, 'EVENT_RECEIVED');
    }

    private function enqueuePayload(string $rawBody): void
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return;
        }

        $enqueued = 0;
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['messages'] ?? [] as $message) {
                    if ($this->enqueueMessage($message)) {
                        $enqueued++;
                    }
                }
            }
        }

        if ($enqueued > 0) {
            $this->logger->info('Pesan masuk di-enqueue', ['count' => $enqueued]);
        }
    }

    private function enqueueMessage(array $message): bool
    {
        $id = (string) ($message['id'] ?? '');
        $from = (string) ($message['from'] ?? '');
        if ($id === '' || $from === '') {
            return false;
        }

        $fragment = [
            'id' => $id,
            'type' => (string) ($message['type'] ?? 'unknown'),
            'text' => self::extractText($message),
            'ts' => (int) ($message['timestamp'] ?? time()),
        ];

        // appendBuffer dulu, baru scheduleDue. Bila crash di antaranya, Meta
        // mengirim ulang message_id yang sama -> fragmen di-append lagi & worker
        // mendeduplikasi per-id. Tidak ada pesan hilang.
        $this->store->appendBuffer($from, $fragment);
        $this->store->scheduleDue($from, microtime(true) + $this->config->debounceSeconds);
        return true;
    }

    /** Verifikasi X-Hub-Signature-256 (HMAC-SHA256 atas raw body dgn App Secret). */
    private function verifySignature(string $rawBody, ?string $signatureHeader): bool
    {
        if ($this->config->appSecret === '') {
            // Tanpa App Secret: TOLAK kecuali operator secara eksplisit mengizinkan (dev).
            if ($this->config->allowInsecureWebhook) {
                $this->logger->warn('WA_APP_SECRET kosong — verifikasi dilewati (WA_ALLOW_INSECURE_WEBHOOK aktif, HANYA dev)');
                return true;
            }
            $this->logger->error('WA_APP_SECRET kosong & insecure tidak diizinkan — webhook ditolak');
            return false;
        }
        if ($signatureHeader === null || !str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->config->appSecret);
        return hash_equals($expected, $signatureHeader);
    }

    /** Ekstrak teks dari berbagai tipe pesan WhatsApp. */
    public static function extractText(array $message): string
    {
        return match ($message['type'] ?? '') {
            'text' => trim((string) ($message['text']['body'] ?? '')),
            'button' => trim((string) ($message['button']['text'] ?? '')),
            'interactive' => trim((string) (
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? ''
            )),
            'image' => '[gambar]',
            'document' => '[dokumen]',
            'audio' => '[pesan suara]',
            'location' => '[lokasi]',
            default => '',
        };
    }
}
