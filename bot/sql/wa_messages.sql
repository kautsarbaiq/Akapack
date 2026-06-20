-- AKAPACK WhatsApp bot — memori percakapan (Fase 3).
-- Jalankan SEKALI di Supabase SQL Editor. Bot degrade dengan aman bila tabel
-- ini belum ada (balasan tetap jalan, hanya tanpa memori).
--
-- Catatan RLS: arah proyek = RLS internal OFF, jadi anon key bisa SELECT/INSERT.
-- Kalau kamu mengaktifkan RLS, tambahkan policy anon untuk SELECT & INSERT
-- pada tabel ini.

create table if not exists public.wa_messages (
    id          uuid primary key default gen_random_uuid(),
    tenant_id   uuid not null default '00000000-0000-0000-0000-000000000001',
    wa_id       text not null,
    role        text not null check (role in ('user', 'assistant')),
    content     text not null,
    created_at  timestamptz not null default now()
);

-- Sliding-window query: ambil N pesan terakhir per nomor.
create index if not exists wa_messages_wa_id_created_idx
    on public.wa_messages (wa_id, created_at desc);
