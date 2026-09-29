<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Percobaan SCORM seorang siswa pada satu SCO (§12).
 *
 * Kunci unik `(user_id, sco_id)` menahan percobaan ganda dari klik berulang.
 * Data CMI disimpan agar siswa dapat melanjutkan (resume) setelah koneksi
 * putus atau browser dimuat ulang.
 */
class LmsScormAttempt extends BaseModel
{
    protected $table = 'lms_scorm_attempts';

    protected $casts = [
        'cmi' => 'array',
        'score_raw' => 'float',
        'score_min' => 'float',
        'score_max' => 'float',
        'score_scaled' => 'float',
        'total_time_seconds' => 'integer',
        'launched_at' => 'datetime',
        'last_commit_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(LmsScormPackage::class, 'scorm_package_id');
    }

    public function sco(): BelongsTo
    {
        return $this->belongsTo(LmsScormSco::class, 'sco_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LmsScormTrackingEvent::class, 'attempt_id');
    }

    public function isCompleted(): bool
    {
        return $this->completion_status === 'completed'
            || $this->success_status === 'passed'
            || $this->status === 'COMPLETED';
    }

    /** Nilai 0–100 bila SCO melaporkan skor. */
    public function normalizedScore(): ?float
    {
        if ($this->score_scaled !== null) {
            return round($this->score_scaled * 100, 2);
        }

        if ($this->score_raw === null) {
            return null;
        }

        $min = $this->score_min ?? 0.0;
        $max = $this->score_max ?? 100.0;

        if ($max <= $min) {
            return round($this->score_raw, 2);
        }

        return round(($this->score_raw - $min) / ($max - $min) * 100, 2);
    }
}
