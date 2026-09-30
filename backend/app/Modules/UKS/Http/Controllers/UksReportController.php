<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Modules\UKS\Application\Services\UksHealthService;
use App\Modules\UKS\Application\Services\UksReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UksReportController extends UksController
{
    public function __construct(
        private readonly UksReportService $reports,
        private readonly UksHealthService $health,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $this->successResponse(
            $this->reports->dashboard($request->user(), $data['from'] ?? null, $data['to'] ?? null),
            'Ringkasan UKS.',
        );
    }

    public function health(Request $request): JsonResponse
    {
        return $this->successResponse($this->health->report(), 'Pemeriksaan kesehatan data UKS.');
    }

    /** Ekspor laporan harian kunjungan sebagai CSV. */
    public function dailyCsv(Request $request): StreamedResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);
        $rows = $this->reports->dailyRows($request->user(), $data['date']);

        $headers = ['Kode', 'Nama', 'NIS', 'Kelas', 'Jenis', 'Keluhan', 'Tingkat', 'Status', 'Tiba', 'Dipulangkan'];

        return response()->streamDownload(function () use ($rows, $headers): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['code'], $row['full_name'], $row['nis'], $row['class_name'],
                    $row['visit_type'], $row['complaint'], $row['severity'], $row['status'],
                    $row['arrived_at'], $row['sent_home'] ? 'Ya' : 'Tidak',
                ]);
            }
            fclose($out);
        }, 'uks-harian-'.$data['date'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
