<?php

declare(strict_types=1);

namespace Akapack\Bot\Store;

use Predis\Client;

/**
 * Implementasi Store berbasis Redis (Predis). Disarankan untuk produksi VPS:
 * dedup atomik (SET NX EX), debounce via sorted set, aman untuk multi-worker.
 */
final class RedisStore implements Store
{
    private const PREFIX = 'wa:';
    private const DUE_KEY = 'wa:due';
    private const VALID_MODES = ['BOT', 'HUMAN'];

    /** Lua: ambil semua anggota due <= now lalu hapus (atomik, aman multi-worker). */
    private const POP_DUE_LUA = <<<'LUA'
        local ids = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
        if #ids > 0 then
            redis.call('ZREM', KEYS[1], unpack(ids))
        end
        return ids
        LUA;

    /** Lua: hapus fragmen ber-id tertentu dari list buffer (preserve sisanya). */
    private const ACK_BUFFER_LUA = <<<'LUA'
        local items = redis.call('LRANGE', KEYS[1], 0, -1)
        redis.call('DEL', KEYS[1])
        for i = 1, #items do
            local ok, obj = pcall(cjson.decode, items[i])
            local keep = true
            if ok and obj and obj.id then
                for j = 1, #ARGV do
                    if ARGV[j] == obj.id then keep = false break end
                end
            end
            if keep then redis.call('RPUSH', KEYS[1], items[i]) end
        end
        return 1
        LUA;

    public function __construct(private readonly Client $redis)
    {
    }

    public static function fromDsn(string $dsn): self
    {
        return new self(new Client($dsn));
    }

    public function seen(string $id): bool
    {
        return (int) $this->redis->exists(self::PREFIX . 'dedup:' . $id) > 0;
    }

    public function markSeen(array $ids, int $ttl): void
    {
        if ($ids === []) {
            return;
        }
        $this->redis->pipeline(function ($pipe) use ($ids, $ttl): void {
            foreach ($ids as $id) {
                $pipe->set(self::PREFIX . 'dedup:' . $id, '1', 'EX', $ttl);
            }
        });
    }

    public function appendBuffer(string $waId, array $message): void
    {
        $this->redis->rpush(
            self::PREFIX . 'buf:' . $waId,
            [json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    public function peekBuffer(string $waId): array
    {
        $rows = $this->redis->lrange(self::PREFIX . 'buf:' . $waId, 0, -1);
        $out = [];
        foreach ((array) $rows as $row) {
            $decoded = json_decode((string) $row, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    public function ackBuffer(string $waId, array $ids): void
    {
        $key = self::PREFIX . 'buf:' . $waId;
        $this->redis->eval(self::ACK_BUFFER_LUA, 1, $key, ...array_map('strval', $ids));
    }

    public function scheduleDue(string $waId, float $dueAt): void
    {
        $this->redis->zadd(self::DUE_KEY, [$waId => $dueAt]);
    }

    public function popDue(float $now): array
    {
        $ids = $this->redis->eval(self::POP_DUE_LUA, 1, self::DUE_KEY, (string) $now);
        return is_array($ids) ? array_map('strval', $ids) : [];
    }

    public function getMode(string $waId): string
    {
        $v = (string) ($this->redis->get(self::PREFIX . 'mode:' . $waId) ?? '');
        return in_array($v, self::VALID_MODES, true) ? $v : 'BOT';
    }

    public function setMode(string $waId, string $mode): void
    {
        $mode = in_array($mode, self::VALID_MODES, true) ? $mode : 'BOT';
        $this->redis->set(self::PREFIX . 'mode:' . $waId, $mode);
    }
}
