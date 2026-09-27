<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Student;
use App\Services\Boarding\BoardingMonitoringService;
use App\Services\Boarding\BoardingScopeService;
use App\Services\Boarding\BoardingStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monitoring status santri (§42) — daftar "siapa di mana" + kartu satu santri.
 */
class BoardingMonitoringController extends BaseApiController
{
    public function __construct(
        private readonly BoardingMonitoringService $monitoring,
        private readonly BoardingStatusService $statuses,
        private readonly BoardingScopeService $scope,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(200, (int) $request->query('per_page', 25)));

        return $this->successResponse(
            $this->monitoring->overview($request->query(), $request->user(), $perPage),
            'Monitoring santri asrama berhasil diambil',
        );
    }

    /** Legend/opsi status §42 untuk filter UI. */
    public function options(): JsonResponse
    {
        return $this->successResponse(BoardingStatusService::options(), 'Opsi status santri berhasil diambil');
    }

    public function student(Request $request, Student $student): JsonResponse
    {
        return $this->successResponse(
            $this->monitoring->studentCard((int) $student->id, $request->user()),
            'Kartu status santri berhasil diambil',
        );
    }

    /** Status banyak santri sekaligus (buat kartu daftar). Query: student_ids=1,2,3 */
    public function bulk(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null,
            explode(',', (string) $request->query('student_ids', '')),
        )));

        $allowed = array_slice($ids, 0, 500);

        // Satu per satu dicek: daftar id dari klien tidak boleh melewati lingkup
        // binaan pengguna yang dibatasi.
        foreach ($allowed as $id) {
            $this->scope->assertCanAccessStudent($request->user(), $id);
        }

        return $this->successResponse([
            'statuses' => $this->statuses->statusesFor($allowed),
            'summaries' => $this->statuses->summariesFor($allowed),
        ], 'Status santri berhasil diambil');
    }
}
