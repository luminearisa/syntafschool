<?php

namespace App\Modules\Procurement\Models;

/**
 * Pengaturan modul (aturan bisnis configurable). Tabel: `proc_settings`.
 *
 * Nilai menimpa default `config('procurement.*')` bila ada, sehingga sekolah
 * dapat mengubah ambang/toleransi tanpa deploy.
 */
class Setting extends ProcurementModel
{
    protected $table = 'proc_settings';

    protected $casts = ['value' => 'array'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting?->value ?? $default;
    }
}
