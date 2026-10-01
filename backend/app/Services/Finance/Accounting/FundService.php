<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceFund;
use App\Models\FinanceFundAccount;
use App\Models\FinanceFundContraAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan dana & daftar akunnya.
 *
 * Masa simpan dana bukan "hapus saja": dana yang sudah dipakai baris jurnal
 * dinonaktifkan (FK RESTRICT menjaga angka historis). Yang boleh dihapus hanya
 * dana yang belum pernah dipakai — dan itu pun dengan membersihkan daftar
 * akunnya lebih dulu.
 */
class FundService
{
    /** @return Collection<int, FinanceFund> */
    public function all(bool $activeOnly = false): Collection
    {
        return FinanceFund::query()
            ->withCount(['accounts', 'contraAccounts'])
            ->when($activeOnly, fn ($q) => $q->active())
            ->orderBy('kode_dana')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?int $id = null): FinanceFund
    {
        $fund = $id === null ? new FinanceFund() : FinanceFund::query()->findOrFail($id);

        $kode = (string) ($data['kode_dana'] ?? $fund->kode_dana ?? '');

        if ($kode === '') {
            throw ValidationException::withMessages(['kode_dana' => 'Kode dana wajib diisi.']);
        }

        if ($id === null && FinanceFund::query()->where('kode_dana', $kode)->exists()) {
            throw ValidationException::withMessages(['kode_dana' => "Dana '{$kode}' sudah ada."]);
        }

        $fund->fill([
            'kode_dana' => $kode,
            'nama_dana' => $data['nama_dana'] ?? $fund->nama_dana,
            'jenis' => $data['jenis'] ?? $fund->jenis ?? 'bebas',
            'peruntukan' => $data['peruntukan'] ?? $fund->peruntukan,
            'status' => $data['status'] ?? $fund->status ?? 'aktif',
            'keterangan' => $data['keterangan'] ?? $fund->keterangan,
        ]);

        // Dana terikat tanpa peruntukan yang jelas akan ditolak penjaga
        // peruntukan di kemudian hari tanpa sebab yang dapat dipahami pengguna.
        if ($fund->jenis !== 'bebas' && empty($fund->peruntukan)) {
            throw ValidationException::withMessages([
                'peruntukan' => 'Dana terikat wajib mencantumkan peruntukannya, agar penolakan jurnal di kemudian hari dapat dijelaskan.',
            ]);
        }

        $fund->save();

        return $fund->refresh();
    }

    /** @param list<int> $accountIds */
    public function syncAccounts(FinanceFund $fund, array $accountIds): FinanceFund
    {
        DB::transaction(function () use ($fund, $accountIds): void {
            FinanceFundAccount::query()->where('kode_dana', $fund->kode_dana)->delete();

            foreach (array_unique(array_map('intval', $accountIds)) as $accountId) {
                FinanceFundAccount::query()->create(['kode_dana' => $fund->kode_dana, 'account_id' => $accountId]);
            }
        });

        return $fund->refresh();
    }

    /** @param list<int> $accountIds */
    public function syncContraAccounts(FinanceFund $fund, array $accountIds): FinanceFund
    {
        DB::transaction(function () use ($fund, $accountIds): void {
            FinanceFundContraAccount::query()->where('kode_dana', $fund->kode_dana)->delete();

            foreach (array_unique(array_map('intval', $accountIds)) as $accountId) {
                FinanceFundContraAccount::query()->create(['kode_dana' => $fund->kode_dana, 'account_id' => $accountId]);
            }
        });

        return $fund->refresh();
    }

    public function delete(FinanceFund $fund): void
    {
        $terpakai = DB::table('finance_transaction_lines')->where('kode_dana', $fund->kode_dana)->exists();

        if ($terpakai) {
            throw ValidationException::withMessages([
                'kode_dana' => 'Dana ini sudah dipakai pada baris jurnal; nonaktifkan saja agar angka laporan historis tidak bergeser.',
            ]);
        }

        $fund->delete();
    }
}
