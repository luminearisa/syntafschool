<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penggabungan pegawai duplikat (§58).
 *
 * Data historis TIDAK dihapus: transaksi, penugasan, dokumen, dan linimasa
 * dipindahkan ke pegawai induk; pegawai sumber ditandai `merged` dan tetap dapat
 * ditelusuri. `resolution` menyimpan pilihan nilai yang dipertahankan untuk setiap
 * kolom yang berbeda — keputusan manusia, bukan tebakan sistem.
 */
class HrEmployeeMerge extends HrdModel
{
    protected $casts = [
        'comparison' => 'array',
        'resolution' => 'array',
        'moved_records' => 'array',
        'approved_at' => 'datetime',
        'merged_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_MERGED = 'merged';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public function source(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'source_employee_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'target_employee_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
