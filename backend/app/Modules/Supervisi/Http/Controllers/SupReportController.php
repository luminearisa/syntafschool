<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\AnalyticsService;
use App\Modules\Supervisi\Application\Services\ReportService;
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
 */
class SupReportController extends SupController
{
    public function __construct(
        protected AnalyticsService $analytics,
        protected ReportService $reports,
    ) {
    }

    /** Dasbor analitik: cakupan, nilai, indikator lemah, disiplin RTL, penilai. */
    public function analytics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $this->successResponse(
            $this->analytics->overview($data['from'] ?? null, $data['to'] ?? null),
            'Analitik supervisi',
        );
    }

    /** Daftar laporan yang tersedia beserta periode bawaannya. */
    public function catalog(): JsonResponse
    {
        return $this->successResponse([
            'reports' => collect(ReportService::REPORTS)
                ->map(fn (string $title, string $key) => ['key' => $key, 'title' => $title])
                ->values()
                ->all(),
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

        $table = $this->reports->build($data['report_key'], $data);

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
    public function history(): JsonResponse
    {
        return $this->successResponse([
            'rows' => $this->reports->history()->map(fn (SupReport $report) => [
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
