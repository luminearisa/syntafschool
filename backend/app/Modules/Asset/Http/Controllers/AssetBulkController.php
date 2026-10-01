<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetBulkService;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetBulkController extends AssetControllerBase
{
    public function __construct(private readonly AssetBulkService $bulk) {}

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
            'status' => ['required', 'string', 'max:24'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->ok(
            $this->bulk->changeStatus($this->actor($request), $data['asset_ids'], AssetStatus::from($data['status']), $data['reason'] ?? null),
            'Operasi massal selesai — periksa ringkasan hasilnya.',
        );
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
            'custodian_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        return $this->ok(
            $this->bulk->assign($this->actor($request), $data['asset_ids'], (int) $data['custodian_id'], $data['location_id'] ?? null),
            'Penugasan massal selesai.',
        );
    }

    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
            'inspection_type' => ['nullable', 'string', 'max:32'],
        ]);

        return $this->ok(
            $this->bulk->inspect($this->actor($request), $data['asset_ids'], $data['inspection_type'] ?? 'ROUTINE'),
            'Inspeksi massal dijadwalkan.',
        );
    }

    public function printLabels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
        ]);

        return $this->ok($this->bulk->markPrinted($this->actor($request), $data['asset_ids']), 'Label massal ditandai tercetak.');
    }
}
