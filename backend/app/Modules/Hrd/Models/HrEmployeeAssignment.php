<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penugasan pegawai (§19, §20).
 *
 * Tabel inilah yang menjawab "siapa menempati kursi ini pada tanggal tertentu".
 * Penugasan lama ditutup (`end_date`, `status = ended`), tidak dihapus.
 *
 * Satu pegawai hanya boleh punya satu penugasan utama aktif. Aturan itu ditegakkan
 * `AssignmentService` di dalam transaksi dengan `lockForUpdate` — bukan unique
 * index parsial, karena MySQL tidak mendukungnya; sisa pelanggaran data lama
 * dilaporkan sebagai temuan kualitas data.
 */
class HrEmployeeAssignment extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'is_primary' => 'boolean',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'percentage' => 'decimal:2',
        'version' => 'integer',
    ];

    public const TYPE_PRIMARY = 'primary';
    public const TYPE_SECONDARY = 'secondary';
    public const TYPE_TEMPORARY = 'temporary';
    public const TYPE_ACTING = 'acting';
    public const TYPE_PROJECT = 'project';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(HrWorkLocation::class, 'location_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'manager_employee_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(HrTransaction::class, 'transaction_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /** Penugasan yang berlaku pada satu tanggal — pertanyaan inti §19. */
    public function scopeEffectiveOn(Builder $query, mixed $date): Builder
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;

        return $query->where('start_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }

    public function isOpen(): bool
    {
        return $this->status === 'active';
    }
}
