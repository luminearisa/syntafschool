<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tindak lanjut terjadwal (kontrol ulang, perawatan luka, baca hasil).
 *
 * Tabel: `clinic_follow_ups`
 */
class ClinicFollowUp extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_follow_ups';

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function referral(): BelongsTo
    {
        return $this->belongsTo(ClinicReferral::class, 'referral_id');
    }
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function isOverdue(): bool
    {
        return $this->status !== 'COMPLETED' && $this->due_date !== null && $this->due_date->isPast();
    }

    /**
     * Status terlambat ikut dikirim ke API supaya layar tindak lanjut tidak
     * perlu menghitung ulang tanggal (dan tidak bisa berbeda dengan server).
     */
    protected $appends = ['is_overdue'];

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }
}
