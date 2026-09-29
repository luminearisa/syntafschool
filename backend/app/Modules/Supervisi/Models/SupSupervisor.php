<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Models\Employee;
use App\Modules\Supervisi\Domain\Enums\SupervisorKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Registry supervisor (§B keputusan desain).
 *
 * Kepala sekolah memilih **siapa saja** yang boleh menilai/membina dari daftar
 * pegawai — bukan hard-code peran. Kewenangan dibatasi masa berlaku, cakupan,
 * mapel, kapasitas kasus, dan hak finalisasi.
 */
class SupSupervisor extends BaseModel
{
    use SoftDeletes;

    protected $table = 'sup_supervisors';

    protected $casts = [
        'kind' => SupervisorKind::class,
        'subject_ids' => 'array',
        'categories' => 'array',
        'can_finalize' => 'boolean',
        'can_appraise' => 'boolean',
        'is_active' => 'boolean',
        'max_active_cases' => 'integer',
        'active_from' => 'date',
        'active_to' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('active_from')->orWhere('active_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('active_to')->orWhere('active_to', '>=', now()));
    }

    /** Efektif boleh memfinalkan: butuh jenis yang berwenang dan sakelar aktif. */
    public function mayFinalize(): bool
    {
        return $this->can_finalize && $this->kind->mayFinalize();
    }

    public function mayAppraise(): bool
    {
        return $this->can_appraise && $this->kind->mayAppraise();
    }
}
