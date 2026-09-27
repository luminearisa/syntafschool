<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceAccountResource;
use App\Models\FinanceAccount;
use App\Services\Finance\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buku besar (§35–§37, §47): baris jurnal posted per akun dengan saldo berjalan
 * pada sisi normal akun, dan saldo satu akun pada sebuah rentang.
 *
 * Controller ini sengaja tidak menghitung apa pun. Angkanya datang dari
 * `FinanceReportService` — sumber yang sama dengan laporan `ledger` dan ekspor
 * CSV — sehingga layar buku besar, laporan, dan CSV adalah satu perhitungan
 * (§36). Menjumlah debit−credit apa adanya akan menampilkan sisi yang salah
 * untuk akun bergaya kredit; itu sudah ditolak §37 dan ditangani di service.
 */
class FinanceLedgerController extends BaseApiController
{
    public function __construct(private readonly FinanceReportService $reports) {}

    /** Buku besar semua akun (§35). */
    public function index(Request $request): JsonResponse
    {
        return $this->respond($request, $this->filters($request));
    }

    /** Buku besar satu akun (§35, §47) — `account_id` dipaksa dari route. */
    public function account(Request $request, FinanceAccount $account): JsonResponse
    {
        return $this->respond($request, array_merge(
            $this->filters($request),
            ['account_id' => (int) $account->id],
        ), $account);
    }

    /** Saldo satu akun + subledger kas/bank di bawahnya (§47). */
    public function balance(Request $request, FinanceAccount $account): JsonResponse
    {
        return $this->successResponse([
            'account' => new FinanceAccountResource($account),
            'balance' => $this->reports->accountBalance((int) $account->id, $this->filters($request)),
        ], 'Saldo akun berhasil diambil');
    }

    /**
     * Cached balance vs saldo buku besar untuk SEMUA rekening kas/bank (§1):
     * jawaban atas "berapa uang di setiap bank / kas setiap unit" sekaligus
     * membuktikan angkanya bisa direkonstruksi dari jurnal.
     */
    public function subledgers(): JsonResponse
    {
        return $this->successResponse($this->reports->subledgerReconciliation(), 'Saldo kas & bank berhasil diambil');
    }

    /**
     * Mutasi satu rekening kas/bank, diambil dari baris jurnal berstempel
     * rekening (§1, §2). Setiap barisnya punya `transaction_number` sehingga
     * layar ini bisa diklik sampai dokumen sumbernya (§38).
     */
    public function subledgerMovements(Request $request, string $type, int $subledger): JsonResponse
    {
        return $this->successResponse(
            $this->reports->subledgerMovements($type, $subledger, $this->filters($request)),
            'Mutasi rekening berhasil diambil',
        );
    }

    /**
     * Filter yang sama dengan laporan buku besar/CSV: rentang tanggal, unit,
     * dan batas baris.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter(
            [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'cost_center_id' => $request->query('cost_center_id'),
                'limit' => $request->query('limit'),
            ],
            fn (mixed $value): bool => filled($value),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function respond(Request $request, array $filters, ?FinanceAccount $account = null): JsonResponse
    {
        $rows = $this->reports->rows(FinanceReportService::KIND_LEDGER, $filters);

        return $this->successResponse([
            'account' => $account !== null ? new FinanceAccountResource($account) : null,
            'headers' => $this->reports->headers(FinanceReportService::KIND_LEDGER),
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ], 'Buku besar berhasil diambil');
    }

    /**
     * Saldo akhir per akun = `running_balance` baris terakhir akun itu; tidak
     * dijumlahkan lintas akun (§37).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{lines: int, debit: float, credit: float, closing_balances: array<string, float>}
     */
    private function totals(array $rows): array
    {
        $closing = [];
        $debit = 0.0;
        $credit = 0.0;

        foreach ($rows as $row) {
            $closing[(string) $row['account_code']] = (float) $row['running_balance'];
            $debit = round($debit + (float) $row['debit'], 2);
            $credit = round($credit + (float) $row['credit'], 2);
        }

        return [
            'lines' => count($rows),
            'debit' => $debit,
            'credit' => $credit,
            'closing_balances' => $closing,
        ];
    }
}
