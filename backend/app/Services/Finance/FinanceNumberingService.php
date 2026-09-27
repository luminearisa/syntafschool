<?php

namespace App\Services\Finance;

use App\Models\DocumentSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen keuangan (§73).
 *
 * Memakai sistem penomoran existing (`document_sequences`) — tidak ada tabel
 * sequence baru. Token yang dikenali: `{SEQ}`, `{SEQ:n}`, `{YYYY}`, `{YY}`,
 * `{MM}`, `{YYYYMM}`. Seluruh format dijaga muat di kolom `*_number` (≤30 char).
 *
 * `next()` atomik (transaksi + lockForUpdate) agar dua permintaan bersamaan
 * tidak pernah menerbitkan nomor yang sama — penting untuk idempotensi (§14,
 * §66): satu billing/invoice/payment hanya boleh punya satu nomor.
 */
class FinanceNumberingService
{
    public const CODE_BILLING = 'finance_billing';

    public const CODE_INVOICE = 'finance_invoice';

    public const CODE_CASHIER_SESSION = 'finance_cashier_session';

    public const CODE_PAYMENT = 'finance_payment';

    public const CODE_RECEIPT = 'finance_receipt';

    public const CODE_REFUND = 'finance_refund';

    public const CODE_EXPENSE_REQUEST = 'finance_expense_request';

    public const CODE_EXPENSE = 'finance_expense';

    public const CODE_VENDOR_INVOICE = 'finance_vendor_invoice';

    public const CODE_PURCHASE_REQUEST = 'finance_purchase_request';

    public const CODE_PURCHASE_ORDER = 'finance_purchase_order';

    public const CODE_TRANSFER = 'finance_transfer';

    public const CODE_CASH_TRANSACTION = 'finance_cash_transaction';

    public const CODE_BANK_STATEMENT = 'finance_bank_statement';

    public const CODE_ADJUSTMENT = 'finance_adjustment';

    public const CODE_TRANSACTION = 'finance_transaction';

    public const CODE_RE_REGISTRATION = 'finance_re_registration';

    public const CODE_PAYMENT_PLAN = 'finance_payment_plan';

    public const CODE_CLEARANCE = 'finance_clearance';

    /** @var array<string, array{0: string, 1: string}> code => [format, reset period] */
    public const DEFAULT_FORMATS = [
        self::CODE_BILLING => ['BILL-{YYYYMM}-{SEQ:5}', 'month'],
        self::CODE_INVOICE => ['INV-{YYYYMM}-{SEQ:5}', 'month'],
        self::CODE_CASHIER_SESSION => ['CSS-{YYYYMM}-{SEQ:4}', 'month'],
        self::CODE_PAYMENT => ['PAY-{YYYYMM}-{SEQ:5}', 'month'],
        self::CODE_RECEIPT => ['RCPT-{YYYYMM}-{SEQ:5}', 'month'],
        self::CODE_REFUND => ['REF-{YYYY}-{SEQ:5}', 'year'],
        self::CODE_EXPENSE_REQUEST => ['EREQ-{YYYY}-{SEQ:5}', 'year'],
        self::CODE_EXPENSE => ['EXP-{YYYYMM}-{SEQ:5}', 'month'],
        self::CODE_VENDOR_INVOICE => ['VINV-{YYYY}-{SEQ:5}', 'year'],
        self::CODE_PURCHASE_REQUEST => ['PR-{YYYY}-{SEQ:5}', 'year'],
        self::CODE_PURCHASE_ORDER => ['PO-{YYYY}-{SEQ:5}', 'year'],
        self::CODE_TRANSFER => ['TRF-{YYYYMM}-{SEQ:5}', 'month'],
        self::CODE_CASH_TRANSACTION => ['CSH-{YYYYMM}-{SEQ:5}', 'month'],
        self::CODE_BANK_STATEMENT => ['STMT-{YYYYMM}-{SEQ:4}', 'month'],
        self::CODE_ADJUSTMENT => ['ADJ-{YYYYMM}-{SEQ:5}', 'month'],
        self::CODE_PAYMENT_PLAN => ['PLAN-{YYYYMM}-{SEQ:4}', 'month'],
        self::CODE_TRANSACTION => ['JRN-{YYYYMM}-{SEQ:6}', 'month'],
        self::CODE_RE_REGISTRATION => ['REG-{YYYY}-{SEQ:5}', 'year'],
        self::CODE_CLEARANCE => ['CLR-{YYYY}-{SEQ:5}', 'year'],
    ];

    /** Pastikan konfigurasi penomoran tersedia (idempoten). */
    public function ensureDefaults(): void
    {
        foreach (self::DEFAULT_FORMATS as $code => [$format, $reset]) {
            DocumentSequence::query()->firstOrCreate(
                ['code' => $code],
                ['prefix' => null, 'format' => $format, 'last_number' => 0, 'reset_period' => $reset],
            );
        }
    }

    /**
     * Nomor berikutnya, diambil atomik (transaksi + lockForUpdate) agar dua
     * permintaan bersamaan tidak pernah menghasilkan nomor yang sama.
     */
    public function next(string $code): string
    {
        return DB::transaction(function () use ($code): string {
            $this->ensureDefaults();

            $sequence = DocumentSequence::query()->where('code', $code)->lockForUpdate()->firstOrFail();

            $now = Carbon::now();
            $this->applyReset($sequence, $now);

            $number = (int) $sequence->last_number + 1;
            $sequence->last_number = $number;
            $sequence->save();

            return $this->render($sequence, $number, $now);
        });
    }

    /** Pratinjau nomor berikutnya tanpa menaikkannya. */
    public function preview(string $code): string
    {
        $this->ensureDefaults();

        $sequence = DocumentSequence::query()->where('code', $code)->first();

        if ($sequence === null) {
            return (string) (self::DEFAULT_FORMATS[$code][0] ?? '{SEQ}');
        }

        return $this->render($sequence, (int) $sequence->last_number + 1, Carbon::now());
    }

    protected function render(DocumentSequence $sequence, int $number, Carbon $now): string
    {
        $format = filled($sequence->format) ? (string) $sequence->format : '{SEQ}';

        $rendered = preg_replace_callback('/\{SEQ(?::(\d+))?\}/', function (array $matches) use ($number): string {
            $padding = isset($matches[1]) ? max(1, (int) $matches[1]) : 1;

            return str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
        }, $format) ?? $format;

        $rendered = str_replace(
            ['{YYYY}', '{YY}', '{MM}', '{YYYYMM}'],
            [$now->format('Y'), $now->format('y'), $now->format('m'), $now->format('Ym')],
            $rendered,
        );

        if (filled($sequence->prefix)) {
            $rendered = $sequence->prefix.$rendered;
        }

        return $rendered;
    }

    /** Reset berkala sesuai `reset_period` (never | year | month). */
    protected function applyReset(DocumentSequence $sequence, Carbon $now): void
    {
        if ($sequence->reset_period === 'never') {
            return;
        }

        $last = $sequence->last_reset_at !== null ? Carbon::parse($sequence->last_reset_at) : null;

        $due = match ($sequence->reset_period) {
            'year' => $last === null || (int) $last->format('Y') !== (int) $now->format('Y'),
            'month' => $last === null || $last->format('Y-m') !== $now->format('Y-m'),
            default => false,
        };

        if ($due) {
            $sequence->last_number = 0;
            $sequence->last_reset_at = $now;
        }

        $sequence->last_reset_at ??= $now;
    }
}
