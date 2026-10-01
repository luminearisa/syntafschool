<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetAuditService;
use App\Modules\Asset\Application\Services\AssetKpiService;
use App\Modules\Asset\Application\Services\AssetValuationService;
use App\Modules\Asset\Domain\Enums\AuditAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetDashboardController extends AssetControllerBase
{
    public function __construct(
        private readonly AssetKpiService $kpi,
        private readonly AssetValuationService $valuation,
        private readonly AssetAuditService $audit,
    ) {}

    public function assets(Request $request): JsonResponse
    {
        return $this->ok($this->kpi->assetDashboard(), 'KPI aset.');
    }

    public function maintenance(Request $request): JsonResponse
    {
        return $this->ok($this->kpi->maintenanceDashboard(), 'KPI pemeliharaan.');
    }

    public function facilities(Request $request): JsonResponse
    {
        return $this->ok($this->kpi->facilityDashboard(), 'KPI fasilitas.');
    }

    public function stockOpnames(Request $request): JsonResponse
    {
        return $this->ok($this->kpi->stockOpnameSummary(), 'KPI stok opname.');
    }

    public function reconciliation(Request $request): JsonResponse
    {
        return $this->ok($this->kpi->reconciliation(), 'Rekonsiliasi aset.');
    }

    public function report(Request $request, string $kind): JsonResponse
    {
        return $this->ok($this->kpi->report($kind, $request->only(['from', 'to', 'status', 'condition', 'category_id', 'location_id'])), 'Laporan '.$kind.'.');
    }

    /**
     * Ekspor laporan (CSV).
     *
     * Ekspor SELALU tercatat pada jejak audit: berisi data yang keluar dari sistem,
     * dan pertanyaan "siapa mengunduh daftar aset lengkap dengan nilainya" harus
     * punya jawaban.
     */
    public function export(Request $request, string $kind): StreamedResponse
    {
        $rows = $this->kpi->exportRows($kind, $request->only(['from', 'to', 'status', 'condition', 'category_id', 'location_id']));

        $this->audit->logAccess(AuditAction::EXPORT_CREATED, new \App\Modules\Asset\Models\AssetImport(), true, 'Ekspor laporan '.$kind, $this->actor($request));

        $headers = array_keys($rows[0] ?? ['kosong' => null]);
        $filename = 'asset-'.$kind.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $headers): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value), $row));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function depreciationPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
        ]);

        return $this->ok($this->valuation->preview($data['period'], $data['asset_id'] ?? null), 'Pratinjau penyusutan (belum mengubah apa pun).');
    }

    public function depreciationRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
        ]);

        return $this->ok($this->valuation->run($this->actor($request), $data['period'], $data['asset_id'] ?? null), 'Penyusutan dijalankan (satu kali per aset per periode).');
    }
}
