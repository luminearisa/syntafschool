<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceCashAccountRequest;
use App\Http\Resources\FinanceCashAccountResource;
use App\Models\FinanceCashAccount;
use App\Services\Finance\FinanceOpeningBalanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Akun kas (§39) + saldo awal (§50).
 *
 * `current_balance` tidak pernah diedit langsung lewat update — ia digerakkan
 * ledger engine. Saldo awal diset lewat endpoint `opening-balance` yang
 * memposting jurnal double-entry (debit kas, credit ekuitas) via
 * `FinanceOpeningBalanceService`.
 */
class FinanceCashAccountController extends BaseApiController
{
    public function __construct(private readonly FinanceOpeningBalanceService $openingBalances)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = FinanceCashAccount::query()
            ->with(['account', 'costCenter'])
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceCashAccountResource::collection($query->get()), 'Daftar akun kas berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceCashAccountResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar akun kas berhasil diambil',
        );
    }

    public function show(FinanceCashAccount $cashAccount): JsonResponse
    {
        $cashAccount->load(['account', 'costCenter']);

        return $this->successResponse(new FinanceCashAccountResource($cashAccount), 'Detail akun kas berhasil diambil');
    }

    public function store(FinanceCashAccountRequest $request): JsonResponse
    {
        $cash = FinanceCashAccount::create([
            ...$request->validated(),
            'opening_balance' => 0,
            'current_balance' => 0,
        ]);

        return $this->createdResponse(new FinanceCashAccountResource($cash->load(['account', 'costCenter'])), 'Akun kas berhasil dibuat');
    }

    public function update(FinanceCashAccountRequest $request, FinanceCashAccount $cashAccount): JsonResponse
    {
        $data = $request->validated();
        // Saldo tidak boleh diset lewat update — hanya lewat ledger/opening balance.
        unset($data['opening_balance'], $data['current_balance']);

        $cashAccount->update($data);

        return $this->successResponse(new FinanceCashAccountResource($cashAccount->fresh(['account', 'costCenter'])), 'Akun kas berhasil diperbarui');
    }

    public function destroy(FinanceCashAccount $cashAccount): JsonResponse
    {
        if ((float) $cashAccount->current_balance !== 0.0) {
            throw ValidationException::withMessages([
                'cash_account' => 'Akun kas masih punya saldo. Kosongkan (pindahkan/adjustment) sebelum menghapus.',
            ]);
        }

        $cashAccount->delete();

        return $this->successResponse(null, 'Akun kas berhasil dihapus');
    }

    /** Set saldo awal (§50) — memposting jurnal opening balance via ledger engine. */
    public function openingBalance(Request $request, FinanceCashAccount $cashAccount): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $transaction = $this->openingBalances->set(
            $cashAccount,
            (float) $data['amount'],
            isset($data['date']) ? Carbon::parse($data['date']) : null,
            $request->user(),
            $data['note'] ?? null,
        );

        return $this->successResponse([
            'cash_account' => new FinanceCashAccountResource($cashAccount->fresh(['account', 'costCenter'])),
            'transaction_number' => $transaction->transaction_number,
        ], 'Saldo awal kas berhasil diposting');
    }
}
