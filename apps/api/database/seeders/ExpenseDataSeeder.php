<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed master data biaya untuk PT Barumun Palma Nauli.
 * Struktur: Kategori → Sub-Kategori → Item Biaya
 * Item bertanda is_routine=true akan otomatis masuk template PDO Bulanan.
 */
class ExpenseDataSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = DB::table('companies')->where('code', 'BPN')->value('id');

        if (! $companyId) {
            $this->command->error('❌ Company BPN tidak ditemukan. Jalankan DatabaseSeeder terlebih dahulu.');
            return;
        }

        $now = now();

        // ─── STRUKTUR MASTER DATA ────────────────────────────────────────────
        //
        // Format:
        // [ kategori_code, nama_kategori, include_in_recap, display_order,
        //   subcategories => [
        //     [ sub_code, nama_sub, display_order,
        //       items => [
        //         [ item_code, nama_item, account, unit, rate, is_routine ]
        //       ]
        //     ]
        //   ]
        // ]

        $masterData = [
            // ── 1. BIAYA PEMELIHARAAN TANAMAN ────────────────────────────────
            [
                'code'            => 'BPT',
                'name'            => 'Biaya Pemeliharaan Tanaman',
                'include_in_recap'=> true,
                'display_order'   => 1,
                'subcategories'   => [
                    [
                        'code'         => 'BPT-PUK',
                        'name'         => 'Pemupukan',
                        'display_order'=> 1,
                        'items'        => [
                            ['BPT-PUK-001', 'Pupuk Urea', '5.1.1.001', 'Kg', 0, true],
                            ['BPT-PUK-002', 'Pupuk MOP (KCl)', '5.1.1.002', 'Kg', 0, true],
                            ['BPT-PUK-003', 'Pupuk CIRP / RP', '5.1.1.003', 'Kg', 0, true],
                            ['BPT-PUK-004', 'Pupuk Borate', '5.1.1.004', 'Kg', 0, false],
                            ['BPT-PUK-005', 'Upah Aplikasi Pupuk', '5.1.1.005', 'Hari', 0, true],
                        ],
                    ],
                    [
                        'code'         => 'BPT-HPT',
                        'name'         => 'Pengendalian Hama dan Penyakit',
                        'display_order'=> 2,
                        'items'        => [
                            ['BPT-HPT-001', 'Herbisida Sistemik', '5.1.2.001', 'Liter', 0, true],
                            ['BPT-HPT-002', 'Herbisida Kontak', '5.1.2.002', 'Liter', 0, false],
                            ['BPT-HPT-003', 'Insektisida', '5.1.2.003', 'Liter', 0, false],
                            ['BPT-HPT-004', 'Upah Semprot', '5.1.2.004', 'Hari', 0, true],
                        ],
                    ],
                    [
                        'code'         => 'BPT-PGW',
                        'name'         => 'Pengendalian Gulma',
                        'display_order'=> 3,
                        'items'        => [
                            ['BPT-PGW-001', 'Upah Dongkel Anak Kayu', '5.1.3.001', 'Hari', 0, true],
                            ['BPT-PGW-002', 'Upah Babat Piringan', '5.1.3.002', 'Hari', 0, true],
                        ],
                    ],
                ],
            ],

            // ── 2. BIAYA PANEN ───────────────────────────────────────────────
            [
                'code'            => 'BPN',
                'name'            => 'Biaya Panen',
                'include_in_recap'=> true,
                'display_order'   => 2,
                'subcategories'   => [
                    [
                        'code'         => 'BPN-UPH',
                        'name'         => 'Upah Panen',
                        'display_order'=> 1,
                        'items'        => [
                            ['BPN-UPH-001', 'Upah Potong Buah (TBS)', '5.2.1.001', 'Ton', 0, true],
                            ['BPN-UPH-002', 'Upah Angkut Janjang', '5.2.1.002', 'Ton', 0, true],
                            ['BPN-UPH-003', 'Upah Kutip Brondolan', '5.2.1.003', 'Hari', 0, true],
                        ],
                    ],
                    [
                        'code'         => 'BPN-ANC',
                        'name'         => 'Ancak dan Peralatan Panen',
                        'display_order'=> 2,
                        'items'        => [
                            ['BPN-ANC-001', 'Dodos / Chisel', '5.2.2.001', 'Unit', 0, false],
                            ['BPN-ANC-002', 'Egrek', '5.2.2.002', 'Unit', 0, false],
                            ['BPN-ANC-003', 'Gancu', '5.2.2.003', 'Unit', 0, false],
                        ],
                    ],
                ],
            ],

            // ── 3. BIAYA TRANSPORTASI ────────────────────────────────────────
            [
                'code'            => 'BTR',
                'name'            => 'Biaya Transportasi',
                'include_in_recap'=> true,
                'display_order'   => 3,
                'subcategories'   => [
                    [
                        'code'         => 'BTR-BBM',
                        'name'         => 'Bahan Bakar',
                        'display_order'=> 1,
                        'items'        => [
                            ['BTR-BBM-001', 'Solar Operasional', '5.3.1.001', 'Liter', 0, true],
                            ['BTR-BBM-002', 'Bensin Operasional', '5.3.1.002', 'Liter', 0, true],
                            ['BTR-BBM-003', 'Pelumas / Oli Mesin', '5.3.1.003', 'Liter', 0, true],
                        ],
                    ],
                    [
                        'code'         => 'BTR-KND',
                        'name'         => 'Sewa Kendaraan',
                        'display_order'=> 2,
                        'items'        => [
                            ['BTR-KND-001', 'Sewa Truk Angkut TBS', '5.3.2.001', 'Rit', 0, true],
                            ['BTR-KND-002', 'Sewa Mobil Operasional', '5.3.2.002', 'Hari', 0, false],
                        ],
                    ],
                ],
            ],

            // ── 4. BIAYA UMUM DAN ADMINISTRASI ──────────────────────────────
            [
                'code'            => 'BUA',
                'name'            => 'Biaya Umum dan Administrasi',
                'include_in_recap'=> true,
                'display_order'   => 4,
                'subcategories'   => [
                    [
                        'code'         => 'BUA-ADM',
                        'name'         => 'Administrasi Kantor',
                        'display_order'=> 1,
                        'items'        => [
                            ['BUA-ADM-001', 'Alat Tulis Kantor (ATK)', '5.4.1.001', 'Paket', 0, true],
                            ['BUA-ADM-002', 'Fotokopi dan Percetakan', '5.4.1.002', 'Paket', 0, true],
                            ['BUA-ADM-003', 'Materai', '5.4.1.003', 'Lembar', 10000, false],
                        ],
                    ],
                    [
                        'code'         => 'BUA-UTL',
                        'name'         => 'Utilitas',
                        'display_order'=> 2,
                        'items'        => [
                            ['BUA-UTL-001', 'Listrik Kantor Kebun', '5.4.2.001', 'Bulan', 0, true],
                            ['BUA-UTL-002', 'Air Bersih / PDAM', '5.4.2.002', 'Bulan', 0, true],
                            ['BUA-UTL-003', 'Internet dan Komunikasi', '5.4.2.003', 'Bulan', 0, true],
                        ],
                    ],
                    [
                        'code'         => 'BUA-SOC',
                        'name'         => 'Sosial dan Keamanan',
                        'display_order'=> 3,
                        'items'        => [
                            ['BUA-SOC-001', 'Biaya Keamanan Kebun', '5.4.3.001', 'Bulan', 0, true],
                            ['BUA-SOC-002', 'Sumbangan / Sosial Kemasyarakatan', '5.4.3.002', 'Kali', 0, false],
                        ],
                    ],
                ],
            ],

            // ── 5. BIAYA INFRASTRUKTUR DAN PEMELIHARAAN ─────────────────────
            [
                'code'            => 'BIP',
                'name'            => 'Biaya Infrastruktur dan Pemeliharaan',
                'include_in_recap'=> true,
                'display_order'   => 5,
                'subcategories'   => [
                    [
                        'code'         => 'BIP-JAL',
                        'name'         => 'Pemeliharaan Jalan dan Jembatan',
                        'display_order'=> 1,
                        'items'        => [
                            ['BIP-JAL-001', 'Material Perbaikan Jalan (Batu/Sirtu)', '5.5.1.001', 'M3', 0, false],
                            ['BIP-JAL-002', 'Upah Perbaikan Jalan', '5.5.1.002', 'Hari', 0, false],
                        ],
                    ],
                    [
                        'code'         => 'BIP-BNG',
                        'name'         => 'Pemeliharaan Bangunan',
                        'display_order'=> 2,
                        'items'        => [
                            ['BIP-BNG-001', 'Material Bangunan', '5.5.2.001', 'Paket', 0, false],
                            ['BIP-BNG-002', 'Upah Tukang', '5.5.2.002', 'Hari', 0, false],
                        ],
                    ],
                ],
            ],

            // ── 6. BIAYA LAIN-LAIN ───────────────────────────────────────────
            [
                'code'            => 'BLL',
                'name'            => 'Biaya Lain-Lain',
                'include_in_recap'=> false,
                'display_order'   => 6,
                'subcategories'   => [
                    [
                        'code'         => 'BLL-KLN',
                        'name'         => 'Biaya Tidak Terduga',
                        'display_order'=> 1,
                        'items'        => [
                            ['BLL-KLN-001', 'Pengeluaran Tidak Terduga', '5.6.1.001', 'Paket', 0, false],
                        ],
                    ],
                ],
            ],
        ];

        $totalKategori    = 0;
        $totalSubkategori = 0;
        $totalItem        = 0;
        $totalRoutine     = 0;

        foreach ($masterData as $katData) {
            // ── Upsert Kategori ──────────────────────────────────────────────
            $categoryId = $this->stableUpsert(
                'expense_categories',
                ['company_id' => $companyId, 'code' => $katData['code']],
                [
                    'name'            => $katData['name'],
                    'display_order'   => $katData['display_order'],
                    'include_in_recap'=> $katData['include_in_recap'],
                    'is_active'       => true,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]
            );
            $totalKategori++;

            foreach ($katData['subcategories'] as $subData) {
                // ── Upsert Sub-Kategori ──────────────────────────────────────
                $subcategoryId = $this->stableUpsert(
                    'expense_subcategories',
                    ['category_id' => $categoryId, 'code' => $subData['code']],
                    [
                        'name'         => $subData['name'],
                        'display_order'=> $subData['display_order'],
                        'is_active'    => true,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]
                );
                $totalSubkategori++;

                foreach ($subData['items'] as [$code, $name, $account, $unit, $rate, $isRoutine]) {
                    // ── Upsert Item Biaya ────────────────────────────────────
                    $this->stableUpsert(
                        'expense_items',
                        ['subcategory_id' => $subcategoryId, 'code' => $code],
                        [
                            'name'                   => $name,
                            'default_account_number' => $account,
                            'default_unit'           => $unit,
                            'default_rate'           => $rate,
                            'mode_input'             => 'manual',
                            'is_routine'             => $isRoutine,
                            'is_active'              => true,
                            'created_at'             => $now,
                            'updated_at'             => $now,
                        ]
                    );
                    $totalItem++;
                    if ($isRoutine) $totalRoutine++;
                }
            }
        }

        $this->command->info("✅ Kategori:    $totalKategori");
        $this->command->info("✅ Sub-Kategori: $totalSubkategori");
        $this->command->info("✅ Item Biaya:   $totalItem (Rutin: $totalRoutine)");
    }

    private function stableUpsert(string $table, array $match, array $values): string
    {
        $existingId = DB::table($table)->where($match)->value('id');

        if ($existingId) {
            $updateValues = $values;
            unset($updateValues['id'], $updateValues['created_at']);

            DB::table($table)->where('id', $existingId)->update($updateValues);

            return $existingId;
        }

        $id = (string) Str::uuid();

        DB::table($table)->insert(array_merge($match, $values, ['id' => $id]));

        return $id;
    }
}
