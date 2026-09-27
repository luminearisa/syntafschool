<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceJournalReverseRequest;
use App\Http\Resources\FinancialTransactionResource;
use App\Models\FinancialTransaction;
use App\Services\Finance\FinanceJournalService;
use App\Services\Finance\JournalPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jurnal umum (§3–§6, §34, §38, §47).
 *
 * TIDAK ada tabel jurnal kedua: `finance_transactions` adalah jurnalnya (§71),
 * jadi `/finance/journals` dan `/finance/transactions` membaca query yang sama
 * lewat `FinanceJournalService`. Yang membedakan hanya hak baca — operator
 * pencatat melihat hasil catatnya (`finance.transaction.view`), pemosting
 * jurnal dan auditor melihat jurnalnya (`finance.journal.view`).
 *
 * Tidak ada route edit/hapus di sini, dan memang tidak boleh ada (§41): satu-
 * satunya cara mengoreksi jurnal posted adalah `reverse()`, yang membuat jurnal
 * baru dan menautkan keduanya dua arah. Jurnal yang menggerakkan kas/bank
 * ditolak di sini — pembalikannya harus lewat dokumen sumbernya supaya saldo
 * rekening ikut bergerak (`JournalPostingService::reverse()`).
 */
class FinanceJournalController extends BaseApiController
{
    public function __construct(
        private readonly FinanceJournalService $journals,
        private readonly JournalPostingService $posting,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->query();
        $query = $this->journals->query($filters);

        if ($request->boolean('all')) {
            return $this->successResponse(
                FinancialTransactionResource::collection($query->get())
                    ->additional(['meta' => ['totals' => $this->journals->sums($filters)]]),
                'Daftar jurnal berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinancialTransactionResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => [
                    'types' => FinancialTransaction::TYPES,
                    'type_labels' => FinancialTransaction::TYPE_LABELS,
                    'statuses' => [FinancialTransaction::STATUS_POSTED, FinancialTransaction::STATUS_REVERSED],
                    'documents' => FinanceJournalService::documentKeys(),
                    'totals' => $this->journals->sums($filters),
                ]]),
            'Daftar jurnal berhasil diambil',
        );
    }

    /** `GET /finance/transactions/{transaction}` — nama parameter ikut jalurnya. */
    public function showTransaction(Request $request, FinancialTransaction $transaction): JsonResponse
    {
        return $this->show($request, $transaction);
    }

    public function show(Request $request, FinancialTransaction $journal): JsonResponse
    {
        return $this->successResponse(
            new FinancialTransactionResource($this->withDetail($journal)),
            'Detail jurnal berhasil diambil',
        );
    }

    /**
     * Rantai penelusuran §38: JRN → pembayaran → kuitansi → invoice → tagihan
     * → baris item → santri, plus semua jurnal lain yang lahir dari dokumen
     * yang sama.
     */
    public function trace(Request $request, FinancialTransaction $journal): JsonResponse
    {
        return $this->successResponse([
            'journal' => new FinancialTransactionResource($this->withDetail($journal)),
            'trace' => $this->journals->trace($journal),
        ], 'Penelusuran jurnal berhasil diambil');
    }

    /** Buat jurnal pembalik (§51). Jurnal asli tetap utuh, hanya ditandai reversed. */
    public function reverse(FinanceJournalReverseRequest $request, FinancialTransaction $journal): JsonResponse
    {
        $counter = $this->posting->reverse($journal, (string) $request->input('reason'), $request->user());

        return $this->createdResponse(
            new FinancialTransactionResource($this->withDetail($counter)),
            'Jurnal pembalik berhasil dibuat',
        );
    }

    /**
     * Relasi yang dibutuhkan layar detail jurnal. `lines.account` membawa sisi
     * normal saldo supaya arah debit/kredit bisa dibaca tanpa query tambahan.
     */
    private function withDetail(FinancialTransaction $journal): FinancialTransaction
    {
        return $journal->load([
            'lines.account:id,code,name,type,normal_balance',
            'lines.costCenter:id,name',
            'costCenter:id,name',
            'period:id,code,name',
            'createdBy:id,name',
            'postedBy:id,name',
            'reference',
            'reversal:id,transaction_number,status,transaction_date',
            'reversals:id,transaction_number,status,transaction_date,reversed_by_transaction_id',
        ]);
    }
}
