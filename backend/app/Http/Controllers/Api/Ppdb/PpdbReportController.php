<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbExportRequest;
use App\Http\Requests\Ppdb\PpdbReportFilterRequest;
use App\Services\Ppdb\PpdbReportService;
use Barryvdh\DomPDF\PDF;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Empat grup laporan PPDB + ekspor CSV + cetak PDF.
 *
 * Rute (semua di bawah `auth:sanctum`):
 * - `GET /ppdb/reports/{registration|payment|selection|document}` → `ppdb.report.view`
 * - `GET /ppdb/export?type=...`                                   → `ppdb.report.export`
 * - `GET /ppdb/reports/{type}/pdf`                                → `ppdb.report.export`
 *
 * Bentuk respons JSON seragam untuk semua jenis:
 * `type, title, generated_at, filters, columns, rows, summary, pagination`.
 * `rows` dipaginasi (`page`, `per_page` dengan clamp di request), `summary`
 * selalu dihitung atas SELURUH himpunan terfilter — bukan halaman yang tampil.
 *
 * Jenis yang bisa diekspor/dicetak lebih luas dari laporan utama: ada dua daftar
 * datar tambahan (`applicants`, `parent_accounts`).
 */
class PpdbReportController extends BaseApiController
{
    public function __construct(protected readonly PpdbReportService $reports)
    {
    }

    /** Pendaftaran per periode/gelombang/program/rentang tanggal. */
    public function registration(PpdbReportFilterRequest $request): JsonResponse
    {
        return $this->reportResponse('registration', $request);
    }

    /** Tagihan & pembayaran tingkat invoice. */
    public function payment(PpdbReportFilterRequest $request): JsonResponse
    {
        return $this->reportResponse('payment', $request);
    }

    /** Hasil seleksi: pendaftaran × tahap, dengan rata-rata nilai per tahap. */
    public function selection(PpdbReportFilterRequest $request): JsonResponse
    {
        return $this->reportResponse('selection', $request);
    }

    /** Berkas: yang terunggah + persyaratan wajib yang BELUM ada berkasnya. */
    public function document(PpdbReportFilterRequest $request): JsonResponse
    {
        return $this->reportResponse('document', $request);
    }

    /**
     * Ekspor CSV (UTF-8 ber-BOM agar rapi dibuka Excel).
     *
     * Keamanan data: tidak ada kolom kredensial yang pernah di-select service;
     * NIK/NISN calon tetap ada karena panitia mencocokkan berkas dengannya,
     * sedangkan data orang tua hanya memuat nama + kontak untuk menindaklanjuti
     * pendaftaran. Nilai teks berawalan `=`, `+`, `-`, `@` diberi tanda kutip
     * agar tidak dieksekusi Excel (formula injection).
     */
    public function export(PpdbExportRequest $request): StreamedResponse
    {
        $dataset = $this->reports->dataset($request->type(), $request->filters());

        return response()->streamDownload(
            function () use ($dataset): void {
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                // Escape kosong → tanda kutip dalam nilai dilipat gandakan sesuai
                // RFC 4180 sehingga Excel membacanya sama dengan pembuat berkas.
                fputcsv($stream, $dataset['headers'], ',', '"', '');

                foreach ($dataset['rows'] as $row) {
                    fputcsv($stream, array_map(fn (mixed $value): mixed => $this->csvSafe($value), $row), ',', '"', '');
                }

                fclose($stream);
            },
            $dataset['filename'],
            // Content-Disposition ditambahkan sendiri oleh streamDownload() dari
            // nama berkas, jadi hanya tipe MIME yang perlu dinyatakan di sini.
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    /**
     * Cetak PDF satu jenis laporan. Batas baris dipegang service
     * (`PpdbReportService::PDF_ROW_LIMIT`) supaya berkas tetap ringan.
     */
    public function pdf(PpdbReportFilterRequest $request, string $type): Response
    {
        abort_unless(in_array($type, PpdbReportService::EXPORT_TYPES, true), 404, 'Jenis laporan tidak ditemukan.');

        return $this->pdfResponse(
            $this->reports->pdf($type, $request->filters()),
            "ppdb-{$type}-".now()->format('Ymd-His').'.pdf'
        );
    }

    /**
     * Satu bentuk laporan JSON untuk semua jenis.
     *
     * @param  string  $type  registration|payment|selection|document
     */
    protected function reportResponse(string $type, PpdbReportFilterRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->reports->report($type, $request->filters(), $request->page(), $request->perPage()),
            'Laporan '.$type.' berhasil diambil'
        );
    }

    protected function pdfResponse(PDF $pdf, string $filename): Response
    {
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /** Bentengi sel CSV dari formula injection Excel. */
    protected function csvSafe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
