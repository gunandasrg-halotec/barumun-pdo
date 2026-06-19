<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name'        => 'Admin',
                'code'        => 'ADMIN',
                'description' => 'Kelola user, master data, dan konfigurasi sistem. Tidak terlibat dalam approval PDO.',
            ],
            [
                'name'        => 'Kerani',
                'code'        => 'KERANI',
                'description' => 'Buat PDO Bulanan/Tambahan dan catat realisasi. Terikat satu unit kebun.',
            ],
            [
                'name'        => 'Asisten Kebun',
                'code'        => 'ASISTEN_KEBUN',
                'description' => 'Approval tahap pertama PDO. Terikat satu unit kebun.',
            ],
            [
                'name'        => 'Manajer Kebun',
                'code'        => 'MANAJER_KEBUN',
                'description' => 'Approval tahap kedua (paralel). Berkedudukan di HO, akses semua unit.',
            ],
            [
                'name'        => 'Manajer Keuangan',
                'code'        => 'MANAJER_KEUANGAN',
                'description' => 'Approval tahap kedua (paralel), catat transfer, tutup PDO. HO, akses semua unit.',
            ],
            [
                'name'        => 'Staff Keuangan',
                'code'        => 'STAFF_KEUANGAN',
                'description' => 'Catat transfer dana. HO, akses semua unit.',
            ],
            [
                'name'        => 'Direktur Keuangan',
                'code'        => 'DIREKTUR_KEUANGAN',
                'description' => 'Approval final PDO. HO, akses semua unit.',
            ],
            [
                'name'        => 'Staff Purchasing',
                'code'        => 'STAFF_PURCHASING',
                'description' => 'Catat realisasi dengan sumber dana Rekening Utama Perusahaan saja. HO.',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['code' => $role['code']],
                array_merge($role, ['created_at' => now()])
            );
        }

        $this->command->info('✅ Roles seeded: ' . count($roles) . ' roles');
    }
}
