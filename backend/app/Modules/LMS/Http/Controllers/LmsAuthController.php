<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Middleware\ResolveLmsActor;
use App\Modules\LMS\Models\LmsUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Autentikasi LMS (§2).
 *
 * Integrated Mode: endpoint ini hanya meneruskan kredensial ke akun ERP yang
 * sama — token tetap Sanctum, sehingga frontend LMS memakai sesi yang sudah ada.
 * Standalone Mode: akun dibuat & diverifikasi pada tabel `lms_users`.
 */
class LmsAuthController extends LmsController
{
    public function __construct(private readonly UserProviderInterface $users) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [], ['login' => 'email atau nama pengguna']);

        $actor = $this->users->attempt($data['login'], $data['password']);

        if ($actor === null) {
            return $this->errorResponse('Kredensial tidak cocok atau akun tidak aktif.', 401, [
                'login' => ['Email/nama pengguna atau kata sandi salah.'],
            ]);
        }

        return $this->successResponse([
            'token' => $this->users->issueToken($actor, $data['device_name'] ?? 'lms-web'),
            'token_type' => 'Bearer',
            'actor' => $actor->toArray(),
            'mode' => $this->users->mode(),
        ], 'Berhasil masuk ke LMS');
    }

    /** Pendaftaran mandiri hanya tersedia pada Standalone Mode (§2). */
    public function register(Request $request): JsonResponse
    {
        if (! $this->users->isStandalone()) {
            return $this->errorResponse(
                'Pendaftaran mandiri hanya tersedia ketika LMS berjalan sendiri (LMS_STANDALONE=true).',
                409,
            );
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('lms_users', 'email')],
            'username' => ['nullable', 'string', 'max:60', Rule::unique('lms_users', 'username')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', Rule::in([ActorRole::STUDENT->value, ActorRole::TEACHER->value, ActorRole::PARENT->value])],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [], ['name' => 'nama', 'email' => 'email', 'password' => 'kata sandi']);

        $user = LmsUser::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? ActorRole::STUDENT->value,
            'is_active' => true,
        ]);

        $actor = $this->users->find((int) $user->id);

        return $this->createdResponse([
            'token' => $actor !== null ? $this->users->issueToken($actor, $data['device_name'] ?? 'lms-web') : null,
            'actor' => $actor?->toArray(),
        ], 'Akun LMS berhasil dibuat');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->successResponse([
            'actor' => $this->actor($request)->toArray(),
            'mode' => $this->users->mode(),
        ], 'Profil LMS berhasil diambil');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->users->revokeCurrentToken($request);

        return $this->successResponse(null, 'Berhasil keluar dari LMS');
    }

    /** Ringkasan identitas untuk header LMS (tanpa menuntut autentikasi). */
    public function session(Request $request): JsonResponse
    {
        $actor = ResolveLmsActor::actor($request);

        return $this->successResponse([
            'authenticated' => $actor !== null,
            'actor' => $actor?->toArray(),
            'mode' => $this->users->mode(),
        ], 'Status sesi LMS berhasil diambil');
    }

    /** Dipakai middleware untuk dokumentasi tipe aktor yang sah. */
    protected function roleLabel(ActorRole $role): string
    {
        return $role->label();
    }
}
