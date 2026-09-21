<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseApiController
{
    /**
     * Login dan kembalikan token Sanctum.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Email atau password salah', 401);
        }

        if (! $user->is_active) {
            return $this->errorResponse('Akun Anda tidak aktif. Hubungi administrator.', 403);
        }

        $token = $user->createToken($request->input('device_name', 'web'))->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->save();
        $user->load('roles', 'permissions');

        return $this->successResponse([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Login berhasil');
    }

    /**
     * Profil user yang sedang login.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'permissions');

        return $this->successResponse(new UserResource($user), 'Profil berhasil diambil');
    }

    /**
     * Logout (hapus token aktif).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Logout berhasil');
    }
}
