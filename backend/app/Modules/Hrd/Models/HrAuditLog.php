<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit HRD (§51).
 *
 * Baris di sini **tidak dapat diubah atau dihapus**. Model ini menolak operasi
 * `update`/`delete` pada baris yang sudah ada, dan tidak ada endpoint yang
 * menyuntingnya — audit yang bisa diedit bukan audit.
 *
 * `before`/`after` menyimpan potongan data yang berubah, bukan seluruh tabel;
 * `transaction_id` menautkan perubahan ke transaksi yang menyebabkannya sehingga
 * satu transfer dapat ditelusuri dari permintaan sampai notifikasi.
 */
class HrAuditLog extends HrdModel
{
    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'changed_fields' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** Audit bersifat tambah-saja: tidak ada pembaruan, tidak ada penghapusan. */
    protected static function booted(): void
    {
        static::updating(function (self $log): bool {
            throw new \RuntimeException('Jejak audit HRD tidak dapat diubah (§51).');
        });

        static::deleting(function (self $log): bool {
            throw new \RuntimeException('Jejak audit HRD tidak dapat dihapus (§51).');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(HrTransaction::class, 'transaction_id');
    }

    public function scopeForEntity(Builder $query, string $type, int|string $id): Builder
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }
}
