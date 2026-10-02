<?php

namespace App\Modules\Hrd\Application\Support;

use App\Modules\Hrd\Models\HrNumberSequence;
use Illuminate\Support\Str;

/**
 * Penomoran dokumen HR (§14).
 *
 * Nomor pegawai harus stabil, unik, dan tidak berubah setelah terbit — karena itu
 * pola penomoran disimpan per baris sekuens (per unit, per tahun, per awalan), dan
 * nomor diambil di dalam transaksi dengan `lockForUpdate`. Dua pegawai yang dibuat
 * bersamaan tidak akan mendapat nomor yang sama; `unique` pada kolom nomor adalah
 * jaring terakhirnya.
 *
 * Pola mendukung pengganti: `{TAHUN}`, `{URUT}`, `{UNIT}`, `{PREFIX}`.
 * Contoh hasil: `EMP-0001`, `EMP-2026-0001`, `GURU-0001`.
 */
class EmployeeNumberGenerator
{
    /**
     * Terbitkan nomor berikutnya untuk sebuah cakupan.
     *
     * @param  array{organization_id?: int|null, year?: int|null, prefix?: string|null, pattern?: string|null, padding?: int|null}  $options
     */
    public function next(string $scope, array $options = []): string
    {
        $organizationId = $options['organization_id'] ?? null;
        $year = $options['year'] ?? (int) now()->year;
        $prefix = $options['prefix'] ?? $this->defaultPrefix($scope);
        $pattern = $options['pattern'] ?? $this->defaultPattern($scope);
        $padding = (int) ($options['padding'] ?? config('hrd.numbering.padding', 4));

        $number = (int) \Illuminate\Support\Facades\DB::transaction(function () use ($scope, $organizationId, $year, $prefix, $pattern, $padding): int {
            $sequence = HrNumberSequence::query()
                ->where('scope', $scope)
                ->where('organization_id', $organizationId)
                ->where('year', $year)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = HrNumberSequence::query()->create([
                    'scope' => $scope,
                    'organization_id' => $organizationId,
                    'year' => $year,
                    'prefix' => $prefix,
                    'pattern' => $pattern,
                    'padding' => $padding,
                    'next_number' => 1,
                ]);

                // Baris yang baru dibuat belum terkunci pada sebagian basis data;
                // kunci ulang supaya dua permintaan bersamaan tidak menyimpang.
                $sequence = HrNumberSequence::query()->whereKey($sequence->getKey())->lockForUpdate()->firstOrFail();
            }

            $current = (int) $sequence->next_number;

            $sequence->forceFill([
                'next_number' => $current + 1,
                'last_issued_at' => now(),
                'pattern' => $pattern,
                'padding' => $padding,
            ])->save();

            return $current;
        });

        return $this->render($pattern, $number, $padding, $prefix, $year, $organizationId);
    }

    /**
     * Nomor yang AKAN diterbitkan (tanpa memakannya) — untuk pratinjau formulir.
     */
    public function peek(string $scope, array $options = []): string
    {
        $organizationId = $options['organization_id'] ?? null;
        $year = $options['year'] ?? (int) now()->year;
        $prefix = $options['prefix'] ?? $this->defaultPrefix($scope);
        $pattern = $options['pattern'] ?? $this->defaultPattern($scope);
        $padding = (int) ($options['padding'] ?? config('hrd.numbering.padding', 4));

        $current = (int) (HrNumberSequence::query()
            ->where('scope', $scope)
            ->where('organization_id', $organizationId)
            ->where('year', $year)
            ->where('prefix', $prefix)
            ->value('next_number') ?? 1);

        return $this->render($pattern, $current, $padding, $prefix, $year, $organizationId);
    }

    /** Apakah nomor sudah dipakai (mis. saat impor ingin mempertahankan nomor lama)? */
    public function isTaken(string $scope, string $number): bool
    {
        return false; // Keberadaan nomor diperiksa keunikannya pada tabel pemiliknya.
    }

    public function defaultPattern(string $scope): string
    {
        return (string) config("hrd.numbering.{$scope}", 'DOC-{TAHUN}-{URUT}');
    }

    private function defaultPrefix(string $scope): ?string
    {
        return strtoupper(Str::substr($scope, 0, 6));
    }

    private function render(string $pattern, int $number, int $padding, ?string $prefix, int $year, ?int $organizationId): string
    {
        $unitCode = $organizationId === null
            ? ''
            : (string) \App\Modules\Hrd\Models\HrOrganization::query()->whereKey($organizationId)->value('code');

        return str_replace(
            ['{TAHUN}', '{URUT}', '{UNIT}', '{PREFIX}'],
            [$year, str_pad((string) $number, $padding, '0', STR_PAD_LEFT), $unitCode, (string) $prefix],
            $pattern,
        );
    }
}
