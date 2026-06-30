# AKAPACK WhatsApp Bot — "Mbak Aka"

Bot auto-reply WhatsApp AI untuk AKAPACK (grosir B2B kemasan plastik & mesin,
cabang Bandung & Garut). Arsitektur **async wajib**: webhook hanya menerima &
antri, semua proses berat (Claude, query Supabase) di worker terpisah.

```
WhatsApp Cloud API (webhook)
        │
   ┌────▼─────┐  verifikasi tanda tangan, dedup message_id,
   │ RECEIVER │  enqueue, balas 200 (<5 dtk). TANPA Claude.
   └────┬─────┘  → bot/public/webhook.php
        │   (Redis / file queue)
   ┌────▼─────┐  debounce 3–5 dtk → ROUTER (mode BOT/HUMAN,
   │  WORKER  │  kata kunci "admin"/"cs" = eskalasi) → Handler → Send API
   └──────────┘  → bin/worker.php (daemon)
```

## Status fase

| Fase | Isi | Status |
|------|-----|--------|
| **0** | Echo-bot + queue async **at-least-once** (receiver, worker, debounce, dedup idempotent, retry, eskalasi, mode HUMAN) | ✅ selesai |
| **1** | `ClaudeHandler` — FAQ via Claude API (SDK resmi, adaptive thinking, effort medium, prompt caching, anti-refusal) | ✅ selesai |
| **2** | `ClaudeToolHandler` — tools function-call Supabase (cari_produk/cek_stok/cek_harga/daftar_kategori) + guardrail cost_price | ✅ selesai |
| **3** | Memori percakapan (sliding-window, Supabase `wa_messages`) + `eskalasi_ke_admin` per-cabang (intent+ringkasan+riwayat) | ✅ selesai |

**Otak AI** bisa **Gemini** (default) atau **Claude** — pilih via `LLM_PROVIDER`
(`gemini`/`claude`/kosong=auto). Keduanya di belakang seam `LlmClient`/`ToolRunner`
yang sama, jadi tools/memori/eskalasi identik.

Handler dipilih otomatis di `src/Bot.php`:
- **ClaudeToolHandler** (Fase 2/3) bila ada API key LLM **dan** `SUPABASE_URL`/`SUPABASE_ANON_KEY` → jawab produk/stok/harga real-time + tools + eskalasi.
- **ClaudeHandler** (Fase 1) bila hanya LLM yang terisi → FAQ saja.
- **EchoHandler** (Fase 0) bila tak ada API key → dev.

Gemini default `gemini-2.5-flash` (`GEMINI_MODEL`); Claude default `claude-sonnet-4-6`
(`CLAUDE_MODEL`). Nama kelas masih `Claude*` tapi provider-agnostik (terima interface).

### Coba cepat — chat lokal (tanpa WhatsApp/VPS)

Isi `bot/.env`: `GEMINI_API_KEY` (dari https://aistudio.google.com/apikey) +
`SUPABASE_URL`/`SUPABASE_ANON_KEY`, lalu:

```bash
php tools/chat.php
```

Ngobrol sama Mbak Aka di terminal (cek produk/stok/harga real-time). Cara tercepat
memastikan otak bot jalan sebelum deploy.

> **Guardrail cost_price (kritis):** RLS internal OFF → anon key BISA baca `cost_price`.
> Karena itu `SupabaseClient` menolak query yang menyebut kolom modal DAN men-scrub
> `cost_price/buy_price/modal/margin` rekursif dari semua baris. Tool tidak pernah
> SELECT kolom modal. Diuji end-to-end (termasuk `select=*` → tetap ter-scrub).

## Struktur

```
bot/
├─ public/webhook.php   # RECEIVER (titik masuk webhook)
├─ bin/worker.php       # WORKER daemon (--once / --drain untuk test/cron)
├─ tools/simulate.php   # uji integrasi pipeline tanpa kredensial
├─ src/
│  ├─ Receiver.php      # verifikasi sig, dedup, enqueue
│  ├─ Worker.php        # debounce loop, router, eskalasi
│  ├─ WhatsApp.php      # Send API (driver api|log)
│  ├─ Store/            # Store interface + RedisStore + FileStore
│  ├─ Handler/          # Handler interface + EchoHandler (Fase 0)
│  ├─ Config.php Env.php Logger.php Bot.php Response.php
├─ .env.example
└─ composer.json
```

## Setup

```bash
cd bot
cp .env.example .env        # isi token WA + (nanti) Claude/Supabase
composer install            # untuk driver Redis (predis). Driver file jalan tanpa ini.
```

Isi `.env` minimal untuk produksi:
`WA_VERIFY_TOKEN`, `WA_APP_SECRET`, `WA_ACCESS_TOKEN`, `WA_PHONE_NUMBER_ID`,
`QUEUE_DRIVER=redis`, `REDIS_DSN`.

> **Keamanan (fail-closed):** receiver memverifikasi `X-Hub-Signature-256`. Bila
> `WA_APP_SECRET` kosong, webhook POST **ditolak 403** — kecuali kamu set
> `WA_ALLOW_INSECURE_WEBHOOK=1` secara eksplisit (HANYA untuk dev). Jadi lupa
> mengisi secret di produksi = bot menolak semua pesan, bukan menerima yang palsu.

## Worker sebagai service (systemd, VPS)

`/etc/systemd/system/akapack-bot.service`:

```ini
[Unit]
Description=AKAPACK WhatsApp Bot worker
After=network.target redis-server.service

[Service]
Type=simple
WorkingDirectory=/var/www/akapack/bot
ExecStart=/usr/bin/php /var/www/akapack/bot/bin/worker.php
Restart=always
RestartSec=2
User=www-data

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now akapack-bot
sudo journalctl -u akapack-bot -f
```

Worker menangani `SIGTERM`/`SIGINT` untuk shutdown rapi (`systemctl stop` aman).

## Webhook (nginx + php-fpm)

Arahkan Meta webhook ke `https://DOMAIN/bot/public/webhook.php`
(atau ke `whatsapp_bot.php` lama — sudah jadi shim ke file yang sama).

Di Meta App → WhatsApp → Configuration:
- **Callback URL**: URL di atas
- **Verify token**: sama dengan `WA_VERIFY_TOKEN`
- Subscribe field **messages**.

## Uji lokal (tanpa kredensial)

```bash
php tools/simulate.php
```

Menjalankan 22 assertion: debounce menggabungkan pesan beruntun; dedup
idempotent (Meta kirim ulang `message_id` → tidak ada balasan ganda); "cs"/"admin"
memicu eskalasi walau digabung dengan teks lain (mode → HUMAN, bot diam); **retry**
saat kirim gagal (buffer ditahan, dicoba lagi); dan keamanan tanda tangan
(tanpa/ salah signature → 403, valid → 200, fail-closed saat secret kosong).
Pesan keluar ditulis ke `var/test/outgoing.log` (driver `log`, bukan kirim sungguhan).

Uji Fase 1 (ClaudeHandler) tanpa kredensial — pakai LLM palsu:

```bash
php tools/test_claude.php
```

Memverifikasi passthrough balasan, fallback saat refusal/empty (arahkan *admin*),
input kosong, dan integrasi penuh via Worker. Untuk produksi, isi `ANTHROPIC_API_KEY`
di `.env` lalu bot otomatis memakai Claude.

Uji Fase 2 (tools Supabase) — unit + **live** ke Supabase asli:

```bash
php tools/test_supabase.php
```

Bagian unit memakai DB palsu (tier harga, mapping cabang, guardrail cost_price).
Bagian LIVE konek anon key dari `~/Documents/akapack/.env.local` dan menguji
`cari_produk/cek_stok/cek_harga/daftar_kategori` + memastikan `cost_price` tak bocor.

Uji Fase 3 (memori + eskalasi) tanpa kredensial:

```bash
php tools/test_memory.php
```

Memverifikasi memori sliding-window, riwayat diumpan ke LLM, eskalasi per-cabang
(intent+ringkasan+riwayat ke admin yang tepat), dan tool `eskalasi_ke_admin`.

### Mengaktifkan memori percakapan (Supabase)

Jalankan SEKALI di **Supabase SQL Editor**: isi `sql/wa_messages.sql`
(buat tabel `wa_messages` + index). Bot **degrade dengan aman** bila tabel belum
ada — balasan tetap jalan, hanya tanpa memori. Isi juga `ADMIN_WA_BANDUNG` /
`ADMIN_WA_GARUT` di `.env` supaya handoff dikirim ke admin cabang yang tepat.

## Keandalan (at-least-once)

Receiver TIDAK menandai pesan "sudah dibalas". Ia hanya `appendBuffer` +
`scheduleDue`, lalu selalu balas 200 (kecuali tanda tangan tak valid → 403).
Worker membaca buffer **non-destruktif** (`peekBuffer`), dan **hanya setelah
balasan terkirim sukses** ia menandai `markSeen(message_id)` + `ackBuffer`.

Konsekuensinya:
- **Gagal kirim / crash** → buffer tetap utuh, due di-arm ulang
  (`RETRY_BACKOFF_SECONDS`) → dicoba lagi sampai sukses atau usia melewati
  `MAX_RETRY_AGE_SECONDS` (lalu menyerah + log).
- **Meta kirim ulang webhook** (umum saat balasan 200 telat) → fragmen di-append
  lagi, tapi worker melewati `message_id` yang sudah dibalas → **tidak ada
  balasan ganda**.
- Trade-off: prioritas "tidak ada pesan hilang" di atas "tidak ada duplikat".
  Pada kegagalan jaringan langka, satu balasan bisa terkirim dua kali.

## Catatan operasional

- **Queue Redis** disarankan di produksi (debounce via sorted set, dedup atomik,
  aman multi-worker). **File queue** cocok dev / volume kecil (1 worker).
- Mode `HUMAN` tidak auto-reset — admin melepas dengan mereset
  `wa:mode:<nomor>` (Redis) / `var/modes.json` (file). Pelepasan via perintah
  admin dibuat di Fase 3.
- Jendela 24 jam: balasan ke pesan masuk dalam 24 jam gratis & bebas template.
