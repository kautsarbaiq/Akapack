<?php

declare(strict_types=1);

namespace Akapack\Bot\Store;

/**
 * Implementasi Store berbasis file + flock. Tanpa dependency eksternal.
 * Cocok untuk dev/test dan volume kecil. Untuk produksi gunakan RedisStore.
 *
 * Penulisan JSON bersifat crash-atomic (tulis ke temp lalu rename), dan file
 * korup (non-kosong tapi gagal decode) dibuang ke .corrupt + dianggap kosong —
 * BUKAN diam-diam menimpa state valid.
 */
final class FileStore implements Store
{
    private readonly string $dedupDir;
    private readonly string $bufferDir;
    private readonly string $dueFile;
    private readonly string $modeFile;
    private readonly string $gcMarker;

    private const VALID_MODES = ['BOT', 'HUMAN'];

    public function __construct(string $dataDir)
    {
        $root = rtrim($dataDir, '/');
        $this->dedupDir = $root . '/dedup';
        $this->bufferDir = $root . '/buffers';
        $this->dueFile = $root . '/due.json';
        $this->modeFile = $root . '/modes.json';
        $this->gcMarker = $root . '/.dedup-gc';

        foreach ([$this->dedupDir, $this->bufferDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    public function seen(string $id): bool
    {
        $file = $this->dedupDir . '/' . sha1($id);
        if (!is_file($file)) {
            return false;
        }
        $expiry = (int) @file_get_contents($file);
        if ($expiry > 0 && time() > $expiry) {
            @unlink($file); // kedaluwarsa
            return false;
        }
        return true;
    }

    public function markSeen(array $ids, int $ttl): void
    {
        $expiry = time() + $ttl;
        foreach ($ids as $id) {
            $file = $this->dedupDir . '/' . sha1((string) $id);
            $this->atomicWrite($file, (string) $expiry);
        }
        $this->maybeGc();
    }

    public function appendBuffer(string $waId, array $message): void
    {
        $this->mutateJson($this->bufferFile($waId), static function (array &$data) use ($message): void {
            $data[] = $message;
        });
    }

    public function peekBuffer(string $waId): array
    {
        return $this->readJson($this->bufferFile($waId));
    }

    public function ackBuffer(string $waId, array $ids): void
    {
        $drop = array_fill_keys(array_map('strval', $ids), true);
        $this->mutateJson($this->bufferFile($waId), static function (array &$data) use ($drop): void {
            $data = array_values(array_filter(
                $data,
                static fn ($m) => !isset($drop[(string) ($m['id'] ?? '')])
            ));
        });
    }

    public function scheduleDue(string $waId, float $dueAt): void
    {
        $this->mutateJson($this->dueFile, static function (array &$data) use ($waId, $dueAt): void {
            $data[$waId] = $dueAt; // timpa: pesan baru / retry mendorong tenggat
        });
    }

    public function popDue(float $now): array
    {
        $ready = $this->mutateJson($this->dueFile, static function (array &$data) use ($now): array {
            $ids = [];
            foreach ($data as $waId => $dueAt) {
                if ((float) $dueAt <= $now) {
                    $ids[] = (string) $waId;
                    unset($data[$waId]);
                }
            }
            return $ids;
        });

        $this->maybeGc();
        return $ready;
    }

    public function getMode(string $waId): string
    {
        $data = $this->readJson($this->modeFile);
        $v = isset($data[$waId]) ? (string) $data[$waId] : '';
        return in_array($v, self::VALID_MODES, true) ? $v : 'BOT';
    }

    public function setMode(string $waId, string $mode): void
    {
        $mode = in_array($mode, self::VALID_MODES, true) ? $mode : 'BOT';
        $this->mutateJson($this->modeFile, static function (array &$data) use ($waId, $mode): void {
            $data[$waId] = $mode;
        });
    }

    private function bufferFile(string $waId): string
    {
        return $this->bufferDir . '/' . sha1($waId) . '.json';
    }

    /** Baca file JSON (shared lock). File korup -> backup .corrupt + anggap kosong. */
    private function readJson(string $file): array
    {
        $fh = @fopen($file, 'r');
        if ($fh === false) {
            return [];
        }
        try {
            flock($fh, LOCK_SH);
            $raw = stream_get_contents($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        return $this->decodeOrQuarantine($file, $raw === false ? '' : $raw);
    }

    /**
     * Read-modify-write atomik pada satu file JSON (exclusive lock + temp+rename).
     * @return mixed nilai yang dikembalikan callback.
     */
    private function mutateJson(string $file, callable $fn): mixed
    {
        $lock = $file . '.lock';
        $lh = fopen($lock, 'c');
        if ($lh === false) {
            throw new \RuntimeException("Tidak bisa membuka lock: {$lock}");
        }

        try {
            flock($lh, LOCK_EX);

            $raw = is_file($file) ? (string) @file_get_contents($file) : '';
            $data = $this->decodeOrQuarantine($file, $raw);

            $ret = $fn($data);

            $this->atomicWrite($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $ret;
        } finally {
            flock($lh, LOCK_UN);
            fclose($lh);
        }
    }

    /**
     * Decode JSON; bedakan KOSONG dari KORUP. File non-kosong yang gagal decode
     * dipindah ke .corrupt agar tidak menimpa diam-diam state valid.
     */
    private function decodeOrQuarantine(string $file, string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
        // Non-kosong tapi bukan array valid -> karantina.
        @rename($file, $file . '.corrupt.' . time());
        return [];
    }

    /** Tulis file secara atomik: ke temp di direktori sama lalu rename(). */
    private function atomicWrite(string $file, string $contents): void
    {
        $tmp = $file . '.tmp.' . getmypid() . '.' . substr(sha1($file . $contents), 0, 8);
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Gagal menulis file state: {$file}");
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Gagal rename file state: {$file}");
        }
    }

    /** Bersihkan marker dedup yang kedaluwarsa, maksimal sekali per jam. */
    private function maybeGc(): void
    {
        $last = is_file($this->gcMarker) ? (int) @filemtime($this->gcMarker) : 0;
        if (time() - $last < 3600) {
            return;
        }
        @touch($this->gcMarker);

        $now = time();
        foreach (glob($this->dedupDir . '/*') ?: [] as $f) {
            $expiry = (int) @file_get_contents($f);
            if ($expiry > 0 && $now > $expiry) {
                @unlink($f);
            }
        }
    }
}
