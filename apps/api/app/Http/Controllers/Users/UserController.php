<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Services\Users\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserManagementService $service) {}

    public function index(Request $request): JsonResponse
    {
        // Hanya ADMIN yang bisa melihat daftar user
        if (! $request->user()->hasRole(Role::ADMIN)) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke halaman ini.'],
            ], 403);
        }

        $filters = $request->only(['is_active', 'role_id']);
        $data    = $this->service->listUsers($request->user()->company_id, $filters);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->service->createUser($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $user, 'message' => 'Pengguna berhasil dibuat.'], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! $request->user()->hasRole(Role::ADMIN) && $request->user()->id !== $id) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses ke data ini.'],
            ], 403);
        }

        $user = $this->service->findUser($id, $request->user()->company_id);

        return response()->json(['success' => true, 'data' => $user]);
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user    = $this->service->findUser($id, $request->user()->company_id);
        $updated = $this->service->updateUser($user, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Pengguna berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()->hasRole(Role::ADMIN)) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses untuk menghapus pengguna.'],
            ], 403);
        }

        $user = $this->service->findUser($id, $request->user()->company_id);
        $this->service->deleteUser($user, $request->user());

        return response()->json(['success' => true, 'message' => 'Pengguna berhasil dihapus.']);
    }

    public function roles(): JsonResponse
    {
        $roles = $this->service->listRoles();

        return response()->json(['success' => true, 'data' => $roles]);
    }

    public function plantationUnits(Request $request): JsonResponse
    {
        $units = PlantationUnit::where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return response()->json(['success' => true, 'data' => $units]);
    }
}
