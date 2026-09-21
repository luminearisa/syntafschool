<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\TahfizHalaqah;
use App\Models\TahfizParticipant;
use App\Models\TahfizProgram;
use App\Services\Tahfiz\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Laporan & rapor Tahfiz.
 *
 * Semua laporan dihitung ulang dari data mentah pada saat diminta, sehingga
 * tidak pernah menampilkan ringkasan yang usang. Periode (`from`/`to`)
 * opsional; tanpa periode, seluruh data historis dihitung.
 *
 * Laporan butuh `tahfiz.report`; rapor adalah dokumen formal yang juga butuh
 * `tahfiz.report`.
 */
class TahfizReportController extends BaseApiController
{
    public function __construct(protected ReportService $reports)
    {
    }

    public function individual(Request $request, TahfizParticipant $participant): JsonResponse
    {
        $validated = $this->periodRules($request);

        return $this->successResponse(
            $this->reports->individual($participant->id, $validated['from'] ?? null, $validated['to'] ?? null),
            'Laporan individual peserta berhasil dibuat'
        );
    }

    public function halaqah(Request $request, TahfizHalaqah $halaqah): JsonResponse
    {
        $validated = $this->periodRules($request);

        return $this->successResponse(
            $this->reports->halaqah($halaqah->id, $validated['from'] ?? null, $validated['to'] ?? null),
            'Laporan halaqah berhasil dibuat'
        );
    }

    public function program(Request $request, TahfizProgram $program): JsonResponse
    {
        $validated = $this->periodRules($request);

        return $this->successResponse(
            $this->reports->program($program->id, $validated['from'] ?? null, $validated['to'] ?? null),
            'Laporan program berhasil dibuat'
        );
    }

    public function teacher(Request $request, Employee $employee): JsonResponse
    {
        $validated = $this->periodRules($request);

        return $this->successResponse(
            $this->reports->teacher($employee->id, $validated['from'] ?? null, $validated['to'] ?? null),
            'Laporan guru berhasil dibuat'
        );
    }

    /**
     * Rapor Tahfiz — dokumen penilaian berkala per peserta.
     */
    public function rapor(Request $request, TahfizParticipant $participant): JsonResponse
    {
        $validated = $this->periodRules($request);

        return $this->successResponse(
            $this->reports->rapor($participant->id, $validated['from'] ?? null, $validated['to'] ?? null),
            'Rapor Tahfiz berhasil dibuat'
        );
    }

    protected function periodRules(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
    }
}
