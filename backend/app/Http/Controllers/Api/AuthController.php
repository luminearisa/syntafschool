<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Mobile\ContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseApiController
{
    public function __construct(private readonly ContextService $contexts) {}

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

        if ($request->input('client') === 'mobile') {
            return $this->mobileLogin($request, $user);
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
     * Login mobile: token awal hanya boleh memilih peran. Bila akun hanya
     * punya satu peran, peran itu langsung dipilih.
     */
    private function mobileLogin(LoginRequest $request, User $user): JsonResponse
    {
        $contexts = $this->contexts->contexts($user);

        if ($contexts === []) {
            return $this->errorResponse('Akun Anda belum terhubung dengan data pegawai, wali murid, atau siswa. Hubungi administrator sekolah.', 403);
        }

        $device = (string) $request->input('device_name', 'mobile');
        $user->forceFill(['last_login_at' => now()])->save();

        if (count($contexts) === 1) {
            return $this->issueContextToken($user, $contexts[0]['type'], $device, 'Login berhasil');
        }

        $token = $user->createToken($device, ['ctx:pending'])->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => $this->userPayload($user),
            'contexts' => $contexts,
            'active_context' => null,
        ], 'Pilih peran untuk melanjutkan');
    }

    /**
     * Daftar peran yang tersedia + peran aktif pada token ini.
     */
    public function contexts(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'contexts' => $this->contexts->contexts($user),
            'active_context' => $this->activeContext($user),
        ], 'Daftar peran berhasil diambil');
    }

    /**
     * Pindah peran: menerbitkan token baru ber-ability `ctx:<type>` dan
     * mencabut token saat ini.
     */
    public function switchContext(Request $request): JsonResponse
    {
        $data = $request->validate([
            'context' => ['required', 'string', 'in:'.implode(',', ContextService::TYPES)],
        ]);

        $user = $request->user();

        if (! $this->contexts->has($user, $data['context'])) {
            return $this->errorResponse('Peran tidak tersedia untuk akun ini.', 403);
        }

        $current = $user->currentAccessToken();
        $device = $current?->name ?: 'mobile';
        $current?->delete();

        return $this->issueContextToken($user, $data['context'], $device, 'Peran berhasil diganti');
    }

    private function issueContextToken(User $user, string $type, string $device, string $message): JsonResponse
    {
        $token = $user->createToken($device, [ContextService::ability($type)])->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => $this->userPayload($user),
            'contexts' => $this->contexts->contexts($user),
            'active_context' => $type,
        ], $message);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_path' => $user->avatar_path,
        ];
    }

    private function activeContext(User $user): ?string
    {
        $token = $user->currentAccessToken();

        foreach (ContextService::TYPES as $type) {
            if ($token && method_exists($token, 'can') && $token->can(ContextService::ability($type))) {
                return $type;
            }
        }

        return null;
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
