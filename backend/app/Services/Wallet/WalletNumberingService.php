<?php

namespace App\Services\Wallet;

use App\Models\DocumentSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen wallet.
 *
 * Memakai sistem penomoran existing (`document_sequences`) — tidak ada tabel
 * sequence baru. Token yang dikenali: `{SEQ}`, `{SEQ:n}`, `{YYYY}`, `{YY}`, `{MM}`.
 *
 * Nomor rekening wallet memakai `reset_period = never`: satu kali dipakai
 * `WLT-000001`, nomor itu tidak boleh terbit lagi di tahun berikutnya (§5).
 * Dokumen periodik (top up, penarikan) boleh tahunan karena nomornya hanya
 * perlu unik di dalam dokumen itu sendiri.
 */
class WalletNumberingService
{
    public const CODE_WALLET = 'wallet_number';

    public const CODE_TRANSACTION = 'wallet_transaction';

    public const CODE_TOPUP = 'wallet_topup';

    public const CODE_WITHDRAWAL = 'wallet_withdrawal';

    public const CODE_ADJUSTMENT = 'wallet_adjustment';

    public const CODE_REVERSAL = 'wallet_reversal';

    /** @var array<string, array{0: string, 1: string}> format => reset period */
    public const DEFAULT_FORMATS = [
        self::CODE_WALLET => ['WLT-{SEQ:6}', 'never'],
        self::CODE_TRANSACTION => ['WT-{YYYYMM}-{SEQ:6}', 'month'],
        self::CODE_TOPUP => ['TP-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_WITHDRAWAL => ['WD-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_ADJUSTMENT => ['ADJ-{YYYY}-{SEQ:6}', 'year'],
        self::CODE_REVERSAL => ['RVS-{YYYY}-{SEQ:6}', 'year'],
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
