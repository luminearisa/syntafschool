<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceBusinessUnit;
use App\Models\FinanceUnitDefault;
use App\Support\Accounting\SumberModul;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unit bisnis & unit bawaannya.
 *
 * Satu unit dinonaktifkan, tidak dihapus: ia sudah menempel pada baris jurnal
 * historis dengan FK RESTRICT, dan menghapusnya akan menggeser angka laporan
 * lama. Penonaktifan menghentikan pemakaian baru tanpa menyentuh yang lama.
 */
class BusinessUnitService
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, FinanceBusinessUnit> */
    public function all(bool $activeOnly = false)
    {
        return FinanceBusinessUnit::query()
            ->when($activeOnly, fn ($q) => $q->active())
            ->orderBy('code')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?string $code = null): FinanceBusinessUnit
    {
        $kode = $code ?? (string) ($data['code'] ?? '');

        if ($kode === '') {
            throw ValidationException::withMessages(['code' => 'Kode unit wajib diisi.']);
        }

        $unit = FinanceBusinessUnit::query()->firstOrNew(['code' => $kode]);

        $parent = $data['parent_code'] ?? $unit->parent_code;

        if ($parent === $kode) {
            throw ValidationException::withMessages(['parent_code' => 'Unit tidak boleh menjadi induk dirinya sendiri.']);
        }

        $unit->fill([
            'name' => $data['name'] ?? $unit->name,
            'parent_code' => $parent,
            // Level diturunkan dari induknya, bukan diketik manual: level yang
            // diketik manual akan berbeda-beda dan merusak pengelompokan laporan.
            'level' => $parent === null ? 1 : ((int) FinanceBusinessUnit::query()->where('code', $parent)->value('level') + 1),
            'is_active' => $data['is_active'] ?? $unit->is_active ?? true,
            'description' => $data['description'] ?? $unit->description,
        ]);

        $unit->save();

        return $unit->refresh();
    }

    public function toggle(FinanceBusinessUnit $unit, bool $active): FinanceBusinessUnit
    {
        $unit->forceFill(['is_active' => $active])->save();

        return $unit->refresh();
    }

    /** @return array<string, string> sumber_modul => kode_unit */
    public function defaults(): array
    {
        return FinanceUnitDefault::query()->pluck('business_unit_code', 'sumber_modul')->all();
    }

    /**
     * Menetapkan unit bawaan sebuah modul.
     *
     * Modul asalnya divalidasi terhadap daftar sah: unit bawaan untuk modul yang
     * tidak pernah memanggil ledger adalah konfigurasi yang menyesatkan.
     */
    public function setDefault(string $sumberModul, ?string $businessUnitCode): void
    {
        if (! SumberModul::isValid($sumberModul)) {
            throw ValidationException::withMessages([
                'sumber_modul' => "Modul '{$sumberModul}' tidak terdaftar sebagai sumber jurnal yang sah.",
            ]);
        }

        DB::transaction(function () use ($sumberModul, $businessUnitCode): void {
            if ($businessUnitCode === null) {
                FinanceUnitDefault::query()->where('sumber_modul', $sumberModul)->delete();

                return;
            }

            $unit = FinanceBusinessUnit::query()->where('code', $businessUnitCode)->first();

            if ($unit === null) {
                throw ValidationException::withMessages(['business_unit_code' => "Unit '{$businessUnitCode}' tidak ditemukan."]);
            }

            if (! $unit->is_active) {
                throw ValidationException::withMessages(['business_unit_code' => "Unit '{$businessUnitCode}' berstatus nonaktif."]);
            }

            FinanceUnitDefault::query()->updateOrCreate(
                ['sumber_modul' => $sumberModul],
                ['business_unit_code' => $businessUnitCode],
            );
        });
    }
}
