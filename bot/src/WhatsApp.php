<?php

declare(strict_types=1);

namespace Akapack\Bot;

/**
 * Klien WhatsApp Cloud API (Send API).
 *
 * Driver:
 *  - "api": kirim HTTP ke Graph API Meta (produksi).
 *  - "log": tulis pesan keluar ke file (dev/test tanpa kredensial).
 */
final class WhatsApp
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $outgoingLog,
    ) {
    }

    public function sendText(string $toWaId, string $text): bool
    {
        if ($this->config->sendDriver === 'log') {
            $line = json_encode([
                'ts' => date('c'),
                'to' => $toWaId,
                'text' => $text,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($this->outgoingLog, $line . "\n", FILE_APPEND | LOCK_EX);
            $this->logger->info('Pesan keluar (driver=log)', ['to' => $toWaId]);
            return true;
        }

        // Driver uji: simulasikan kegagalan kirim (untuk menguji retry).
        if ($this->config->sendDriver === 'fail') {
            $this->logger->warn('Pesan GAGAL kirim (driver=fail, simulasi)', ['to' => $toWaId]);
            return false;
        }

        return $this->sendViaApi($toWaId, $text);
    }

    private function sendViaApi(string $toWaId, string $text): bool
    {
        if ($this->config->accessToken === '' || $this->config->phoneNumberId === '') {
            $this->logger->error('Kredensial WA belum diisi, pesan tidak terkirim', ['to' => $toWaId]);
            return false;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->config->graphApiVersion,
            $this->config->phoneNumberId,
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toWaId,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger->error('Gagal kirim WA (curl)', ['to' => $toWaId, 'err' => $err]);
            return false;
        }
        if ($httpCode >= 400) {
            $this->logger->error('Gagal kirim WA (HTTP)', ['to' => $toWaId, 'code' => $httpCode, 'resp' => $response]);
            return false;
        }

        $this->logger->info('Pesan terkirim', ['to' => $toWaId, 'code' => $httpCode]);
        return true;
    }
}
