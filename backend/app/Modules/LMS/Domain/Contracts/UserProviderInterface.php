<?php

namespace App\Modules\LMS\Domain\Contracts;

use App\Modules\LMS\Domain\Support\LmsActor;
use Illuminate\Http\Request;

/**
 * Sumber identitas pengguna LMS.
 *
 * Integrated : `App\Models\User` (Spatie role/permission) — tidak ada User baru.
 * Standalone : `LmsUser` (tabel `lms_users`, role disimpan lokal).
 */
interface UserProviderInterface
{
    /** 'integrated' | 'standalone' */
    public function mode(): string;

    public function isStandalone(): bool;

    /** Pelaku dari request terautentikasi (null bila guest). */
    public function fromRequest(Request $request): ?LmsActor;

    public function find(int $id): ?LmsActor;

    /**
     * Verifikasi kredensial lalu kembalikan pelaku (dipakai Standalone Mode
     * untuk login mandiri; Integrated Mode memakai endpoint auth ERP).
     */
    public function attempt(string $login, string $password): ?LmsActor;

    /** Token API untuk pelaku (Sanctum di kedua mode). */
    public function issueToken(LmsActor $actor, string $deviceName): string;

    public function revokeCurrentToken(Request $request): void;

    /**
     * Daftar pelaku berdasarkan id (dipakai daftar peserta/enrollment).
     *
     * @param  list<int>  $ids
     * @return array<int, LmsActor>
     */
    public function findMany(array $ids): array;
}
