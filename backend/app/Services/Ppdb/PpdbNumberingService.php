<?php

namespace App\Services\Ppdb;

use App\Models\DocumentSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen PPDB.
 *
 * Memakai **sistem penomoran existing** (`document_sequences`) — bukan tabel
 * baru. Format disimpan sebagai data dan boleh memakai token:
 * `{SEQ}`, `{SEQ:n}` (padding n digit), `{YYYY}`, `{YY}`, `{MM}`.
 *
 * Contoh: `PPDB-{YYYY}-{SEQ:6}` → `PPDB-2027-000001`.
 *
 * Nomor diambil atomik (transaksi + lock) agar dua permintaan bersamaan tidak
 * menghasilkan nomor yang sama.
 */
class PpdbNumberingService
{
    public const DEFAULT_FORMATS = [
        'registration' => 'PPDB-{YYYY}-{SEQ:6}',
        'invoice' => 'INV-{YYYY}-{SEQ:6}',
        're_registration' => 'RREG-{YYYY}-{SEQ:6}',
        // NIS untuk calon yang sudah daftar ulang (kolom `students.nis`).
        'student' => '{YYYY}{SEQ:4}',
    ];

    /**
     * Pastikan konfigurasi penomoran tersedia (idempoten).
     */
    public function ensureDefaults(): void
    {
        foreach (self::DEFAULT_FORMATS as $code => $format) {
            DocumentSequence::query()->firstOrCreate(
                ['code' => $code],
                ['prefix' => null, 'format' => $format, 'last_number' => 0, 'reset_period' => 'year'],
            );
        }
    }

    /**
     * Ambil nomor berikutnya untuk sebuah kode penomoran.
     */
    public function next(string $code): string
    {
        return DB::transaction(function () use ($code): string {
            $sequence = DocumentSequence::query()->where('code', $code)->lockForUpdate()->first();

            if ($sequence === null) {
                $this->ensureDefaults();

                $sequence = DocumentSequence::query()->where('code', $code)->lockForUpdate()->firstOrFail();
            }

            $now = Carbon::now();
            $this->applyReset($sequence, $now);

            $number = (int) $sequence->last_number + 1;
            $sequence->last_number = $number;
            $sequence->save();

            return $this->render($sequence, $number, $now);
        });
    }

    /**
     * Pratinjau nomor berikutnya tanpa menaikkannya.
     */
    public function preview(string $code): string
    {
        $this->ensureDefaults();

        $sequence = DocumentSequence::query()->where('code', $code)->first();

        if ($sequence === null) {
            return (string) (self::DEFAULT_FORMATS[$code] ?? '{SEQ}');
        }

        return $this->render($sequence, (int) $sequence->last_number + 1, Carbon::now());
    }

    protected function render(DocumentSequence $sequence, int $number, Carbon $now): string
    {
        $format = filled($sequence->format) ? (string) $sequence->format : '{SEQ}';

        // {SEQ:n} → padding n digit; {SEQ} → tanpa padding.
        $rendered = preg_replace_callback('/\{SEQ(?::(\d+))?\}/', function (array $matches) use ($number): string {
            $padding = isset($matches[1]) ? max(1, (int) $matches[1]) : 1;

            return str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
        }, $format) ?? $format;

        $rendered = str_replace(
            ['{YYYY}', '{YY}', '{MM}'],
            [$now->format('Y'), $now->format('y'), $now->format('m')],
            $rendered,
        );

        if (filled($sequence->prefix)) {
            $rendered = $sequence->prefix.$rendered;
        }

        return $rendered;
    }

    /**
     * Reset berkala (tahunan/bulanan) mengikuti konfigurasi.
     */
    protected function applyReset(DocumentSequence $sequence, Carbon $now): void
    {
        if ($sequence->reset_period === 'never') {
            return;
        }

        $last = $sequence->last_reset_at;

        $due = $last === null
            || ($sequence->reset_period === 'year' && $last->year !== $now->year)
            || ($sequence->reset_period === 'month'
                && ($last->year !== $now->year || $last->month !== $now->month));

        if ($due) {
            $sequence->last_number = 0;
            $sequence->last_reset_at = $now;
        }
    }
}