<?php

namespace App\Modules\LMS\Domain\Support;

use Illuminate\Validation\ValidationException;

/**
 * Kesalahan domain LMS dalam bentuk respons HTTP yang konsisten.
 *
 * Phase 1–2 memakai `abort(403)` di controller dan `ValidationException` untuk
 * payload; helper ini hanya memindahkan konvensi yang sama ke dalam service
 * supaya aturan penilaian tidak perlu tahu soal controller.
 */
final class LmsFault
{
    public static function forbidden(string $message = 'Anda tidak memiliki akses untuk tindakan ini.'): never
    {
        abort(403, $message);
    }

    public static function notFound(string $message = 'Data tidak ditemukan.'): never
    {
        abort(404, $message);
    }

    public static function invalid(string $message, string $field = 'grade'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function conflict(string $message): never
    {
        throw ValidationException::withMessages(['state' => $message]);
    }
}
