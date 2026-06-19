<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     * Rate limit: 5 percobaan per IP per 15 menit (NFR-12, TAD 5.1)
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $key = 'login:' . $request->ip();

        // Rate limit check
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            AuditLog::record(
                actor: null,
                entityType: 'users',
                entityId: '00000000-0000-0000-0000-000000000000',
                action: 'LOGIN_RATE_LIMITED',
                newValues: ['email' => $request->email, 'ip' => $request->ip()]
            );

            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'RATE_LIMIT_EXCEEDED',
                    'message' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
                ],
            ], 429);
        }

        $user = User::with(['role', 'plantationUnit'])
            ->where('email', $request->email)
            ->first();

        // Validasi credentials
        if (! $user || ! Hash::check($request->password, $user->password_hash)) {
            RateLimiter::hit($key, 900); // 15 menit

            AuditLog::record(
                actor: null,
                entityType: 'users',
                entityId: '00000000-0000-0000-0000-000000000000',
                action: 'LOGIN_FAILED',
                newValues: ['email' => $request->email, 'ip' => $request->ip()]
            );

            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'AUTHENTICATION_FAILED',
                    'message' => 'Email atau password tidak valid.',
                ],
            ], 401);
        }

        // Cek akun aktif
        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'ACCOUNT_INACTIVE',
                    'message' => 'Akun Anda tidak aktif. Hubungi Admin.',
                ],
            ], 401);
        }

        // Reset rate limit setelah login berhasil
        RateLimiter::clear($key);

        // Buat token Sanctum
        $token = $user->createToken('pdo-access-token', ['*'], now()->addHour())->plainTextToken;

        // Update last_login_at
        $user->update(['last_login_at' => now()]);

        // Audit log
        AuditLog::record(
            actor: $user,
            entityType: 'users',
            entityId: $user->id,
            action: 'LOGIN',
            newValues: ['ip' => $request->ip()]
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
                'user'         => $this->formatUser($user),
            ],
            'message' => 'Login berhasil.',
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke token saat ini
        $request->user()->currentAccessToken()->delete();

        AuditLog::record(
            actor: $user,
            entityType: 'users',
            entityId: $user->id,
            action: 'LOGOUT',
        );

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * POST /api/v1/auth/refresh-token
     * Rotate token — revoke lama, buat baru dengan expiry 1 jam
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string']);

        // Cari token berdasarkan hash
        $hashedToken = hash('sha256', $request->refresh_token);
        $token = \Laravel\Sanctum\PersonalAccessToken::where('token', $hashedToken)->first();

        if (! $token || $token->expires_at?->isPast()) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'TOKEN_EXPIRED',
                    'message' => 'Refresh token tidak valid atau sudah kedaluwarsa. Silakan login kembali.',
                ],
            ], 401);
        }

        $user = $token->tokenable;

        // Revoke token lama
        $token->delete();

        // Buat token baru
        $newToken = $user->createToken('pdo-access-token', ['*'], now()->addHour())->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => [
                'access_token' => $newToken,
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'plantationUnit']);

        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user),
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'               => $user->id,
            'full_name'        => $user->full_name,
            'email'            => $user->email,
            'whatsapp_number'  => $user->whatsapp_number,
            'is_active'        => $user->is_active,
            'role'             => [
                'id'   => $user->role->id,
                'name' => $user->role->name,
                'code' => $user->role->code,
            ],
            'plantation_unit'  => $user->plantationUnit ? [
                'id'   => $user->plantationUnit->id,
                'code' => $user->plantationUnit->code,
                'name' => $user->plantationUnit->name,
            ] : null,
            'last_login_at'    => $user->last_login_at?->toIso8601String(),
        ];
    }
}
