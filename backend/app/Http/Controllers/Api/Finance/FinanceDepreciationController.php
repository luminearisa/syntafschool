<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Finance\Accounting\DepreciationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penyusutan aset tetap per periode.
 *
 * `preview` disediakan lebih dulu dengan sengaja: menjalankan penyusutan untuk
 * ratusan aset tanpa melihat akibatnya adalah cara cepat memposting jurnal yang
 * tidak diinginkan — dan jurnal yang sudah diposting tidak dapat dihapus.
 */
class FinanceDepreciationController extends BaseApiController
{
    public function __construct(private readonly DepreciationService $penyusutan) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'periode' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return $this->successResponse($this->penyusutan->preview($data['periode']));
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'periode' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'asset_id' => ['nullable', 'integer', 'exists:finance_assets,id'],
        ]);

        $hasil = $this->penyusutan->run($data['periode'], $request->user(), $data['asset_id'] ?? null);

        return $this->successResponse($hasil, $hasil['diposting'] > 0
            ? 'Penyusutan periode diposting untuk '.$hasil['diposting'].' aset.'
            : 'Tidak ada aset yang perlu disusutkan pada periode ini (sudah diproses atau nilai tersusut sudah habis).');
    }
}
