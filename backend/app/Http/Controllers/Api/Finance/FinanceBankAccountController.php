<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceBankAccountRequest;
use App\Http\Resources\FinanceBankAccountResource;
use App\Models\FinanceBankAccount;
use App\Services\Finance\FinanceOpeningBalanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Rekening bank (§40) + saldo awal (§50).
 *
 * `bank_id` menunjuk master `banks` existing (§71). `current_balance` digerakkan
 * ledger engine; saldo awal lewat endpoint `opening-balance` yang memposting
 * jurnal double-entry, bukan menimpa kolom langsung.
 */
class FinanceBankAccountController extends BaseApiController
{
    public function __construct(private readonly FinanceOpeningBalanceService $openingBalances)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = FinanceBankAccount::query()
            ->with(['bank', 'financeAccount', 'costCenter'])
            ->when($request->filled('account_type'), fn (Builder $q) => $q->where('account_type', $request->string('account_type')))
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%")
                    ->orWhere('account_number', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceBankAccountResource::collection($query->get()), 'Daftar rekening bank berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceBankAccountResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['account_types' => FinanceBankAccount::TYPES, 'type_labels' => FinanceBankAccount::TYPE_LABELS]]),
            'Daftar rekening bank berhasil diambil',
        );
    }

    public function show(FinanceBankAccount $bankAccount): JsonResponse
    {
        $bankAccount->load(['bank', 'financeAccount', 'costCenter']);

        return $this->successResponse(new FinanceBankAccountResource($bankAccount), 'Detail rekening bank berhasil diambil');
    }

    public function store(FinanceBankAccountRequest $request): JsonResponse
    {
        $bank = FinanceBankAccount::create([
            ...$request->validated(),
            'opening_balance' => 0,
            'current_balance' => 0,
        ]);

        return $this->createdResponse(new FinanceBankAccountResource($bank->load(['bank', 'financeAccount', 'costCenter'])), 'Rekening bank berhasil dibuat');
    }

    public function update(FinanceBankAccountRequest $request, FinanceBankAccount $bankAccount): JsonResponse
    {
        $data = $request->validated();
        unset($data['opening_balance'], $data['current_balance']);

        $bankAccount->update($data);

        return $this->successResponse(new FinanceBankAccountResource($bankAccount->fresh(['bank', 'financeAccount', 'costCenter'])), 'Rekening bank berhasil diperbarui');
    }

    public function destroy(FinanceBankAccount $bankAccount): JsonResponse
    {
        if ((float) $bankAccount->current_balance !== 0.0) {
            throw ValidationException::withMessages([
                'bank_account' => 'Rekening masih punya saldo. Kosongkan sebelum menghapus.',
            ]);
        }

        if ($bankAccount->transactions()->exists()) {
            throw ValidationException::withMessages([
                'bank_account' => 'Rekening sudah punya transaksi bank dan tidak boleh dihapus. Nonaktifkan saja.',
            ]);
        }

        $bankAccount->delete();

        return $this->successResponse(null, 'Rekening bank berhasil dihapus');
    }

    /** Set saldo awal (§50) — memposting jurnal opening balance via ledger engine. */
    public function openingBalance(Request $request, FinanceBankAccount $bankAccount): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $transaction = $this->openingBalances->set(
            $bankAccount,
            (float) $data['amount'],
            isset($data['date']) ? Carbon::parse($data['date']) : null,
            $request->user(),
            $data['note'] ?? null,
        );

        return $this->successResponse([
            'bank_account' => new FinanceBankAccountResource($bankAccount->fresh(['bank', 'financeAccount', 'costCenter'])),
            'transaction_number' => $transaction->transaction_number,
        ], 'Saldo awal bank berhasil diposting');
    }
}
