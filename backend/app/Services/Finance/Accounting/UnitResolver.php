<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceAccount;
use App\Models\FinanceUnitDefault;
use App\Support\Accounting\CoaHierarchy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penentu unit bisnis per baris jurnal.
 *
 * Urutannya tiga tingkat, yang lebih spesifik menang:
 *   1. unit pada barisnya sendiri (dokumen multi-unit);
 *   2. unit dokumen (seluruh baris mewarisi);
 *   3. unit bawaan modul asalnya.
 *
 * KECUALI baris neraca. Seluruh liabilitas dan setiap rekening kas/bank selalu
 * memakai **unit penampung** (`finance.container_unit`). Unit adalah pusat laba,
 * dan neraca hanya bermakna di tingkat yayasan: satu rekening kas memang
 * menampung uang banyak unit, sehingga memaksakan unit per baris di sana akan
 * melahirkan pembagian yang tidak pernah ada kenyataannya.
 *
 * Bila pengaturan unit penampung kosong (instalasi lama), perilakunya persis
 * seperti sebelum lapisan ini ada: unit hanya diisi bila pemanggil menyebutnya.
 */
class UnitResolver
{
    /** @var array<string, true>|null */
    private ?array $rekeningKas = null;

    /** @var string|null|false */
    private string|false|null $unitPenampung = false;

    /** Unit bawaan untuk sebuah modul asal (null bila modul tidak dipetakan). */
    public function defaultUnitFor(?string $sumberModul): ?string
    {
        if ($sumberModul === null) {
            return null;
        }

        return FinanceUnitDefault::query()
            ->where('sumber_modul', $sumberModul)
            ->value('business_unit_code');
    }

    /**
     * Menetapkan `kode_unit` pada setiap baris.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function resolveForLines(array $lines, ?string $unitDokumen): array
    {
        $penampung = $this->unitPenampung();
        $kas = $this->kodeRekeningKas();

        foreach ($lines as $index => $line) {
            $kodeAkun = (string) ($line['account_code'] ?? $line['kode_coa'] ?? '');

            if ($penampung !== null && CoaHierarchy::barisDipusatkan($kodeAkun, isset($kas[$kodeAkun]))) {
                $lines[$index]['kode_unit'] = $penampung;

                continue;
            }

            $lines[$index]['kode_unit'] = $line['kode_unit'] ?? $unitDokumen;
        }

        return $lines;
    }

    /** Unit penampung dari setelan; null bila tidak diisi. */
    public function unitPenampung(): ?string
    {
        if ($this->unitPenampung === false) {
            $nilai = (string) (app(\App\Services\Finance\FinanceSettingsService::class)->get('container_unit') ?? '');
            $this->unitPenampung = $nilai === '' ? null : $nilai;
        }

        return $this->unitPenampung;
    }

    /**
     * Peta kode COA yang dipakai rekening kas/bank konkret.
     *
     * @return array<string, true>
     */
    protected function kodeRekeningKas(): array
    {
        // Dibaca lewat kolomnya langsung, bukan lewat relasi model: nama relasi
        // pada model kas/bank bukan urusan resolver ini, dan menebaknya akan
        // membuat aturan unit bergantung pada hal yang tidak relevan.
        return $this->rekeningKas ??= array_fill_keys(array_filter(array_merge(
            $this->kodeCoaRekening('finance_cash_accounts'),
            $this->kodeCoaRekening('finance_bank_accounts'),
        )), true);
    }

    /** @return list<string> */
    protected function kodeCoaRekening(string $tabel): array
    {
        if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, 'account_id')) {
            return [];
        }

        $ids = DB::table($tabel)->whereNotNull('account_id')->pluck('account_id')->all();

        if ($ids === []) {
            return [];
        }

        return FinanceAccount::query()->whereIn('id', $ids)->pluck('code')->all();
    }

    /** Buang cache — wajib dipanggil bila rekening kas/bank atau setelan unit berubah. */
    public function forget(): void
    {
        $this->rekeningKas = null;
        $this->unitPenampung = false;
    }

    /**
     * Nama akun untuk snapshot di baris jurnal.
     *
     * @param  list<int>  $accountIds
     * @return array<int, string>
     */
    public function accountNames(array $accountIds): array
    {
        return FinanceAccount::query()
            ->whereIn('id', $accountIds)
            ->pluck('name', 'id')
            ->all();
    }
}
