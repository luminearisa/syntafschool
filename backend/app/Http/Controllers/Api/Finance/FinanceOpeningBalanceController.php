<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\FinanceOpeningBalance;
use App\Services\Finance\Accounting\OpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saldo awal per akun.
 *
 * Rancangan boleh diubah berkali-kali; begitu diposting, ia menjadi jurnal
 * pembuka yang terkunci. Jurnal pembuka wajib seimbang — layanan ini menolak dan
 * menyebutkan selisihnya, bukan menyelipkan selisih ke akun lain.
 */
class FinanceOpeningBalanceController extends BaseApiController
{
    public function __construct(private readonly OpeningBalanceService $opening) {}

    public function index(): JsonResponse
    {
        return $this->successResponse([
            'balances' => $this->opening->all(),
            'summary' => $this->opening->summary(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.account_id' => ['required', 'integer', 'exists:finance_accounts,id'],
            'entries.*.jenis_saldo' => ['required', 'in:debet,kredit'],
            'entries.*.saldo' => ['required', 'numeric', 'min:0'],
            'entries.*.keterangan' => ['nullable', 'string', 'max:500'],
        ]);

        $this->opening->save($data['entries'], $request->user());

        return $this->successResponse([
            'balances' => $this->opening->all(),
            'summary' => $this->opening->summary(),
        ], 'Rancangan saldo awal disimpan.');
    }

    public function destroy(int $id): JsonResponse
    {
        $balance = FinanceOpeningBalance::query()->findOrFail($id);
        $this->opening->remove($balance);

        return $this->successResponse($this->opening->summary(), 'Saldo awal dihapus dari rancangan.');
    }

    public function post(Request $request): JsonResponse
    {
        $data = $request->validate(['tanggal' => ['nullable', 'date']]);

        $transaction = $this->opening->post($request->user(), $data['tanggal'] ?? null);

        return $this->successResponse([
            'transaction' => $transaction,
            'summary' => $this->opening->summary(),
        ], 'Saldo awal diposting menjadi jurnal pembuka.');
    }
}
