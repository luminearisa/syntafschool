<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Niat mencatatkan sesuatu ke modul lain (§66, §77).
 *
 * Inilah sebab HRD tidak menuntut seluruh modul ERP terpasang: peristiwa
 * (mis. "pegawai ini menerima laptop") disimpan lebih dulu sebagai niat, lalu
 * dikerjakan ketika modul Aset tersedia. Tidak ada integrasi yang hilang hanya
 * karena modul tujuannya belum ada.
 */
class HrIntegrationOutbox extends HrdModel
{
    // Nama tabel tidak dapat diturunkan otomatis: "outbox" tidak berjamak.
    protected $table = 'hr_integration_outbox';

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(fn (Builder $q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }
}
