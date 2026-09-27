<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceBankStatementRequest;
use App\Http\Resources\FinanceBankStatementLineResource;
use App\Http\Resources\FinanceBankStatementResource;
use App\Http\Resources\FinanceReconciliationResource;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinancialTransactionLine;
use App\Services\Finance\FinanceBankStatementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rekening koran bank + pencocokan (Phase 4 §4).
 *
 * Layar ini tidak menyentuh buku besar: import hanya menyimpan bukti dari sisi
 * bank, pencocokan hanya menautkan baris ke baris jurnal yang SUDAH ada, dan
 * `reconcile` membuat rekonsiliasi dari keduanya. Tidak ada endpoint yang
 * membuat jurnal — §4 melarang jurnal lahir hanya karena statement diimpor.
 *
 * Rekonsiliasi ditutup di sini hanya bila selisihnya nol. Kalau tidak, hasilnya
 * `discrepancy` dan jalurnya adalah workflow adjustment (§51), bukan tombol di
 * layar ini.
 */
class FinanceBankStatementController extends BaseApiController
{
    public function __construct(private readonly FinanceBankStatementService $statements) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceBankStatement::query()
            ->with(['bankAccount:id,code,name', 'lines'])
            ->withCount('lines')
            ->when($request->filled('bank_account_id'), fn (Builder $q) => $q->where('bank_account_id', (int) $request->input('bank_account_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('statement_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('statement_date', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('statement_number', 'like', "%$term%")
                    ->orWhere('external_reference', 'like', "%$term%")
                    ->orWhere('filename', 'like', "%$term%"));
            })
            ->orderByDesc('statement_date')
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceBankStatementResource::collection($query->get()), 'Daftar rekening koran berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceBankStatementResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => [
                    'statuses' => FinanceBankStatement::STATUS_LABELS,
                    'sources' => FinanceBankStatement::SOURCES,
                    'line_statuses' => FinanceBankStatementLine::STATUS_LABELS,
                    'match_methods' => FinanceBankStatementLine::METHODS,
                ]]),
            'Daftar rekening koran berhasil diambil',
        );
    }

    public function store(FinanceBankStatementRequest $request): JsonResponse
    {
        $statement = $this->statements->import($request->validated(), $request->user());

        // Review summary ikut di `data`: layar selesai mengimpor harus langsung
        // melihat Book vs Bank vs Difference dan berapa baris yang masih butuh
        // tangan manusia — tanpa request tambahan.
        $payload = (new FinanceBankStatementResource($statement))->resolve($request);
        $payload['review'] = $this->statements->summary($statement);

        return $this->createdResponse($payload, 'Rekening koran berhasil diimpor');
    }

    public function show(FinanceBankStatement $bankStatement): JsonResponse
    {
        $bankStatement->load([
            'bankAccount:id,code,name',
            'lines.journalLine.transaction',
            'reconciliations:id,bank_statement_id,status,difference',
        ]);

        return $this->successResponse(
            new FinanceBankStatementResource($bankStatement),
            'Detail rekening koran berhasil diambil',
        );
    }

    /** Ringkasan review §4: Book Balance vs Bank Balance vs Difference + hitungan baris. */
    public function summary(FinanceBankStatement $bankStatement): JsonResponse
    {
        return $this->successResponse($this->statements->summary($bankStatement), 'Ringkasan rekonsiliasi berhasil diambil');
    }

    /** Pergerakan buku besar rekening ini yang tidak ada di rekening koran (§4 "Detect"). */
    public function unclaimed(FinanceBankStatement $bankStatement): JsonResponse
    {
        $rows = $this->statements->unclaimedMovements($bankStatement);

        $sum = fn (string $direction): float => round(array_sum(array_map(
            fn (array $row): float => $row['direction'] === $direction ? (float) $row['amount'] : 0.0,
            $rows,
        )), 2);

        return $this->successResponse([
            'headers' => ['journal_number', 'transaction_date', 'journal_type_label', 'description', 'direction', 'amount'],
            'rows' => $rows,
            'totals' => [
                'lines' => count($rows),
                'in' => $sum('in'),
                'out' => $sum('out'),
            ],
        ], 'Pergerakan buku besar yang belum tercakup rekening koran berhasil diambil');
    }

    /** Jalankan ulang pencocokan otomatis (mis. setelah transaksi buku dicatat). */
    public function autoMatch(Request $request, FinanceBankStatement $bankStatement): JsonResponse
    {
        return $this->successResponse(
            $this->statements->autoMatch($bankStatement, $request->user()),
            'Pencocokan otomatis berhasil dijalankan',
        );
    }

    public function storeLine(Request $request, FinanceBankStatement $bankStatement): JsonResponse
    {
        $line = $this->statements->addLine($bankStatement, $this->lineRules($request), $request->user());

        return $this->createdResponse(
            new FinanceBankStatementLineResource($line->load('journalLine.transaction')),
            'Baris rekening koran berhasil ditambahkan',
        );
    }

    public function updateLine(Request $request, FinanceBankStatementLine $statementLine): JsonResponse
    {
        $line = $this->statements->updateLine($statementLine, $this->lineRules($request));

        return $this->successResponse(
            new FinanceBankStatementLineResource($line->load('journalLine.transaction')),
            'Baris rekening koran berhasil diperbarui',
        );
    }

    public function destroyLine(FinanceBankStatementLine $statementLine): JsonResponse
    {
        $this->statements->removeLine($statementLine);

        return $this->successResponse(['id' => (int) $statementLine->id], 'Baris rekening koran berhasil dihapus');
    }

    public function match(Request $request, FinanceBankStatementLine $statementLine): JsonResponse
    {
        $validated = $request->validate([
            'journal_line_id' => ['required', 'integer', Rule::exists('finance_transaction_lines', 'id')],
        ]);

        $line = $this->statements->matchManually(
            $statementLine,
            FinancialTransactionLine::query()->whereKey((int) $validated['journal_line_id'])->firstOrFail(),
            $request->user(),
        );

        return $this->successResponse(
            new FinanceBankStatementLineResource($line->load('journalLine.transaction')),
            'Baris rekening koran berhasil dicocokkan',
        );
    }

    public function unmatch(Request $request, FinanceBankStatementLine $statementLine): JsonResponse
    {
        $line = $this->statements->unmatch($statementLine, $request->user());

        return $this->successResponse(
            new FinanceBankStatementLineResource($line->load('journalLine.transaction')),
            'Pencocokan baris rekening koran berhasil dilepas',
        );
    }

    /** Kecualikan baris (§4 EXCLUDED). Alasan wajib — terlihat di audit trail (§22). */
    public function exclude(Request $request, FinanceBankStatementLine $statementLine): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $line = $this->statements->exclude($statementLine, (string) $validated['reason'], $request->user());

        return $this->successResponse(
            new FinanceBankStatementLineResource($line),
            'Baris rekening koran berhasil dikecualikan',
        );
    }

    /** Kandidat baris jurnal untuk satu baris statement (layar review §4). */
    public function candidates(Request $request, FinanceBankStatementLine $statementLine): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 25)));

        return $this->successResponse([
            'line' => new FinanceBankStatementLineResource($statementLine),
            'candidates' => $this->statements->candidatesFor($statementLine, $limit),
        ], 'Kandidat pencocokan berhasil diambil');
    }

    /** Rekonsiliasi resmi dari rekening koran (§4/§45). */
    public function reconcile(Request $request, FinanceBankStatement $bankStatement): JsonResponse
    {
        $reconciliation = $this->statements->reconcile($bankStatement, $request->user());

        $payload = (new FinanceReconciliationResource($reconciliation->load([
            'bankAccount:id,code,name',
            'bankStatement:id,statement_number,status',
            'items',
        ])))->resolve($request);
        $payload['statement_status'] = $reconciliation->bankStatement?->status;

        return $this->createdResponse($payload, 'Rekonsiliasi rekening koran berhasil dibuat');
    }

    public function cancel(Request $request, FinanceBankStatement $bankStatement): JsonResponse
    {
        $statement = $this->statements->cancel($bankStatement, $request->user());

        return $this->successResponse(
            new FinanceBankStatementResource($statement->load(['bankAccount:id,code,name', 'lines'])),
            'Rekening koran berhasil dibatalkan',
        );
    }

    /**
     * Aturan satu baris rekening koran (entri manual & perbaikan baris). Nominal
     * dipegang salah satu sisi saja, dan `amount` dihitung service — lihat
     * {@see FinanceBankStatementService::normalizeRow()}.
     *
     * @return array<string, mixed>
     */
    private function lineRules(Request $request): array
    {
        return $request->validate([
            'line_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'debit' => ['nullable', 'numeric', 'min:0'],
            'credit' => ['nullable', 'numeric', 'min:0'],
            'external_id' => ['nullable', 'string', 'max:100'],
            'running_balance' => ['nullable', 'numeric'],
        ]);
    }
}
