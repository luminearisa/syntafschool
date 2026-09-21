<?php

namespace App\Http\Controllers\Api;

use App\Models\Library;
use App\Services\Library\Reports\LibraryImportService;
use App\Services\Library\Reports\LibraryReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan, ekspor, dan impor modul Library (§3, §4, §5).
 *
 * Ekspor memakai CSV (kompatibel dibuka Excel). Proyek ini belum memiliki
 * paket XLSX/PDF, jadi formatnya CSV — bukan framework ekspor baru.
 */
class LibraryReportController extends BaseApiController
{
    public function __construct(
        protected LibraryReportService $reports,
        protected LibraryImportService $imports,
    ) {
    }

    /** Laporan koleksi. */
    public function collection(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->collection($request->all()), 'Laporan koleksi berhasil diambil');
    }

    /** Laporan sirkulasi. */
    public function circulation(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->circulation($request->all()), 'Laporan sirkulasi berhasil diambil');
    }

    /** Laporan anggota. */
    public function member(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->member($request->all()), 'Laporan anggota berhasil diambil');
    }

    /** Laporan denda. */
    public function fine(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->fine($request->all()), 'Laporan denda berhasil diambil');
    }

    /** Laporan pengadaan. */
    public function acquisition(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->acquisition($request->all()), 'Laporan pengadaan berhasil diambil');
    }

    /** Laporan inventaris & stock opname. */
    public function inventory(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->inventory($request->all()), 'Laporan inventaris berhasil diambil');
    }

    /** Laporan reservasi. */
    public function reservation(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        return $this->successResponse($this->reports->reservation($libraryId), 'Laporan reservasi berhasil diambil');
    }

    /**
     * Ekspor CSV (§4).
     */
    public function export(Request $request): StreamedResponse
    {
        $type = (string) $request->query('type', 'catalog');
        $dataset = $this->reports->exportDataset($type, $request->all());

        return response()->streamDownload(function () use ($dataset): void {
            $handle = fopen('php://output', 'w');

            // BOM agar Excel membaca UTF-8 dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $dataset['headers']);

            foreach ($dataset['rows'] as $row) {
                fputcsv($handle, $dataset['map']($row));
            }

            fclose($handle);
        }, "library-{$type}-".now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Template CSV impor (§5). */
    public function template(string $type): StreamedResponse
    {
        $template = $this->imports->template($type);

        return response()->streamDownload(function () use ($template): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $template['headers']);
            fputcsv($handle, $template['sample']);
            fclose($handle);
        }, "template-import-{$type}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Pratinjau impor: total/valid/invalid/duplikat + error per baris.
     */
    public function preview(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'library_id' => ['required', 'integer', 'exists:libraries,id'],
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $library = Library::query()->findOrFail($validated['library_id']);

        $parsed = $this->imports->parseCsv($request->file('file')->getRealPath());
        $report = $this->imports->preview($type, $parsed, $library);

        return $this->successResponse($report, 'Pratinjau impor berhasil dibuat');
    }

    /**
     * Simpan hanya baris valid (§5).
     */
    public function commit(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'library_id' => ['required', 'integer', 'exists:libraries,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.data' => ['required', 'array'],
            'rows.*.errors' => ['nullable', 'array'],
        ]);

        $library = Library::query()->findOrFail($validated['library_id']);

        $result = $this->imports->commit($type, $validated['rows'], $library);

        return $this->successResponse(
            $result,
            "{$result['imported']} baris berhasil diimpor".($result['skipped'] > 0 ? ", {$result['skipped']} dilewati" : '')
        );
    }
}