<?php

declare(strict_types=1);

namespace Akapack\Bot\Supabase;

use Akapack\Bot\Logger;

/**
 * Klien PostgREST Supabase (anon key). READ-ONLY.
 *
 * GUARDRAIL cost_price (RLS internal OFF → anon BISA baca cost_price, jadi
 * WAJIB ditegakkan di sini):
 *   1) Tolak query apa pun yang menyebут kolom modal (cost_price / cost / buy_price).
 *   2) Scrub rekursif kolom rahasia dari SEMUA baris hasil sebelum dikembalikan.
 */
final class SupabaseClient implements Db
{
    /** Kolom yang tidak boleh pernah keluar dari sini. */
    private const FORBIDDEN = ['cost_price', 'buy_price', 'modal', 'margin'];

    public function __construct(
        private readonly string $url,
        private readonly string $anonKey,
        private readonly Logger $logger,
        private readonly int $timeout = 10,
    ) {
    }

    public function select(string $table, array $query): array
    {
        $this->assertNoForbidden($query);

        $endpoint = $this->url . '/rest/v1/' . rawurlencode($table)
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $this->anonKey,
                'Authorization: Bearer ' . $this->anonKey,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->logger->error('Supabase curl gagal', ['table' => $table, 'err' => $err]);
            throw new \RuntimeException('Koneksi Supabase gagal');
        }
        if ($code >= 400) {
            $this->logger->error('Supabase HTTP error', ['table' => $table, 'code' => $code, 'body' => mb_substr((string) $body, 0, 300)]);
            throw new \RuntimeException("Supabase error HTTP {$code}");
        }

        $rows = json_decode((string) $body, true);
        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'scrub'], $rows);
    }

    /** Tolak query yang menyentuh kolom rahasia (di key maupun value). */
    private function assertNoForbidden(array $query): void
    {
        foreach ($query as $k => $v) {
            $hay = strtolower($k . ' ' . (is_scalar($v) ? (string) $v : json_encode($v)));
            foreach (self::FORBIDDEN as $bad) {
                if (str_contains($hay, $bad)) {
                    $this->logger->error('GUARDRAIL: query menyentuh kolom rahasia ditolak', ['needle' => $bad]);
                    throw new \RuntimeException('Akses kolom rahasia ditolak (guardrail)');
                }
            }
        }
    }

    /** Buang kolom rahasia secara rekursif dari sebuah baris/struktur. */
    private function scrub(mixed $row): mixed
    {
        if (!is_array($row)) {
            return $row;
        }
        $out = [];
        foreach ($row as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::FORBIDDEN, true)) {
                continue; // buang kolom rahasia
            }
            $out[$k] = is_array($v) ? $this->scrub($v) : $v;
        }
        return $out;
    }
}
