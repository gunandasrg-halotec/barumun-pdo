<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aktifkan ekstensi UUID
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');

        // ─────────────────────────────────────────
        // 1. COMPANIES
        // ─────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 20)->unique();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->comment('Data perusahaan. Multi-tenant ready.');
        });

        // ─────────────────────────────────────────
        // 2. PLANTATION_UNITS
        // ─────────────────────────────────────────
        Schema::create('plantation_units', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 10)->unique();  // KP, BN, JM, SS
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->comment('Unit kebun. Kode resmi: KP, BN, JM, SS.');
        });

        // ─────────────────────────────────────────
        // 3. ROLES
        // ─────────────────────────────────────────
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 100)->unique();
            $table->string('code', 50)->unique();  // KERANI, ASISTEN_KEBUN, dst.
            $table->text('description')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->comment('Daftar role statis. Di-seed saat inisialisasi.');
        });

        // ─────────────────────────────────────────
        // 4. USERS
        // ─────────────────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignUuid('plantation_unit_id')->nullable()->constrained('plantation_units')->restrictOnDelete();
            $table->string('full_name', 255);
            $table->string('email', 255)->unique();
            $table->string('password_hash', 255);
            $table->string('whatsapp_number', 20);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz('deleted_at');  // Soft delete

            $table->comment('Akun pengguna. plantation_unit_id NULL untuk role lintas unit.');
            $table->index(['plantation_unit_id'], 'idx_users_plantation_unit');
            $table->index(['role_id', 'is_active'], 'idx_users_role_active');
        });

        // Personal access tokens untuk Sanctum
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // ─────────────────────────────────────────
        // 5. EXPENSE_CATEGORIES
        // ─────────────────────────────────────────
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 255);
            $table->integer('display_order')->default(0);
            $table->boolean('include_in_recap')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz('deleted_at');

            $table->unique(['company_id', 'code'], 'uq_expense_categories_code');
            $table->comment('Kategori Biaya level 1 (A, B, C, ...).');
        });

        DB::statement("CREATE INDEX idx_expense_categories_company_active ON expense_categories(company_id, display_order) WHERE is_active = TRUE AND deleted_at IS NULL");

        // ─────────────────────────────────────────
        // 6. EXPENSE_SUBCATEGORIES
        // ─────────────────────────────────────────
        Schema::create('expense_subcategories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 255);
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz('deleted_at');

            $table->unique(['category_id', 'code'], 'uq_expense_subcategories_code');
            $table->comment('Sub-Kategori Biaya level 2.');
        });

        DB::statement("CREATE INDEX idx_expense_subcategories_category_active ON expense_subcategories(category_id, display_order) WHERE is_active = TRUE AND deleted_at IS NULL");

        // ─────────────────────────────────────────
        // 7. EXPENSE_ITEMS
        // ─────────────────────────────────────────
        Schema::create('expense_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('subcategory_id')->constrained('expense_subcategories')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 255);
            $table->string('default_account_number', 50)->nullable();
            $table->string('default_unit', 50)->nullable();
            $table->bigInteger('default_rate')->nullable();
            $table->string('mode_input', 20)->default('manual');
            $table->boolean('is_routine')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz('deleted_at');

            $table->unique(['subcategory_id', 'code'], 'uq_expense_items_code');
            $table->comment('Item Biaya level 3. Snapshot ke pdo_details saat digunakan.');
        });

        DB::statement("ALTER TABLE expense_items ADD CONSTRAINT chk_expense_items_mode_input CHECK (mode_input IN ('manual', 'auto_external'))");
        DB::statement("ALTER TABLE expense_items ADD CONSTRAINT chk_expense_items_default_rate CHECK (default_rate >= 0)");
        DB::statement("CREATE INDEX idx_expense_items_subcategory_active ON expense_items(subcategory_id, display_order) WHERE is_active = TRUE AND deleted_at IS NULL");
        DB::statement("CREATE INDEX idx_expense_items_routine ON expense_items(subcategory_id) WHERE is_routine = TRUE AND is_active = TRUE AND deleted_at IS NULL");

        // ─────────────────────────────────────────
        // 8. SYSTEM_SETTINGS
        // ─────────────────────────────────────────
        Schema::create('system_settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value');
            $table->string('description', 255)->nullable();
            $table->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['company_id', 'key'], 'uq_system_settings_key');
            $table->comment('Konfigurasi sistem yang dapat diubah Admin tanpa deploy ulang.');
        });

        // ─────────────────────────────────────────
        // 9. PDO_HEADERS
        // ─────────────────────────────────────────
        Schema::create('pdo_headers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('plantation_unit_id')->constrained('plantation_units')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pdo_number', 50)->unique();
            $table->smallInteger('period_month');
            $table->smallInteger('period_year');
            $table->date('submission_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('closure_type', 10)->nullable();
            $table->date('closed_at')->nullable();
            $table->text('closure_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            // Constraint: satu PDO per unit per periode
            $table->unique(['plantation_unit_id', 'period_month', 'period_year'], 'uq_pdo_headers_unit_period');
            $table->comment('Header PDO Bulanan. UNIQUE per unit+periode.');
        });

        DB::statement("ALTER TABLE pdo_headers ADD CONSTRAINT chk_pdo_headers_period_month CHECK (period_month BETWEEN 1 AND 12)");
        DB::statement("ALTER TABLE pdo_headers ADD CONSTRAINT chk_pdo_headers_period_year CHECK (period_year BETWEEN 2020 AND 2099)");
        DB::statement("ALTER TABLE pdo_headers ADD CONSTRAINT chk_pdo_headers_status CHECK (status IN ('draft','submitted','reviewed_asisten','in_review_manager','in_review_direktur','final','closed'))");
        DB::statement("ALTER TABLE pdo_headers ADD CONSTRAINT chk_pdo_headers_closure_type CHECK (closure_type IN ('system','manual'))");
        DB::statement("CREATE INDEX idx_pdo_headers_unit_period ON pdo_headers(plantation_unit_id, period_year DESC, period_month DESC)");
        DB::statement("CREATE INDEX idx_pdo_headers_status_unit ON pdo_headers(status, plantation_unit_id)");
        DB::statement("CREATE INDEX idx_pdo_headers_company_period ON pdo_headers(company_id, period_year DESC, period_month DESC, status)");
        DB::statement("CREATE INDEX idx_pdo_headers_final_for_close ON pdo_headers(period_year, period_month) WHERE status = 'final'");
        DB::statement("CREATE INDEX idx_pdo_headers_created_by ON pdo_headers(created_by)");

        // ─────────────────────────────────────────
        // 10. PDO_SUPPLEMENTARY_HEADERS
        // ─────────────────────────────────────────
        Schema::create('pdo_supplementary_headers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('parent_pdo_header_id')->constrained('pdo_headers')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('plantation_unit_id')->constrained('plantation_units')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('pdo_number', 50)->unique();
            $table->smallInteger('period_month');
            $table->smallInteger('period_year');
            $table->date('submission_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestampTz('merged_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->comment('Header PDO Tambahan. Setelah approved Direktur, item masuk ke pdo_details PDO Bulanan.');
        });

        DB::statement("ALTER TABLE pdo_supplementary_headers ADD CONSTRAINT chk_pdot_status CHECK (status IN ('draft','submitted','reviewed_asisten','in_review_manager','in_review_direktur','final_merged','rejected'))");
        DB::statement("CREATE INDEX idx_pdo_supplementary_parent ON pdo_supplementary_headers(parent_pdo_header_id)");
        DB::statement("CREATE INDEX idx_pdo_supplementary_unit_period ON pdo_supplementary_headers(plantation_unit_id, period_year DESC, period_month DESC)");
        DB::statement("CREATE INDEX idx_pdo_supplementary_status ON pdo_supplementary_headers(status, plantation_unit_id) WHERE status NOT IN ('final_merged')");

        // ─────────────────────────────────────────
        // 11. PDO_DETAILS
        // ─────────────────────────────────────────
        Schema::create('pdo_details', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('pdo_header_id')->constrained('pdo_headers')->cascadeOnDelete();
            $table->foreignUuid('expense_item_id')->constrained('expense_items')->restrictOnDelete();
            $table->foreignUuid('source_pdo_supplementary_id')->nullable()->constrained('pdo_supplementary_headers')->restrictOnDelete();
            $table->string('account_number', 50)->nullable();   // snapshot
            $table->text('description');                         // wajib diisi
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 50)->nullable();              // snapshot
            $table->bigInteger('rate')->nullable();              // snapshot tarif
            $table->bigInteger('amount')->default(0);            // jumlah pengajuan
            $table->text('notes')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestampsTz();

            $table->comment('Baris item dalam PDO. Tarif, akun, satuan di-snapshot saat insert.');
        });

        DB::statement("ALTER TABLE pdo_details ADD CONSTRAINT chk_pdo_details_quantity CHECK (quantity >= 0)");
        DB::statement("ALTER TABLE pdo_details ADD CONSTRAINT chk_pdo_details_rate CHECK (rate >= 0)");
        DB::statement("ALTER TABLE pdo_details ADD CONSTRAINT chk_pdo_details_amount CHECK (amount >= 0)");
        DB::statement("CREATE INDEX idx_pdo_details_header_order ON pdo_details(pdo_header_id, display_order)");
        DB::statement("CREATE INDEX idx_pdo_details_header_item ON pdo_details(pdo_header_id, expense_item_id)");
        DB::statement("CREATE INDEX idx_pdo_details_supplementary_source ON pdo_details(source_pdo_supplementary_id) WHERE source_pdo_supplementary_id IS NOT NULL");
        DB::statement("CREATE INDEX idx_pdo_details_expense_item ON pdo_details(expense_item_id)");

        // ─────────────────────────────────────────
        // 12. PDO_SUPPLEMENTARY_DETAILS
        // ─────────────────────────────────────────
        Schema::create('pdo_supplementary_details', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('pdo_supplementary_header_id')->constrained('pdo_supplementary_headers')->cascadeOnDelete();
            $table->foreignUuid('expense_item_id')->constrained('expense_items')->restrictOnDelete();
            $table->string('account_number', 50)->nullable();
            $table->text('description');
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 50)->nullable();
            $table->bigInteger('rate')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->text('notes')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestampsTz();

            $table->comment('Item PDO Tambahan selama proses approval. Setelah merge, disalin ke pdo_details.');
        });

        DB::statement("ALTER TABLE pdo_supplementary_details ADD CONSTRAINT chk_pdot_details_amount CHECK (amount >= 0)");

        // ─────────────────────────────────────────
        // 13. PDO_APPROVAL_LOGS
        // ─────────────────────────────────────────
        Schema::create('pdo_approval_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('pdo_header_id')->constrained('pdo_headers')->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('approval_stage', 50);
            $table->string('action', 20);
            $table->text('reason')->nullable();
            $table->integer('sequence_number');
            $table->timestampTz('created_at')->useCurrent();

            $table->comment('Immutable approval log PDO Bulanan. Append-only.');
        });

        DB::statement("ALTER TABLE pdo_approval_logs ADD CONSTRAINT chk_pdo_approval_action CHECK (action IN ('submit','approve','reject','resubmit','close'))");
        DB::statement("CREATE INDEX idx_pdo_approval_logs_pdo_sequence ON pdo_approval_logs(pdo_header_id, sequence_number ASC)");
        DB::statement("CREATE INDEX idx_pdo_approval_logs_actor_date ON pdo_approval_logs(actor_user_id, created_at DESC)");
        DB::statement("CREATE INDEX idx_pdo_approval_logs_action ON pdo_approval_logs(pdo_header_id, action)");

        // ─────────────────────────────────────────
        // 14. PDO_SUPPLEMENTARY_APPROVAL_LOGS
        // ─────────────────────────────────────────
        Schema::create('pdo_supplementary_approval_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('pdo_supplementary_header_id')->constrained('pdo_supplementary_headers')->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('approval_stage', 50);
            $table->string('action', 20);
            $table->text('reason')->nullable();
            $table->integer('sequence_number');
            $table->timestampTz('created_at')->useCurrent();

            $table->comment('Approval log PDO Tambahan. Struktur identik dengan pdo_approval_logs.');
        });

        DB::statement("ALTER TABLE pdo_supplementary_approval_logs ADD CONSTRAINT chk_pdot_approval_action CHECK (action IN ('submit','approve','reject','resubmit'))");
        DB::statement("CREATE INDEX idx_pdo_supp_approval_logs_pdo ON pdo_supplementary_approval_logs(pdo_supplementary_header_id, sequence_number ASC)");
        DB::statement("CREATE INDEX idx_pdo_supp_approval_logs_actor ON pdo_supplementary_approval_logs(actor_user_id, created_at DESC)");

        // ─────────────────────────────────────────
        // 15. TRANSFER_ENTRIES (ERD v1.2 — recorded_by nullable)
        // ─────────────────────────────────────────
        Schema::create('transfer_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('pdo_detail_id')->constrained('pdo_details')->restrictOnDelete();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // NULL = entri sistem
            $table->string('entry_source', 10)->default('manual');
            $table->boolean('is_auto_generated')->default(false);
            $table->date('transfer_date');
            $table->bigInteger('amount');
            $table->string('reference_number', 100);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->comment('Entri transfer per item. recorded_by NULL untuk entri otomatis sistem saat PDO Final.');
        });

        DB::statement("ALTER TABLE transfer_entries ADD CONSTRAINT chk_transfer_entries_source CHECK (entry_source IN ('system','manual'))");
        DB::statement("ALTER TABLE transfer_entries ADD CONSTRAINT chk_transfer_entries_amount CHECK (amount > 0)");
        DB::statement("CREATE INDEX idx_transfer_entries_pdo_detail_date ON transfer_entries(pdo_detail_id, transfer_date ASC)");
        DB::statement("CREATE INDEX idx_transfer_entries_auto_generated ON transfer_entries(pdo_detail_id, is_auto_generated)");
        DB::statement("CREATE INDEX idx_transfer_entries_source ON transfer_entries(entry_source) WHERE entry_source = 'system'");
        DB::statement("CREATE INDEX idx_transfer_entries_recorded_by ON transfer_entries(recorded_by) WHERE recorded_by IS NOT NULL");

        // ─────────────────────────────────────────
        // 16. REALIZATION_ENTRIES
        // ─────────────────────────────────────────
        Schema::create('realization_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('pdo_detail_id')->constrained('pdo_details')->restrictOnDelete();
            $table->foreignUuid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->date('transaction_date');
            $table->bigInteger('amount');
            $table->string('payment_method', 20);
            $table->string('reference_number', 100);
            $table->string('funding_source', 30);
            $table->text('explanation')->nullable();
            $table->timestampsTz();

            $table->comment('Entri realisasi per item. Validasi kumulatif PDO dilakukan di aplikasi sebelum INSERT.');
        });

        DB::statement("ALTER TABLE realization_entries ADD CONSTRAINT chk_realization_amount CHECK (amount > 0)");
        DB::statement("ALTER TABLE realization_entries ADD CONSTRAINT chk_realization_payment_method CHECK (payment_method IN ('tunai','transfer','kas_kecil'))");
        DB::statement("ALTER TABLE realization_entries ADD CONSTRAINT chk_realization_funding_source CHECK (funding_source IN ('kas_kebun','rekening_kebun','rekening_utama'))");
        DB::statement("CREATE INDEX idx_realization_entries_pdo_detail_date ON realization_entries(pdo_detail_id, transaction_date ASC)");
        DB::statement("CREATE INDEX idx_realization_entries_funding_source ON realization_entries(pdo_detail_id, funding_source)");
        DB::statement("CREATE INDEX idx_realization_entries_recorded_by ON realization_entries(recorded_by)");

        // ─────────────────────────────────────────
        // 17. REALIZATION_ATTACHMENTS
        // ─────────────────────────────────────────
        Schema::create('realization_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('realization_entry_id')->constrained('realization_entries')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('file_name', 255);
            $table->text('file_path');          // S3 path
            $table->string('mime_type', 100);
            $table->bigInteger('file_size_bytes');
            $table->timestampTz('created_at')->useCurrent();

            $table->comment('Metadata file bukti transaksi. File fisik di AWS S3.');
        });

        DB::statement("ALTER TABLE realization_attachments ADD CONSTRAINT chk_attachment_file_size CHECK (file_size_bytes > 0)");
        DB::statement("CREATE INDEX idx_realization_attachments_entry ON realization_attachments(realization_entry_id)");
        DB::statement("CREATE INDEX idx_realization_attachments_uploader ON realization_attachments(uploaded_by)");

        // ─────────────────────────────────────────
        // 18. AUDIT_LOGS
        // ─────────────────────────────────────────
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type', 100);
            $table->uuid('entity_id');
            $table->string('action', 50);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->inet('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->comment('Audit trail system-wide. Append-only — tidak ada UPDATE atau DELETE.');
        });

        DB::statement("CREATE INDEX idx_audit_logs_entity_date ON audit_logs(entity_type, entity_id, created_at DESC)");
        DB::statement("CREATE INDEX idx_audit_logs_actor_date ON audit_logs(actor_user_id, created_at DESC) WHERE actor_user_id IS NOT NULL");
        DB::statement("CREATE INDEX idx_audit_logs_action ON audit_logs(action, entity_type, created_at DESC)");

        // ─────────────────────────────────────────
        // 19. NOTIFICATION_TEMPLATES
        // ─────────────────────────────────────────
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->string('channel', 20);
            $table->text('template_body');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'event_type', 'channel'], 'uq_notification_templates');
            $table->comment('Template pesan notifikasi per event per channel.');
        });

        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT chk_notif_channel CHECK (channel IN ('whatsapp','in_system'))");
        DB::statement("CREATE INDEX idx_notification_templates_active ON notification_templates(company_id, is_active) WHERE is_active = TRUE");
    }

    public function down(): void
    {
        // Drop dalam urutan terbalik (respecting FK)
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('realization_attachments');
        Schema::dropIfExists('realization_entries');
        Schema::dropIfExists('transfer_entries');
        Schema::dropIfExists('pdo_supplementary_approval_logs');
        Schema::dropIfExists('pdo_approval_logs');
        Schema::dropIfExists('pdo_supplementary_details');
        Schema::dropIfExists('pdo_details');
        Schema::dropIfExists('pdo_supplementary_headers');
        Schema::dropIfExists('pdo_headers');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('expense_items');
        Schema::dropIfExists('expense_subcategories');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('plantation_units');
        Schema::dropIfExists('companies');
    }
};
