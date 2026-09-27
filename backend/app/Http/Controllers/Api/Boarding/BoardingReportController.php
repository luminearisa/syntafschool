<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Boarding\BoardingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Laporan modul asrama (§43–§44). `index` untuk tampilan tabel, `export`
 * untuk berkas CSV — keduanya membaca `BoardingReportService::rows()` yang
 * sama supaya angka layar dan ekspor tidak pernah berbeda.
 */
class BoardingReportController extends BaseApiController
{
    public function __construct(private readonly BoardingReportService $reports)
    {
    }

    /** Daftar jenis laporan yang tersedia untuk UI. */
    public function kinds(): JsonResponse
    {
        return $this->successResponse(
            array_map(
                static fn (string $kind, string $label): array => ['kind' => $kind, 'label' => $label],
                array_keys(BoardingReportService::kinds()),
                array_values(BoardingReportService::kinds()),
            ),
            'Daftar jenis laporan berhasil diambil',
        );
    }

    public function show(Request $request, string $kind): JsonResponse
    {
        $this->assertKind($kind);

        $filters = $request->query();
        $rows = $this->reports->rows($kind, $filters, $request->user());

        return $this->successResponse([
            'kind' => $kind,
            'label' => BoardingReportService::kinds()[$kind],
            'headers' => $this->reports->headers($kind),
            'total' => count($rows),
            'rows' => $rows,
        ], 'Laporan berhasil diambil');
    }

    public function export(Request $request, string $kind): Response
    {
        $this->assertKind($kind);

        $rows = $this->reports->rows($kind, $request->query(), $request->user());
        $headers = $this->reports->headers($kind);

        $handle = fopen('php://temp', 'rw+');

        fwrite($handle, "\xEF\xBB\xBF"); // BOM: Excel membaca UTF-8 dengan benar
        fputcsv($handle, array_values($headers), ';', '"', '\\');

        foreach ($rows as $row) {
            $line = [];

            foreach (array_keys($headers) as $key) {
                $value = $row[$key] ?? null;

                $line[] = match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? 'Ya' : 'Tidak',
                    default => (string) $value,
                };
            }

            fputcsv($handle, $line, ';', '"', '\\');
        }

        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->reports->fileName($kind, $request->query()).'"',
        ]);
    }

    protected function assertKind(string $kind): void
    {
        if (! BoardingReportService::supports($kind)) {
            abort(404, 'Jenis laporan tidak dikenal: '.$kind);
        }
    }
}
