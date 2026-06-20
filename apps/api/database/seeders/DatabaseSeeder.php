<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🌱 Memulai database seeding...');

        // 1. Perusahaan
        $companyId = (string) Str::uuid();
        DB::table('companies')->updateOrInsert(
            ['code' => 'BPN'],
            [
                'id'         => $companyId,
                'name'       => 'PT Barumun Palma Nauli',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        // Ambil ID yang mungkin sudah ada
        $companyId = DB::table('companies')->where('code', 'BPN')->value('id');
        $this->command->info('✅ Company: PT Barumun Palma Nauli');

        // 2. Unit Kebun (4 unit resmi)
        $units = [
            ['code' => 'KP', 'name' => 'Kebun Kota Pinang'],
            ['code' => 'BN', 'name' => 'Binanga'],
            ['code' => 'JM', 'name' => 'Janji Matogu'],
            ['code' => 'SS', 'name' => 'Sosa'],
        ];
        foreach ($units as $unit) {
            DB::table('plantation_units')->updateOrInsert(
                ['code' => $unit['code']],
                [
                    'id'         => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'name'       => $unit['name'],
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->command->info('✅ Plantation units: KP, BN, JM, SS');

        // 3. Roles
        $this->call(RoleSeeder::class);

        // 4. Admin user default (GANTI PASSWORD sebelum production!)
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $companyIdForAdmin = DB::table('companies')->value('id');
        DB::table('users')->updateOrInsert(
            ['email' => 'admin@barumunpalma.co.id'],
            [
                'id'                 => (string) Str::uuid(),
                'role_id'            => $adminRoleId,
                'plantation_unit_id' => null,
                'company_id'         => $companyIdForAdmin,
                'full_name'          => 'System Administrator',
                'email'              => 'admin@barumunpalma.co.id',
                'password_hash'      => Hash::make('ChangeMe123!'),  // ⚠️ WAJIB GANTI
                'whatsapp_number'    => '628000000000',              // ⚠️ GANTI dengan nomor asli
                'is_active'          => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]
        );
        $this->command->info('✅ Admin user: admin@barumunpalma.co.id (⚠️  GANTI PASSWORD!)');

        // 5. System Settings
        $this->call(SystemSettingSeeder::class);

        // 6. Notification Templates
        $this->call(NotificationTemplateSeeder::class);

        // 7. Master Data Biaya (Kategori → Sub-Kategori → Item Biaya)
        $this->call(ExpenseDataSeeder::class);

        $this->command->newLine();
        $this->command->info('🎉 Database seeding selesai!');
        $this->command->newLine();
        $this->command->warn('⚠️  LANGKAH SELANJUTNYA:');
        $this->command->line('   1. Ganti password admin via: php artisan tinker');
        $this->command->line('   2. Update nomor WhatsApp admin di tabel users');
        $this->command->line('   3. Sesuaikan item biaya dengan data Finance aktual (ExpenseDataSeeder hanya template)');
        $this->command->line('   4. Buat user accounts untuk setiap kebun');
        $this->command->line('   5. Konfigurasi WhatsApp Gateway di halaman Pengaturan');
    }
}
