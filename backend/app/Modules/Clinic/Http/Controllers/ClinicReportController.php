<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\ClinicReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pusat laporan klinik.
 *
 * Dua pagar privasi yang dipegang di sini:
 *
 * 1. Setiap laporan dihitung **dalam cakupan pengguna** — orang tua hanya
 *    menerima data anaknya meski memanggil laporan yang sama.
 * 2. Ekspor data pasien dicatat di jejak audit (aksi EXPORT) sehingga dapat
 *    ditelusuri bila terjadi kebocoran berkas.
 */
class ClinicReportController extends BaseApiController
{
    public function __construct(
        private readonly ClinicReportService $reports,
        private readonly ClinicAccessService $access,
    ) {
    }

    public function daily(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->daily($request->user(), $this->filters($request)));
    }

    public function medications(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->medicationUsage(
            $request->user(),
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function referrals(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->referrals(
            $request->user(),
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function emergencies(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->emergencies(
            $request->user(),
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function screenings(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->screenings(
            $request->user(),
            $request->query('program_id') === null ? null : (int) $request->query('program_id'),
        ));
    }

    public function followUps(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->followUps($request->user(), $request->query('status')));
    }

    public function staffActivity(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->staffActivity(
            $request->user(),
            $request->query('from'),
            $request->query('to'),
        ));
    }

    /**
     * Laporan akses rekam medis — hanya untuk pengguna ber-izin audit.
     * Inilah alat kepatuhan: siapa membuka rekam medis siapa, dan kapan.
     */
    public function accessLog(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermissionTo('clinic.audit.view')) {
            return $this->errorResponse('Anda tidak berwenang melihat laporan akses rekam medis.', 403);
        }

        return $this->successResponse($this->reports->accessLog(
            $request->user(),
            $request->query('patient_id') === null ? null : (int) $request->query('patient_id'),
            min(500, max(20, (int) $request->query('limit', 200))),
        ));
    }

    /** Ringkasan umum lintas laporan (untuk layar laporan). */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $this->filters($request);

        return $this->successResponse([
            'daily' => $this->reports->daily($user, $filters),
            'top_complaints' => $this->reports->topComplaints($user, 10, $filters['from'] ?? null, $filters['to'] ?? null),
            'top_diagnoses' => $this->reports->topDiagnoses($user, 10),
            'scope' => [
                'patient_level' => $this->access->canSeePatientLevelReport($user),
                'can_export' => $this->access->canExport($user),
            ],
        ]);
    }

    /** Ekspor CSV untuk seluruh jenis laporan. */
    public function export(Request $request, string $report): StreamedResponse|JsonResponse
    {
        $user = $request->user();

        if (! $this->access->canExport($user)) {
            return $this->errorResponse('Anda tidak berwenang mengekspor laporan klinik.', 403);
        }

        [$rows, $filters] = match ($report) {
            'daily' => [($this->reports->daily($user, $this->filters($request)))['rows'], $this->filters($request)],
            'medications' => [$this->reports->medicationUsage($user, $request->query('from'), $request->query('to'), 500), []],
            'referrals' => [($this->reports->referrals($user, $request->query('from'), $request->query('to')))['rows'], []],
            'emergencies' => [($this->reports->emergencies($user, $request->query('from'), $request->query('to')))['rows'], []],
            'screenings' => [($this->reports->screenings($user))['rows'], []],
            'follow-ups' => [($this->reports->followUps($user))['rows'], []],
            'staff-activity' => [$this->reports->staffActivity($user), []],
            'access-log' => $this->access->canSeePatientLevelReport($user)
                ? [$this->reports->accessLog($user), []]
                : [[], []],
            default => [null, []],
        };

        if ($rows === null) {
            return $this->errorResponse('Jenis laporan tidak dikenal.', 404);
        }

        $this->reports->auditExport($user, $report, $filters);

        $csv = $this->reports->toCsv($rows);
        $filename = sprintf('laporan-clinic-%s-%s.csv', $report, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return array_filter([
            'date' => $request->query('date'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'clinic_id' => $request->query('clinic_id'),
            'visit_type' => $request->query('visit_type'),
            'priority' => $request->query('priority'),
            'status' => $request->query('status'),
            'class_id' => $request->query('class_id'),
            'provider_id' => $request->query('provider_id'),
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
