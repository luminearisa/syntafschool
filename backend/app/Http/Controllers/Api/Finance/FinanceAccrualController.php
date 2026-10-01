<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\FinanceAccrual;
use App\Services\Finance\Accounting\AccrualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Akrual: rancangan, posting, dan pembalikan otomatis pada tanggal baliknya.
 */
class FinanceAccrualController extends BaseApiController
{
    public function __construct(private readonly AccrualService $accruals) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse([
            'accruals' => $this->accruals->all($request->query()),
            'due_for_reversal' => FinanceAccrual::query()->dueForReversal()->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        return $this->createdResponse($this->accruals->save($data, null, $request->user()), 'Akrual dibuat sebagai rancangan.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        FinanceAccrual::query()->findOrFail($id);

        return $this->successResponse($this->accruals->save($request->validate($this->rules(false)), $id, $request->user()), 'Akrual diperbarui.');
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $accrual = FinanceAccrual::query()->findOrFail($id);

        return $this->successResponse([
            'transaction' => $this->accruals->post($accrual, $request->user()),
            'accrual' => $accrual->refresh(),
        ], 'Akrual diposting.');
    }

    public function reverseDue(Request $request): JsonResponse
    {
        $data = $request->validate(['tanggal' => ['nullable', 'date']]);

        $hasil = $this->accruals->reverseDue(
            $data['tanggal'] ?? null ? \Illuminate\Support\Carbon::parse($data['tanggal']) : null,
            $request->user(),
        );

        return $this->successResponse($hasil, $hasil['dibalik'] > 0
            ? $hasil['dibalik'].' akrual dibalik pada tanggal baliknya.'
            : 'Tidak ada akrual yang jatuh tempo hari ini.');
    }

    /** @return array<string, mixed> */
    protected function rules(bool $wajib = true): array
    {
        $required = $wajib ? 'required' : 'nullable';

        return [
            'kode' => [$required, 'string', 'max:30'],
            'tanggal' => [$required, 'date'],
            'tanggal_balik' => [$required, 'date', 'after:tanggal'],
            'jenis' => [$required, 'in:pendapatan,beban'],
            'akun_akrual_id' => [$required, 'integer', 'exists:finance_accounts,id'],
            'akun_lawan_id' => [$required, 'integer', 'exists:finance_accounts,id'],
            'jumlah' => [$required, 'numeric', 'gt:0'],
            'deskripsi' => ['nullable', 'string', 'max:500'],
            'kode_unit' => ['nullable', 'string', 'exists:finance_business_units,code'],
            'kode_dana' => ['nullable', 'string', 'exists:finance_funds,kode_dana'],
        ];
    }
}
