<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\AnalyticsService;
use App\Modules\Supervisi\Application\Services\DataScopeService;
use App\Modules\Supervisi\Application\Services\ReportService;
use App\Modules\Supervisi\Application\Services\SupervisiHealthService;
use App\Modules\Supervisi\Models\SupReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analitik & laporan supervisi (Fase 4).
 *
 * Baca analitik memakai izin `supervisi.report.view`; mengunduh/menyimpan
 * dokumen memakai `supervisi.report.export` supaya rekap nilai tidak bocor ke
 * layar yang hanya boleh melihat.
 *
 * Izin hanya menentukan "boleh membuka layar"; **cakupan data** (seluruh
 * sekolah atau dirinya) ditentukan `DataScopeService` mengikuti blueprint
 * §11.6 — guru dan staf memegang `report.view` untuk membaca hasilnya sendiri,
 * bukan nilai rekan sejawat.
 */
class SupReportController extends SupController
{
    public function __construct(
        protected AnalyticsService $analytics,
        protected ReportService $reports,
        protected DataScopeService $scopes,
        protected SupervisiHealthService $health,
    ) {
    }

    /** Dasbor analitik: cakupan, nilai, indikator lemah, disiplin RTL, penilai. */
    public function analytics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $scope = $this->scopes->resolve($this->actor($request));

        return $this->successResponse(
            $this->analytics->overview($data['from'] ?? null, $data['to'] ?? null, $scope),
            $scope->isSchool() ? 'Analitik supervisi sekolah' : 'Analitik supervisi Anda',
        );
    }

    /**
     * Kesehatan & integritas data supervisi (Fase 5).
     *
     * Dipakai admin/pengawas untuk memastikan angka yang dipakai rapat berasal
     * dari data yang sehat — bukan sekadar tidak ada galat di layar.
     */
    public function health(): JsonResponse
    {
        return $this->successResponse($this->health->report(), 'Kesehatan modul supervisi');
    }

    /** Daftar laporan yang tersedia beserta periode bawaannya. */
    public function catalog(Request $request): JsonResponse
    {
        $scope = $this->scopes->resolve($this->actor($request));
        $allowed = $scope->allowsPeerSummary()
            ? array_keys(ReportService::REPORTS)
            : array_values(array_diff(array_keys(ReportService::REPORTS), ReportService::AGGREGATE_REPORTS));

        return $this->successResponse([
            'reports' => collect(ReportService::REPORTS)
                ->only($allowed)
                ->map(fn (string $title, string $key) => ['key' => $key, 'title' => $title])
                ->values()
                ->all(),
            'scope' => $scope->payload(),
            'restricted' => array_values(array_intersect(ReportService::AGGREGATE_REPORTS, array_keys(array_diff_key(ReportService::REPORTS, array_flip($allowed))))),
            'formats' => ['CSV', 'XLSX', 'PDF', 'JSON'],
            'default_window' => [
                'from' => now()->startOfYear()->toDateString(),
                'to' => now()->endOfYear()->toDateString(),
            ],
        ], 'Katalog laporan supervisi');
    }

    /** Pratinjau laporan (tabel + ringkasan) tanpa menyimpan berkas. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_key' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $table = $this->reports->build($data['report_key'], $data, $this->scopes->resolve($this->actor($request)));

        return $this->successResponse([
            'report' => [
                'key' => $table['key'],
                'title' => $table['title'],
                'window' => $table['window'],
            ],
            'headers' => $table['headers'],
            'rows' => $table['rows'],
            'summary' => $table['summary'],
        ], 'Pratinjau laporan');
    }

    /** Riwayat dokumen yang dihasilkan (beserta kode verifikasi). */
    public function history(Request $request): JsonResponse
    {
        $scope = $this->scopes->resolve($this->actor($request));

        return $this->successResponse([
            'scope' => $scope->payload(),
            'rows' => $this->reports->history(60, $scope)->map(fn (SupReport $report) => [
                'id' => $report->id,
                'title' => $report->title,
                'report_key' => $report->report_key,
                'format' => $report->format,
                'rows_count' => $report->rows_count,
                'verification_code' => $report->verification_code,
                'verification_url' => $report->verificationUrl(),
                'checksum' => $report->checksum,
                'status' => $report->status,
                'generated_at' => $report->completed_at?->toIso8601String(),
                'generated_by' => $report->requester?->name,
                'parameters' => $report->parameters,
            ])->all(),
        ], 'Riwayat dokumen supervisi');
    }

    /** Hasilkan & simpan dokumen, kembalikan tautan unduhannya. */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_key' => ['required', 'string'],
            'format' => ['required', 'in:CSV,XLSX,PDF,JSON,csv,xlsx,pdf,json'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        // Dokumen atas nama pegawai lain tidak boleh dibuat oleh akun berizik
        // "diri" meski ia memegang izin ekspor.
        $this->reports->build($data['report_key'], $data, $this->scopes->resolve($this->actor($request)));

        $report = $this->reports->generate(
            $data['report_key'],
            $data['format'],
            $data,
            $this->actor($request),
        );

        return $this->successResponse([
            'id' => $report->id,
            'title' => $report->title,
            'format' => $report->format,
            'rows_count' => $report->rows_count,
            'verification_code' => $report->verification_code,
            'verification_url' => $report->verificationUrl(),
            'checksum' => $report->checksum,
            'download_url' => url('/api/v1/supervisi/reports/'.$report->id.'/download'),
        ], 'Dokumen dibuat', 201);
    }

    /** Unduh berkas dokumen yang pernah dihasilkan. */
    public function download(SupReport $report): StreamedResponse
    {
        abort_unless($report->path !== null && Storage::disk('local')->exists($report->path), 404, 'Berkas tidak ditemukan.');

        $extension = strtolower($report->format);
        $filename = 'supervisi-'.$report->report_key.'-'.($report->verification_code ?? $report->id).'.'.$extension;

        return Storage::disk('local')->download($report->path, $filename, [
            'Content-Type' => $this->mime($report->format),
        ]);
    }

    /**
     * Verifikasi dokumen (kode publik). Dapat dipakai tanpa masuk agar salinan
     * cetak dari pihak luar pun dapat dibuktikan — hanya keterangan dokumen yang
     * diungkap, bukan isinya.
     */
    public function verify(string $code): JsonResponse
    {
        $result = $this->reports->verify($code);

        return $this->successResponse($result, $result['message'], $result['valid'] ? 200 : 404);
    }

    protected function mime(string $format): string
    {
        return match (strtoupper($format)) {
            'CSV' => 'text/csv; charset=UTF-8',
            'XLSX' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'PDF' => 'application/pdf',
            default => 'application/json',
        };
    }
}
