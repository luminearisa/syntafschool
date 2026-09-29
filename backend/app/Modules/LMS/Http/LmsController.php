<?php

namespace App\Modules\LMS\Http;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Http\Middleware\ResolveLmsActor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Dasar seluruh controller LMS.
 *
 * Menyediakan akses ke pelaku (mode apa pun), paginasi seragam, dan penolakan
 * 403 yang konsisten sehingga aturan akses tidak tersebar di tiap endpoint.
 */
abstract class LmsController extends BaseApiController
{
    protected function actor(Request $request): LmsActor
    {
        $actor = ResolveLmsActor::actor($request);

        if ($actor === null) {
            abort(401, 'Anda belum terautentikasi pada LMS.');
        }

        return $actor;
    }

    protected function optionalActor(Request $request): ?LmsActor
    {
        return ResolveLmsActor::actor($request);
    }

    protected function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        return max(1, min($max, (int) $request->query('per_page', $default)));
    }

    /**
     * Boleh mengakses data nilai course ini?
     *
     * Permission tingkat peran (`lms.gradebook.view`, `lms.grade.view`, …)
     * hanya menyatakan *kapabilitas*; kepemilikan course tetap diperiksa di
     * sini supaya guru tidak dapat membuka gradebook course guru lain (§53).
     * `lms.report.view`/`lms.audit.view` hanya memberi akses baca (kepala
     * sekolah, pengawas).
     */
    protected function canAccessCourseGrades(?LmsActor $actor, LmsCourse $course, bool $readOnly = false): bool
    {
        if ($actor === null) {
            return false;
        }

        $access = app(CourseAccessService::class);

        if ($access->canGrade($course, $actor) || $actor->can('lms.manage')) {
            return true;
        }

        if (! $readOnly) {
            return false;
        }

        // Pengawas akademik: admin, kepala sekolah, dan wali kelas memang
        // memantau nilai lintas course — guru biasa tidak (§53).
        return $actor->can('lms.audit.view')
            || $actor->hasRole('kepala-sekolah', 'wali-kelas', 'admin-sekolah', 'super-admin');
    }

    /** Dipakai controller sebelum melayani/mengubah data LMS. */
    protected function authorizeLms(bool $allowed, string $message = 'Anda tidak memiliki akses ke course ini.'): void
    {
        if (! $allowed) {
            abort(403, $message);
        }
    }

    /** Validasi bersyarat untuk payload course/item dari frontend. */
    protected function validatePayload(Request $request, array $rules, array $attributes = []): array
    {
        return $request->validate($rules, [], $attributes);
    }

    protected function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
