<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Dasar seluruh controller modul supervisi.
 *
 * Semua aturan tata kelola (siapa boleh menilai siapa, versi yang sudah
 * terbit tidak boleh diubah) ditegakkan di service — controller hanya
 * memvalidasi masukan dan menyusun respons.
 */
abstract class SupController extends BaseApiController
{
    protected function actor(Request $request): User
    {
        $actor = $request->user();

        if ($actor === null) {
            abort(401, 'Anda belum terautentikasi.');
        }

        return $actor;
    }

    protected function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        return max(1, min($max, (int) $request->query('per_page', $default)));
    }

    protected function authorizeSup(bool $allowed, string $message = 'Anda tidak memiliki izin untuk tindakan ini.'): void
    {
        if (! $allowed) {
            abort(403, $message);
        }
    }

    /** @param array<string,mixed> $data */
    protected function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
