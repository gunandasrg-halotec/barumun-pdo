<?php

namespace App\Services\Users;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

class UserManagementService
{
    public function listUsers(string $companyId, array $filters = []): Collection
    {
        return User::with(['role', 'plantationUnit'])
            ->where('company_id', $companyId)
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->when(isset($filters['role_id']), fn ($q) => $q->where('role_id', $filters['role_id']))
            ->orderBy('full_name')
            ->get();
    }

    public function findUser(string $id, string $companyId): User
    {
        return User::with(['role', 'plantationUnit'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function createUser(array $data, User $actor): User
    {
        $role = Role::findOrFail($data['role_id']);

        // BR-USER-003: role unit-bound wajib punya plantation_unit_id
        $this->assertUnitAssignment($role, $data['plantation_unit_id'] ?? null);

        $user = User::create([
            'company_id'         => $actor->company_id,
            'role_id'            => $data['role_id'],
            'plantation_unit_id' => $data['plantation_unit_id'] ?? null,
            'full_name'          => $data['full_name'],
            'email'              => $data['email'],
            'password_hash'      => Hash::make($data['password']),
            'whatsapp_number'    => $data['whatsapp_number'] ?? null,
            'is_active'          => $data['is_active'] ?? true,
        ]);

        AuditLog::record(
            actor: $actor,
            entityType: 'users',
            entityId: $user->id,
            action: 'INSERT',
            oldValues: null,
            newValues: $user->makeHidden(['password_hash'])->toArray()
        );

        return $user->load(['role', 'plantationUnit']);
    }

    public function updateUser(User $user, array $data, User $actor): User
    {
        // BR-USER-003: validasi ulang unit assignment jika role atau unit berubah
        $roleId = $data['role_id'] ?? $user->role_id;
        $unitId = array_key_exists('plantation_unit_id', $data)
            ? $data['plantation_unit_id']
            : $user->plantation_unit_id;

        $role = Role::findOrFail($roleId);
        $this->assertUnitAssignment($role, $unitId);

        // plantation_unit_id boleh null (melepas unit kebun) — tidak boleh difilter
        $payload = array_filter([
            'role_id'         => $data['role_id'] ?? null,
            'full_name'       => $data['full_name'] ?? null,
            'email'           => $data['email'] ?? null,
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'is_active'       => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null);

        $payload['plantation_unit_id'] = $unitId;

        // Password diupdate terpisah agar tidak masuk array_filter null check
        if (isset($data['password'])) {
            $payload['password_hash'] = Hash::make($data['password']);
        }

        $old = $user->makeHidden(['password_hash'])->toArray();
        $user->update($payload);

        AuditLog::record(
            actor: $actor,
            entityType: 'users',
            entityId: $user->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $user->fresh()->makeHidden(['password_hash'])->toArray()
        );

        return $user->fresh()->load(['role', 'plantationUnit']);
    }

    public function deleteUser(User $user, User $actor): void
    {
        // BR-USER-004: tidak boleh hapus diri sendiri
        if ($user->id === $actor->id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'CANNOT_DELETE_SELF', 'message' => 'Anda tidak dapat menghapus akun Anda sendiri.'],
            ], 409));
        }

        $old = $user->makeHidden(['password_hash'])->toArray();

        // BR-USER-005: selalu soft delete (audit trail harus terjaga)
        $user->update(['is_active' => false]);
        $user->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'users',
            entityId: $user->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }

    public function listRoles(): Collection
    {
        return Role::orderBy('name')->get();
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────

    /**
     * BR-USER-003: Role unit-bound (KERANI, ASISTEN_KEBUN) wajib punya plantation_unit_id.
     * Role cross-unit tidak boleh terikat ke unit tertentu.
     */
    private function assertUnitAssignment(Role $role, ?string $unitId): void
    {
        $isUnitBound = in_array($role->code, [Role::KERANI, Role::ASISTEN_KEBUN]);

        if ($isUnitBound && empty($unitId)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_REQUIRED', 'message' => "Role {$role->code} wajib memiliki unit kebun."],
            ], 422));
        }
    }
}
