<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\BoardingAchievementResource;
use App\Http\Resources\BoardingAttendanceResource;
use App\Http\Resources\BoardingHealthRecordResource;
use App\Http\Resources\BoardingHomecomingResource;
use App\Http\Resources\BoardingPermissionResource;
use App\Http\Resources\BoardingViolationResource;
use App\Http\Resources\BoardingVisitorResource;
use App\Models\Student;
use App\Services\Boarding\BoardingAchievementService;
use App\Services\Boarding\BoardingAttendanceService;
use App\Services\Boarding\BoardingHealthService;
use App\Services\Boarding\BoardingHomecomingService;
use App\Services\Boarding\BoardingNotificationService;
use App\Services\Boarding\BoardingPermissionService;
use App\Services\Boarding\BoardingPortalService;
use App\Services\Boarding\BoardingStatusService;
use App\Services\Boarding\BoardingViolationService;
use App\Services\Boarding\BoardingVisitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal orang tua untuk asrama (§44–§45) — HANYA BACA.
 *
 * Otorisasinya bukan permission boarding (orang tua tidak boleh memegang
 * permission admin, §56) melainkan kepemilikan: `BoardingPortalService::
 * assertChild()` memverifikasi santri yang diminta benar anak pengguna lewat
 * rantai `student_guardians` → `ppdb_parent_accounts` — sama dengan rantai
 * pengiriman notifikasi. Login pun memakai alur PPDB yang sudah ada; tidak ada
 * autentikasi kedua.
 *
 * Layanan domain dipanggil dengan `actor = null` setelah kepemilikan terbukti:
 * pembatasnya di sini adalah `assertChild()`, bukan lingkup binaan pengasuh.
 */
class BoardingPortalController extends BaseApiController
{
    public function __construct(
        private readonly BoardingPortalService $portal,
        private readonly BoardingStatusService $statuses,
        private readonly BoardingViolationService $violations,
        private readonly BoardingAchievementService $achievements,
        private readonly BoardingHealthService $health,
        private readonly BoardingAttendanceService $attendances,
        private readonly BoardingPermissionService $permissions,
        private readonly BoardingHomecomingService $homecomings,
        private readonly BoardingVisitorService $visitors,
        private readonly BoardingNotificationService $notifications,
    ) {
    }

    public function children(Request $request): JsonResponse
    {
        return $this->successResponse($this->portal->children($request->user()), 'Daftar anak asrama berhasil diambil');
    }

    /** Kartu ringkas satu anak: status §42 + poin + counter per area. */
    public function summary(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        $points = $this->violations->pointsSummary($childId, null, 5);
        $placement = $this->statuses->summaryFor($childId);

        return $this->successResponse([
            'placement' => $placement,
            'points' => [
                'point_label' => $points['point_label'],
                'total_points' => $points['total_points'],
                'violation_count' => $points['violation_count'],
                'open_count' => $points['open_count'],
                'by_category' => $points['by_category'],
                'next_threshold' => $points['next_threshold'],
            ],
            'counts' => [
                'achievements' => $this->achievements->query(['student_id' => $childId])->count(),
                'health_open' => $this->health->query(['student_id' => $childId, 'open_only' => 1])->count(),
                // "berjalan" = status masih terbuka (belum kembali/dibatalkan/kedaluwarsa),
                // bukan total riwayat — kartu portal menampilkan yang sedang aktif.
                'permissions' => $this->permissions->query($this->filtered($request, $childId), null)->active()->count(),
                'homecomings' => $this->homecomings->query($this->filtered($request, $childId), null)->active()->count(),
                'visitors' => $this->visitors->query($this->filtered($request, $childId), null)->count(),
            ],
            'recent_violations' => $points['recent'],
            'unread_notifications' => $this->notifications->unreadBoardingCountFor((int) $request->user()->id),
        ], 'Ringkasan anak berhasil diambil');
    }

    public function violations(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->paginated(
            $this->violations->query($this->filtered($request, $childId)->query(), null)->forStudent($childId),
            $request,
            fn ($items): mixed => BoardingViolationResource::collection($items),
            'Daftar pelanggaran anak berhasil diambil',
        );
    }

    public function points(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->successResponse(
            $this->violations->pointsSummary($childId, null, 20),
            'Ringkasan poin anak berhasil diambil',
        );
    }

    public function achievements(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->paginated(
            $this->achievements->query($this->filtered($request, $childId)->query(), null)->forStudent($childId),
            $request,
            fn ($items): mixed => BoardingAchievementResource::collection($items),
            'Daftar prestasi anak berhasil diambil',
        );
    }

    public function health(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->paginated(
            $this->health->query($this->filtered($request, $childId)->query(), null)->forStudent($childId),
            $request,
            fn ($items): mixed => BoardingHealthRecordResource::collection($items),
            'Daftar catatan kesehatan anak berhasil diambil',
        );
    }

    public function attendances(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->paginated(
            $this->attendances->history($student, $request->query(), null),
            $request,
            fn ($items): mixed => BoardingAttendanceResource::collection($items),
            'Riwayat absensi anak berhasil diambil',
        );
    }

    public function permissions(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->paginated(
            $this->permissions->query($this->filtered($request, $childId), null),
            $request,
            fn ($items): mixed => BoardingPermissionResource::collection($items),
            'Daftar izin keluar anak berhasil diambil',
        );
    }

    public function homecomings(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->paginated(
            $this->homecomings->query($this->filtered($request, $childId), null),
            $request,
            fn ($items): mixed => BoardingHomecomingResource::collection($items),
            'Daftar kepulangan anak berhasil diambil',
        );
    }

    public function visitors(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->paginated(
            $this->visitors->query($this->filtered($request, $childId), null),
            $request,
            fn ($items): mixed => BoardingVisitorResource::collection($items),
            'Daftar kunjungan anak berhasil diambil',
        );
    }

    /**
     * Salinan request dengan student_id terkunci ke anak yang sudah diverifikasi
     * — klien tidak boleh memilih santri orang lain lewat query string.
     */
    protected function filtered(Request $request, int $childId): Request
    {
        $clone = clone $request;
        $clone->query->set('student_id', (string) $childId);

        return $clone;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<mixed>  $query
     * @param  callable(mixed): mixed  $wrap
     */
    protected function paginated($query, Request $request, callable $wrap, string $message): JsonResponse
    {
        if ($request->boolean('all')) {
            return $this->successResponse($wrap($query->limit(500)->get()), $message);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));

        return $this->successResponse($wrap($query->paginate($perPage)), $message);
    }
}
