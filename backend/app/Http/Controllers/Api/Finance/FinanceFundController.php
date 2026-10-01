<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\FinanceFund;
use App\Services\Finance\Accounting\FundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dana (fund accounting).
 *
 * Dana bebas dipakai untuk apa saja; dana terikat hanya untuk belanja yang masuk
 * daftar peruntukannya. Yang dibatasi penjaga peruntukan hanya baris beban —
 * lihat `DanaPolicy`.
 */
class FinanceFundController extends BaseApiController
{
    public function __construct(private readonly FundService $funds) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse([
            'funds' => $this->funds->all($request->boolean('active_only')),
            'types' => ['bebas', 'terikat', 'wakaf', 'zakat', 'infaq', 'sedekah', 'temporer'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kode_dana' => ['required', 'string', 'max:30'],
            'nama_dana' => ['required', 'string', 'max:120'],
            'jenis' => ['required', 'string', 'in:bebas,terikat,wakaf,zakat,infaq,sedekah,temporer'],
            'peruntukan' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', 'in:aktif,nonaktif'],
            'keterangan' => ['nullable', 'string'],
        ]);

        return $this->createdResponse($this->funds->save($data), 'Dana dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nama_dana' => ['nullable', 'string', 'max:120'],
            'jenis' => ['nullable', 'string', 'in:bebas,terikat,wakaf,zakat,infaq,sedekah,temporer'],
            'peruntukan' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', 'in:aktif,nonaktif'],
            'keterangan' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->funds->save($data, $id), 'Dana diperbarui.');
    }

    public function syncAccounts(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'account_ids' => ['present', 'array'],
            'account_ids.*' => ['integer', 'exists:finance_accounts,id'],
            'contra_account_ids' => ['nullable', 'array'],
            'contra_account_ids.*' => ['integer', 'exists:finance_accounts,id'],
        ]);

        $fund = FinanceFund::query()->findOrFail($id);
        $fund = $this->funds->syncAccounts($fund, $data['account_ids']);

        if (array_key_exists('contra_account_ids', $data) && $data['contra_account_ids'] !== null) {
            $fund = $this->funds->syncContraAccounts($fund, $data['contra_account_ids']);
        }

        return $this->successResponse($fund->loadCount(['accounts', 'contraAccounts']), 'Daftar akun dana disimpan.');
    }

    public function destroy(int $id): JsonResponse
    {
        $fund = FinanceFund::query()->findOrFail($id);
        $this->funds->delete($fund);

        return $this->successResponse(null, 'Dana dihapus.');
    }
}
