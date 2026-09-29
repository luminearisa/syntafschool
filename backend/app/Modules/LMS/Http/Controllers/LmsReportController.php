<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\LmsReportService;
use App\Modules\LMS\Domain\Enums\ReportFormat;
use App\Modules\LMS\Domain\Enums\ReportKey;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Report Center (§22).
 *
 * Semua laporan membaca tabel yang sudah ada; laporan besar otomatis dijalankan
 * di antrean sehingga permintaan HTTP tidak menahan pekerjaan berat (§11).
 */
class LmsReportController extends LmsController
{
    public function __construct(private readonly LmsReportService $reports) {}

    /** GET /lms/reports */
    public function catalog(Request $request): JsonResponse
    {
        $this->authorizeReport();

        return $this->successResponse(['reports' => $this->reports->catalog()]);
    }

    /** GET /lms/reports/{key} */
    public function run(Request $request, string $key): JsonResponse
    {
        $this->authorizeReport();

        $reportKey = $this->key($key);

        $report = $this->reports->run($reportKey, $this->filters($request), (int) $request->query('limit', 500));

        return $this->successResponse($report, 'Laporan '.$reportKey->label().'.');
    }

    /** POST /lms/reports/{key}/export */
    public function export(Request $request, string $key): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeReport(export: true);

        $data = $request->validate([
            'format' => ['nullable', 'string', 'in:CSV,XLSX,PDF'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
        ]);

        $result = $this->reports->request(
            $this->key($key),
            ReportFormat::tryFrom(strtoupper((string) ($data['format'] ?? 'CSV'))) ?? ReportFormat::CSV,
            $this->filters($request),
            $actor,
            $data['idempotency_key'] ?? null,
        );

        return $this->createdResponse($result, ($result['queued'] ?? false) ? 'Ekspor laporan masuk antrean.' : 'Ekspor laporan siap.');
    }

    /** GET /lms/reports/exports */
    public function exports(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeReport(export: true);

        $onlyMine = ! $actor->can('lms.manage');

        return $this->successResponse([
            'exports' => $this->reports->exports((int) $this->perPage($request, 25), $onlyMine ? (int) $actor->id : null),
        ]);
    }

    /** GET /lms/reports/exports/{export}/download */
    public function download(Request $request, int $export): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeReport(export: true);

        $model = LmsReportExport::query()->find($export);
        abort_if($model === null, 404, 'Ekspor laporan tidak ditemukan.');

        $this->authorizeLms(
            $actor->can('lms.manage') || (int) $model->requested_by === (int) $actor->id,
            'Anda tidak berhak mengunduh laporan ini.',
        );

        $contents = $this->reports->contents($model);

        return $this->successResponse([
            'report' => $model->toPayload(),
            'contents' => $contents,
        ], 'Isi laporan.');
    }

    private function key(string $key): ReportKey
    {
        $reportKey = ReportKey::tryFrom(strtoupper($key));

        abort_if($reportKey === null, 404, 'Jenis laporan tidak dikenal.');

        return $reportKey;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return array_filter([
            'course_id' => $request->query('course_id'),
            'class_id' => $request->query('class_id'),
            'teacher_id' => $request->query('teacher_id'),
            'student_id' => $request->query('student_id'),
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function authorizeReport(bool $export = false): void
    {
        $actor = $this->actor(request());

        $allowed = $export
            ? ($actor->can('lms.report.export') || $actor->can('lms.manage'))
            : ($actor->can('lms.report.view') || $actor->can('lms.manage') || $actor->can('lms.audit.view'));

        $this->authorizeLms($allowed, 'Anda tidak berhak mengakses laporan LMS.');
    }
}
