<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceAccount;
use App\Models\FinanceFund;
use App\Models\FinanceFundAccount;
use App\Support\Accounting\CoaHierarchy;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Penjaga peruntukan dana.
 *
 * Inti fund accounting ada di sini: uang wakaf pembangunan tidak boleh terpakai
 * untuk gaji. Tanpa penjaga ini, penandaan dana hanya menjadi hiasan — dan
 * laporan ke donatur terpaksa disusun dari ingatan.
 *
 * Yang dibatasi hanya baris BEBAN (akar kelompok 5). Dana terikat membatasi
 * belanja-nya; kasnya menumpang rekening yang sama dengan dana lain, dan
 * pendapatannya justru penerimaan dana itu. Membatasi seluruh jenis akun hanya
 * akan menolak jurnal yang sebetulnya sah dan mengajari penggunanya berhenti
 * menandai dana sama sekali.
 */
class DanaPolicy
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    public function assertPeruntukan(array $lines, ?string $sumberModul = null): void
    {
        $mode = (string) (app(\App\Services\Finance\FinanceSettingsService::class)->get('fund_control') ?? 'enforce');

        if ($mode === 'off') {
            return;
        }

        $kodeDana = array_values(array_unique(array_filter(array_map(
            fn (array $line): ?string => $line['kode_dana'] ?? null,
            $lines,
        ))));

        if ($kodeDana === []) {
            return;
        }

        $dana = FinanceFund::query()->whereIn('kode_dana', $kodeDana)->get()->keyBy('kode_dana');

        // Daftar akun yang diizinkan per dana. Dana tanpa daftar = tidak membatasi.
        $izin = FinanceFundAccount::query()
            ->whereIn('kode_dana', $kodeDana)
            ->get()
            ->groupBy('kode_dana')
            ->map(fn ($group) => $group->pluck('account_id')->all());

        $akar = $this->akarAkun($lines);

        foreach (array_values($lines) as $index => $line) {
            $kode = $line['kode_dana'] ?? null;

            if (! $kode) {
                continue;
            }

            $nomor = $index + 1;
            $d = $dana->get($kode);

            if ($d === null) {
                $this->tolak($mode, $sumberModul, "Baris {$nomor}: dana {$kode} tidak ditemukan.");
                continue;
            }

            if ($d->status === 'nonaktif') {
                $this->tolak($mode, $sumberModul, "Baris {$nomor}: dana \"{$d->nama_dana}\" berstatus nonaktif.");
                continue;
            }

            // Hanya baris beban yang dibatasi.
            if (($akar[(int) ($line['account_id'] ?? 0)] ?? null) !== CoaHierarchy::BEBAN) {
                continue;
            }

            $diizinkan = $izin->get($kode, []);

            if ($diizinkan === [] || in_array((int) $line['account_id'], $diizinkan, true)) {
                continue;
            }

            $namaAkun = FinanceAccount::query()->whereKey($line['account_id'])->value('name') ?? $line['account_id'];

            $this->tolak($mode, $sumberModul, sprintf(
                'Baris %d: akun "%s" berada di luar peruntukan dana "%s" (%s). %sPakai dana lain, atau tambahkan akun ini ke daftar peruntukan dananya.',
                $nomor,
                $namaAkun,
                $d->nama_dana,
                $d->labelJenis(),
                $d->peruntukan ? "Peruntukannya: {$d->peruntukan}. " : '',
            ));
        }
    }

    /** Akar kelompok (1..5) tiap akun yang dipakai baris-baris ini. */
    protected function akarAkun(array $lines): array
    {
        $ids = array_values(array_unique(array_map(fn (array $line): int => (int) ($line['account_id'] ?? 0), $lines)));

        $out = [];

        foreach (FinanceAccount::query()->whereIn('id', $ids)->get(['id', 'code', 'type']) as $akun) {
            $out[(int) $akun->id] = CoaHierarchy::dariAkun($akun);
        }

        return $out;
    }

    /**
     * Mode `warning` mencatat pelanggaran tanpa menahan posting; mode `enforce`
     * menolaknya. Mode peringatan dipakai saat sekolah baru mulai menandai dana:
     * menolak sejak hari pertama membuat data justru kosong.
     *
     * @throws ValidationException
     */
    protected function tolak(string $mode, ?string $sumberModul, string $pesan): void
    {
        if ($mode === 'warning') {
            Log::warning('Peruntukan dana dilanggar (mode peringatan).', [
                'pesan' => $pesan,
                'sumber_modul' => $sumberModul,
            ]);

            return;
        }

        throw ValidationException::withMessages(['lines' => [$pesan]]);
    }
}
