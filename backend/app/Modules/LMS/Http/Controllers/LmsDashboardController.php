<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\LmsCalendarService;
use App\Modules\LMS\Application\Services\LmsDashboardService;
use App\Modules\LMS\Application\Services\LmsIntegrationService;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Http\LmsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dashboard semua peran (§26–§29, §37). */
class LmsDashboardController extends LmsController
{
    public function __construct(
        private readonly LmsDashboardService $dashboards,
        private readonly StudentProviderInterface $students,
        private readonly LmsIntegrationService $integration,
        private readonly LmsCalendarService $calendar,
    ) {}

    /** GET /lms/calendar — agenda belajar (§25). */
    public function calendar(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->calendar->agenda(
                $this->actor($request),
                $request->query('from') !== null ? (string) $request->query('from') : null,
                $request->query('until') !== null ? (string) $request->query('until') : null,
            ),
            'Kalender belajar berhasil diambil',
        );
    }

    /** GET /lms/dashboard — dashboard sesuai peran pemanggil. */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->forActor($this->actor($request)),
            'Dashboard LMS berhasil diambil',
        );
    }

    public function student(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->student($this->actor($request)),
            'Dashboard siswa berhasil diambil',
        );
    }

    public function teacher(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->teacher($this->actor($request)),
            'Dashboard guru berhasil diambil',
        );
    }

    public function parent(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->parent($this->actor($request)),
            'Dashboard orang tua berhasil diambil',
        );
    }

    public function admin(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->admin($this->actor($request)),
            'Dashboard admin LMS berhasil diambil',
        );
    }

    /** GET /lms/parent/children — daftar anak seorang wali (§28). */
    public function children(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->integration->children($this->actor($request)),
            'Daftar anak berhasil diambil',
        );
    }

    /**
     * GET /lms/parent/children/{student} — detail belajar satu anak.
     *
     * Isolasi anak ditegakkan di sini: wali yang meminta siswa bukan anaknya
     * menerima 403, bukan data kosong (§44).
     */
    public function child(Request $request, int $student): JsonResponse
    {
        $actor = $this->actor($request);

        if (! $actor->can('lms.manage') && ! $this->students->isGuardianOf($actor, $student)) {
            return $this->errorResponse('Anda hanya dapat melihat data belajar anak Anda sendiri.', 403);
        }

        $overview = $this->dashboards->childOverview($actor, $student);

        if ($overview === []) {
            return $this->errorResponse('Data siswa tidak ditemukan.', 404);
        }

        return $this->successResponse($overview, 'Detail belajar anak berhasil diambil');
    }
}
