<?php

namespace App\Services\Library;

use App\Models\Library;
use App\Models\LibraryNumberingConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penomoran dokumen per perpustakaan.
 *
 * Format tidak ditulis keras: token yang dikenali adalah
 * {SEQ}, {YYYY}, {YY}, {MM}, dan {LIBRARY_CODE}.
 *
 * Nomor diambil secara atomik (transaksi + lock) agar dua permintaan
 * bersamaan tidak menghasilkan nomor yang sama.
 */
class NumberingService
{
    public const TOKENS = ['{SEQ}', '{YYYY}', '{YY}', '{MM}', '{LIBRARY_CODE}'];

    /**
     * Pastikan konfigurasi penomoran bawaan tersedia untuk sebuah library.
     */
    public function ensureDefaults(Library $library): void
    {
        $existing = LibraryNumberingConfig::query()
            ->where('library_id', $library->id)
            ->pluck('key')
            ->all();

        $rows = [];
        $now = now();

        foreach (LibraryNumberingConfig::KEYS as $key => $label) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            $rows[] = [
                'library_id' => $library->id,
                'key' => $key,
                'format' => LibraryNumberingConfig::DEFAULT_FORMATS[$key] ?? '{SEQ}',
                'prefix' => null,
                'padding' => 3,
                'reset_period' => LibraryNumberingConfig::RESET_NEVER,
                'start_number' => 1,
                'last_number' => 0,
                'last_reset_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            LibraryNumberingConfig::query()->insert($rows);
        }
    }

    /**
     * Ambil nomor berikutnya untuk sebuah kunci penomoran.
     */
    public function next(Library $library, string $key): string
    {
        return DB::transaction(function () use ($library, $key): string {
            $config = LibraryNumberingConfig::query()
                ->where('library_id', $library->id)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($config === null) {
                throw ValidationException::withMessages([
                    'key' => "Konfigurasi penomoran \"{$key}\" belum tersedia untuk perpustakaan ini.",
                ]);
            }

            $now = Carbon::now($library->timezone);
            $this->applyReset($config, $now);

            $number = $config->last_number > 0
                ? $config->last_number + 1
                : max(1, (int) $config->start_number);

            $config->last_number = $number;
            $config->save();

            return $this->render($config, $library, $number, $now);
        });
    }

    /**
     * Pratinjau format (tanpa menaikkan nomor) — dipakai halaman pengaturan.
     */
    public function preview(LibraryNumberingConfig $config, Library $library): string
    {
        $number = $config->last_number > 0
            ? $config->last_number + 1
            : max(1, (int) $config->start_number);

        return $this->render($config, $library, $number, Carbon::now($library->timezone));
    }

    /**
     * Validasi format: wajib memuat {SEQ} dan hanya token yang dikenal.
     *
     * @return list<string>  token tak dikenal
     */
    public function unknownTokens(string $format): array
    {
        preg_match_all('/\{[A-Z_]+\}/', $format, $matches);

        return array_values(array_diff($matches[0] ?? [], self::TOKENS));
    }

    protected function render(
        LibraryNumberingConfig $config,
        Library $library,
        int $number,
        Carbon $now,
    ): string {
        $format = $config->format !== null && $config->format !== ''
            ? $config->format
            : '{SEQ}';

        $sequence = str_pad((string) $number, max(1, (int) $config->padding), '0', STR_PAD_LEFT);

        $rendered = str_replace(
            ['{LIBRARY_CODE}', '{YYYY}', '{YY}', '{MM}', '{SEQ}'],
            [$library->code, $now->format('Y'), $now->format('y'), $now->format('m'), $sequence],
            $format,
        );

        // Prefix bersifat tambahan opsional di depan hasil format.
        if ($config->prefix !== null && $config->prefix !== '') {
            $rendered = $config->prefix.$rendered;
        }

        return $rendered;
    }

    /**
     * Reset berkala: tahunan atau bulanan.
     */
    protected function applyReset(LibraryNumberingConfig $config, Carbon $now): void
    {
        if ($config->reset_period === LibraryNumberingConfig::RESET_NEVER) {
            return;
        }

        $last = $config->last_reset_at;

        $due = $last === null
            || ($config->reset_period === LibraryNumberingConfig::RESET_YEAR && $last->year !== $now->year)
            || ($config->reset_period === LibraryNumberingConfig::RESET_MONTH
                && ($last->year !== $now->year || $last->month !== $now->month));

        if ($due) {
            $config->last_number = 0;
            $config->last_reset_at = $now;
        }
    }
}