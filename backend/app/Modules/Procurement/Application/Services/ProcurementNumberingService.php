<?php

namespace App\Modules\Procurement\Application\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen pengadaan (§47).
 *
 * Memakai sistem penomoran existing (`document_sequences`) — tidak ada tabel
 * sequence baru. Token: `{SEQ}`, `{SEQ:n}`, `{YYYY}`, `{YY}`, `{MM}`, `{YYYYMM}`.
 *
 * `next()` atomik (transaksi + lockForUpdate) sehingga dua permintaan bersamaan
 * tidak pernah menerbitkan nomor yang sama — penting agar satu PR/RFQ/PO/invoice
 * hanya memiliki satu nomor.
 */
class ProcurementNumberingService
{
    public const CODE_PR = 'proc_purchase_request';

    public const CODE_RFQ = 'proc_rfq';

    public const CODE_QUOTATION = 'proc_quotation';

    public const CODE_PO = 'proc_purchase_order';

    public const CODE_GOODS_RECEIPT = 'proc_goods_receipt';

    public const CODE_SERVICE_RECEIPT = 'proc_service_receipt';

    public const CODE_INVOICE = 'proc_vendor_invoice';

    public const CODE_CONTRACT = 'proc_contract';

    public const CODE_EVENT = 'proc_event';

    public const CODE_SELECTION = 'proc_vendor_selection';

    public const CODE_VENDOR = 'proc_vendor';

    /** @var array<string, array{0: string, 1: string}> code => [format, reset period] */
    public const DEFAULT_FORMATS = [
        self::CODE_PR => ['PR-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_RFQ => ['RFQ-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_QUOTATION => ['QT-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_PO => ['PO-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_GOODS_RECEIPT => ['GR-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_SERVICE_RECEIPT => ['SR-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_INVOICE => ['INV-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_CONTRACT => ['CTR-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_EVENT => ['PE-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_SELECTION => ['SEL-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_VENDOR => ['VND-{SEQ:5}', 'never'],
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
     * Nomor berikutnya, diambil atomik agar tidak ada nomor kembar (§47, §65).
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
