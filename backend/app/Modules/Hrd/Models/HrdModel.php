<?php

namespace App\Modules\Hrd\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Model dasar seluruh Modul HRD.
 *
 * Nama tabel diturunkan dari nama kelas (`HrEmployeeDocument` →
 * `hr_employee_documents`), jadi tidak ada daftar tabel yang harus dijaga manual
 * dan tidak ada model yang diam-diam menunjuk tabel yang salah.
 *
 * Skop waktu berlaku disediakan sekali di sini: §19 menuntut setiap kueri dapat
 * bertanya "bagaimana keadaan pada tanggal tertentu?", dan itu berarti kueri
 * efektif harus menjadi kebiasaan, bukan tempelan di satu-dua layanan:
 *
 * ```php
 * HrEmployeeAssignment::query()->activeOn('2026-07-01')->get();
 * ```
 */
abstract class HrdModel extends BaseModel
{
    public function getTable(): string
    {
        // Tabel yang namanya tidak dapat diturunkan (mis. `hr_integration_outbox`
        // yang tidak berjamak) menyetel `$table` sendiri dan dihormati di sini.
        if (is_string($this->table) && $this->table !== '') {
            return $this->table;
        }

        // Awalan `Hr` pada nama kelas adalah penanda modul, bukan bagian nama tabel:
        // `HrEmployeeDocument` → `hr_employee_documents`.
        $base = class_basename(static::class);
        $base = Str::startsWith($base, 'Hr') && Str::length($base) > 2 ? Str::substr($base, 2) : $base;

        return 'hr_'.Str::snake(Str::pluralStudly($base));
    }

    /**
     * Baris yang berlaku pada satu tanggal (default: hari ini).
     *
     * `effective_to` NULL berarti "masih berlaku"; baris yang berakhir tepat pada
     * tanggal yang ditanyakan tetap dianggap berlaku sampai akhir hari itu.
     */
    public function scopeActiveOn(Builder $query, mixed $date = null): Builder
    {
        $date = $date === null ? now()->toDateString() : ($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date);

        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    /** Baris yang belum berakhir (tanpa batas awal). */
    public function scopeNotEnded(Builder $query, mixed $date = null): Builder
    {
        $date = $date === null ? now()->toDateString() : ($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date);

        return $query->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
