<?php

declare(strict_types=1);

namespace Akapack\Bot\Supabase;

use Akapack\Bot\Tool\ToolExecutor;

/**
 * 4 tool Supabase untuk Claude (Fase 2). Semua READ-ONLY & jual-saja.
 * cost_price/modal TIDAK PERNAH di-SELECT di sini (dan di-scrub di SupabaseClient).
 */
final class SupabaseTools implements ToolExecutor
{
    public function __construct(
        private readonly Db $db,
        private readonly string $tenantId,
        private readonly string $outletBandung,
        private readonly string $outletGarut,
    ) {
    }

    /** Definisi tool (name/description/inputSchema) untuk dikirim ke Claude. */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'cari_produk',
                'description' => 'Cari produk berdasarkan nama (boleh sebagian). '
                    . 'Panggil saat pelanggan menyebut/menanyakan suatu barang untuk mendapat id, nama, sku, satuan, kategori. '
                    . 'WAJIB dipanggil sebelum cek_stok / cek_harga karena keduanya butuh product_id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'nama' => ['type' => 'string', 'description' => 'Kata kunci nama produk, mis. "plastik PE" atau "sealer"'],
                        'kategori' => ['type' => 'string', 'description' => 'Opsional: saring per nama kategori'],
                    ],
                    'required' => ['nama'],
                ],
            ],
            [
                'name' => 'cek_stok',
                'description' => 'Cek stok sebuah produk per cabang. Panggil saat pelanggan tanya ketersediaan/ready/sisa stok. '
                    . 'Butuh product_id dari cari_produk.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'string', 'description' => 'UUID produk dari cari_produk'],
                        'cabang' => ['type' => 'string', 'enum' => ['bandung', 'garut', 'semua'], 'description' => 'Default "semua"'],
                    ],
                    'required' => ['product_id'],
                ],
            ],
            [
                'name' => 'cek_harga',
                'description' => 'Cek harga jual sebuah produk untuk jumlah tertentu (memperhitungkan harga grosir/tier). '
                    . 'Panggil saat pelanggan tanya harga/berapaan untuk qty tertentu. Butuh product_id dari cari_produk. '
                    . 'Jika hasil butuh_admin=true, arahkan pelanggan ke admin (qty di atas tier tertinggi).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'string', 'description' => 'UUID produk dari cari_produk'],
                        'qty' => ['type' => 'integer', 'description' => 'Jumlah yang mau dibeli (minimal 1)'],
                    ],
                    'required' => ['product_id', 'qty'],
                ],
            ],
            [
                'name' => 'daftar_kategori',
                'description' => 'Daftar semua kategori produk yang tersedia. Panggil saat pelanggan tanya "jual apa aja" / kategori.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];
    }

    public function execute(string $name, array $input): string
    {
        $result = match ($name) {
            'cari_produk' => $this->cariProduk((string) ($input['nama'] ?? ''), isset($input['kategori']) ? (string) $input['kategori'] : null),
            'cek_stok' => $this->cekStok((string) ($input['product_id'] ?? ''), (string) ($input['cabang'] ?? 'semua')),
            'cek_harga' => $this->cekHarga((string) ($input['product_id'] ?? ''), (int) ($input['qty'] ?? 1)),
            'daftar_kategori' => $this->daftarKategori(),
            default => ['error' => "tool tidak dikenal: {$name}"],
        };

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function cariProduk(string $nama, ?string $kategori): array
    {
        if (trim($nama) === '') {
            return ['error' => 'nama produk kosong'];
        }

        $query = [
            'tenant_id' => 'eq.' . $this->tenantId,
            'name' => 'ilike.*' . $nama . '*',
            'select' => 'id,name,sku,unit,category:categories(name)',
            'order' => 'name',
            'limit' => 8,
        ];

        if ($kategori !== null && trim($kategori) !== '') {
            $cats = $this->db->select('categories', [
                'tenant_id' => 'eq.' . $this->tenantId,
                'name' => 'ilike.*' . $kategori . '*',
                'select' => 'id',
                'limit' => 20,
            ]);
            $ids = array_values(array_filter(array_map(static fn ($c) => $c['id'] ?? null, $cats)));
            if ($ids === []) {
                return ['produk' => [], 'catatan' => "kategori '{$kategori}' tidak ditemukan"];
            }
            $query['category_id'] = 'in.(' . implode(',', $ids) . ')';
        }

        $rows = $this->db->select('products', $query);
        $produk = array_map(static function (array $r): array {
            return [
                'id' => $r['id'] ?? null,
                'nama' => $r['name'] ?? null,
                'sku' => $r['sku'] ?? null,
                'satuan' => $r['unit'] ?? null,
                'kategori' => $r['category']['name'] ?? null,
            ];
        }, $rows);

        return ['produk' => $produk, 'jumlah' => count($produk)];
    }

    private function cekStok(string $productId, string $cabang): array
    {
        if (trim($productId) === '') {
            return ['error' => 'product_id kosong'];
        }

        $query = ['product_id' => 'eq.' . $productId, 'select' => 'outlet_id,stock'];
        $cabang = strtolower($cabang);
        if ($cabang === 'bandung') {
            $query['outlet_id'] = 'eq.' . $this->outletBandung;
        } elseif ($cabang === 'garut') {
            $query['outlet_id'] = 'eq.' . $this->outletGarut;
        }

        $rows = $this->db->select('inventory', $query);
        $stok = array_map(fn (array $r): array => [
            'cabang' => $this->outletLabel((string) ($r['outlet_id'] ?? '')),
            'stok' => (int) ($r['stock'] ?? 0),
        ], $rows);

        return ['stok' => $stok];
    }

    private function cekHarga(string $productId, int $qty): array
    {
        if (trim($productId) === '') {
            return ['error' => 'product_id kosong'];
        }
        $qty = max(1, $qty);

        $rows = $this->db->select('products', [
            'id' => 'eq.' . $productId,
            'select' => 'name,unit,units,price,price_market,price_online,price_tiers',
            'limit' => 1,
        ]);
        if ($rows === []) {
            return ['error' => 'produk tidak ditemukan'];
        }
        $p = $rows[0];

        $base = (float) ($p['price'] ?? 0);
        $tiers = is_array($p['price_tiers'] ?? null) ? $p['price_tiers'] : [];
        usort($tiers, static fn ($a, $b) => ((int) ($a['min_qty'] ?? 0)) <=> ((int) ($b['min_qty'] ?? 0)));

        $hargaSatuan = $base;
        $tierTerpakai = null;
        $maxMinQty = 0;
        foreach ($tiers as $t) {
            $minQty = (int) ($t['min_qty'] ?? 0);
            $maxMinQty = max($maxMinQty, $minQty);
            if ($qty >= $minQty) {
                $hargaSatuan = (float) ($t['price'] ?? $hargaSatuan);
                $tierTerpakai = ['min_qty' => $minQty, 'price' => (float) ($t['price'] ?? 0)];
            }
        }

        // qty di atas tier tertinggi → butuh nego admin
        $butuhAdmin = ($tiers !== [] && $qty > $maxMinQty);

        return [
            'nama' => $p['name'] ?? null,
            'satuan' => $p['unit'] ?? null,
            'qty' => $qty,
            'harga_satuan' => $hargaSatuan,
            'subtotal' => $hargaSatuan * $qty,
            'tier_terpakai' => $tierTerpakai,
            'butuh_admin' => $butuhAdmin,
            'harga_market' => $p['price_market'] ?? null,
            'harga_online' => $p['price_online'] ?? null,
            'satuan_lain' => $p['units'] ?? [],
        ];
    }

    private function daftarKategori(): array
    {
        $rows = $this->db->select('categories', [
            'tenant_id' => 'eq.' . $this->tenantId,
            'is_active' => 'eq.true',
            'select' => 'name',
            'order' => 'sort_order',
            'limit' => 200,
        ]);
        $names = array_values(array_filter(array_map(static fn ($r) => $r['name'] ?? null, $rows)));
        return ['kategori' => $names, 'jumlah' => count($names)];
    }

    private function outletLabel(string $outletId): string
    {
        return match ($outletId) {
            $this->outletBandung => 'Bandung',
            $this->outletGarut => 'Garut',
            default => 'Lainnya',
        };
    }
}
