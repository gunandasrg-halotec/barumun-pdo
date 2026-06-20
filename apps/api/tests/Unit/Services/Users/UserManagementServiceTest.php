<?php

namespace Tests\Unit\Services\Users;

use App\Models\Company;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserManagementService $service;
    private User $admin;
    private string $companyId;
    private Role $adminRole;
    private Role $keraniRole;
    private Role $manajerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new UserManagementService();
        $this->companyId = Company::factory()->create()->id;

        $this->adminRole   = Role::factory()->create(['code' => Role::ADMIN]);
        $this->keraniRole  = Role::factory()->create(['code' => Role::KERANI]);
        $this->manajerRole = Role::factory()->create(['code' => Role::MANAJER_KEBUN]);

        $this->admin = User::factory()->create([
            'company_id' => $this->companyId,
            'role_id'    => $this->adminRole->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // CREATE
    // ─────────────────────────────────────────────────────

    public function test_creates_user_and_hashes_password(): void
    {
        $user = $this->service->createUser([
            'role_id'   => $this->manajerRole->id,
            'full_name' => 'Budi Santoso',
            'email'     => 'budi@kebun.com',
            'password'  => 'Secret123',
        ], $this->admin);

        $this->assertEquals('budi@kebun.com', $user->email);
        $this->assertEquals($this->companyId, $user->company_id);
        $this->assertTrue(Hash::check('Secret123', $user->password_hash));
    }

    public function test_creates_user_with_correct_company_id_from_actor(): void
    {
        $user = $this->service->createUser([
            'role_id'   => $this->manajerRole->id,
            'full_name' => 'Test User',
            'email'     => 'test@kebun.com',
            'password'  => 'Pass1234',
        ], $this->admin);

        $this->assertEquals($this->admin->company_id, $user->company_id);
    }

    // ─────────────────────────────────────────────────────
    // BR-USER-003: Unit assignment
    // ─────────────────────────────────────────────────────

    public function test_kerani_without_unit_is_rejected(): void
    {
        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->createUser([
            'role_id'            => $this->keraniRole->id,
            'full_name'          => 'Kerani Tanpa Unit',
            'email'              => 'kerani@kebun.com',
            'password'           => 'Pass1234',
            'plantation_unit_id' => null,
        ], $this->admin);
    }

    public function test_kerani_with_unit_is_created_successfully(): void
    {
        $unit = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $user = $this->service->createUser([
            'role_id'            => $this->keraniRole->id,
            'full_name'          => 'Kerani Dengan Unit',
            'email'              => 'kerani2@kebun.com',
            'password'           => 'Pass1234',
            'plantation_unit_id' => $unit->id,
        ], $this->admin);

        $this->assertEquals($unit->id, $user->plantation_unit_id);
    }

    public function test_manajer_can_be_created_without_unit(): void
    {
        $user = $this->service->createUser([
            'role_id'            => $this->manajerRole->id,
            'full_name'          => 'Manajer Kebun',
            'email'              => 'manajer@kebun.com',
            'password'           => 'Pass1234',
            'plantation_unit_id' => null,
        ], $this->admin);

        $this->assertNull($user->plantation_unit_id);
    }

    // ─────────────────────────────────────────────────────
    // BR-USER-004: Tidak boleh hapus diri sendiri
    // ─────────────────────────────────────────────────────

    public function test_cannot_delete_self(): void
    {
        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->deleteUser($this->admin, $this->admin);
    }

    public function test_can_delete_other_user(): void
    {
        $target = User::factory()->create([
            'company_id' => $this->companyId,
            'role_id'    => $this->manajerRole->id,
        ]);

        $this->service->deleteUser($target, $this->admin);

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
    }

    // ─────────────────────────────────────────────────────
    // BR-USER-005: Selalu soft delete
    // ─────────────────────────────────────────────────────

    public function test_delete_always_soft_deletes(): void
    {
        $target = User::factory()->create([
            'company_id' => $this->companyId,
            'role_id'    => $this->manajerRole->id,
        ]);

        $this->service->deleteUser($target, $this->admin);

        // Masih ada di DB tapi deleted_at tidak null
        $this->assertNotNull(User::withTrashed()->find($target->id)->deleted_at);
    }

    // ─────────────────────────────────────────────────────
    // UPDATE
    // ─────────────────────────────────────────────────────

    public function test_update_password_rehashes(): void
    {
        $target = User::factory()->create([
            'company_id'    => $this->companyId,
            'role_id'       => $this->manajerRole->id,
            'password_hash' => Hash::make('OldPass1'),
        ]);

        $updated = $this->service->updateUser($target, ['password' => 'NewPass9'], $this->admin);

        $this->assertTrue(Hash::check('NewPass9', $updated->password_hash));
        $this->assertFalse(Hash::check('OldPass1', $updated->password_hash));
    }

    public function test_update_role_to_kerani_without_unit_is_rejected(): void
    {
        $target = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $this->manajerRole->id,
            'plantation_unit_id' => null,
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->updateUser($target, [
            'role_id'            => $this->keraniRole->id,
            'plantation_unit_id' => null,
        ], $this->admin);
    }

    // ─────────────────────────────────────────────────────
    // AUDIT LOG
    // ─────────────────────────────────────────────────────

    public function test_audit_log_written_on_create(): void
    {
        $this->service->createUser([
            'role_id'   => $this->manajerRole->id,
            'full_name' => 'Audit Test',
            'email'     => 'audit@kebun.com',
            'password'  => 'Pass1234',
        ], $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'users',
            'action'      => 'INSERT',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_audit_log_written_on_delete(): void
    {
        $target = User::factory()->create([
            'company_id' => $this->companyId,
            'role_id'    => $this->manajerRole->id,
        ]);

        $this->service->deleteUser($target, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'users',
            'action'      => 'DELETE',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // LIST
    // ─────────────────────────────────────────────────────

    public function test_list_users_scoped_to_company(): void
    {
        $otherCompanyId = Company::factory()->create()->id;
        User::factory()->create(['company_id' => $otherCompanyId, 'role_id' => $this->manajerRole->id]);
        User::factory()->create(['company_id' => $this->companyId, 'role_id' => $this->manajerRole->id]);

        $users = $this->service->listUsers($this->companyId);

        // admin (dari setUp) + 1 user baru = 2
        $this->assertCount(2, $users);
        $users->each(fn ($u) => $this->assertEquals($this->companyId, $u->company_id));
    }

    public function test_list_roles_returns_all_roles(): void
    {
        $roles = $this->service->listRoles();

        $this->assertGreaterThanOrEqual(3, $roles->count()); // minimal dari setUp
    }
}
