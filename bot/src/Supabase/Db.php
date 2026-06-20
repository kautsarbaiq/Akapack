<?php

declare(strict_types=1);

namespace Akapack\Bot\Supabase;

/**
 * Akses baca-only ke Supabase (PostgREST). Abstraksi supaya SupabaseTools
 * bisa diuji dengan fake tanpa jaringan.
 */
interface Db
{
    /**
     * SELECT via PostgREST.
     * @param array<string,scalar> $query param query (mis. ['select'=>'id,name','limit'=>8])
     * @return array<int,array<string,mixed>> baris hasil
     */
    public function select(string $table, array $query): array;
}
