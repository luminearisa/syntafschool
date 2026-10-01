<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\SessionStatus;
use App\Modules\Bk\Domain\Enums\SessionType;
use App\Modules\Bk\Domain\Enums\SessionMode;
use App\Modules\Bk\Domain\Enums\AttendanceStatus;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sesi konseling.

Dua kolom sengaja dipisah: `session_summary` (dapat dibagikan sesuai level) dan
`counselor_note` (catatan konselor, COUNSELOR_ONLY). Catatan konselor **tidak**
pernah muncul pada daftar, notifikasi, ekspor, maupun log aplikasi.
 *
 * Tabel: `bk_counseling_sessions`
 */
class BkCounselingSession extends BkModel
{
    protected $table = 'bk_counseling_sessions';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'parent_informed' => 'boolean',
        'status' => \App\Modules\Bk\Domain\Enums\SessionStatus::class,
        'session_type' => \App\Modules\Bk\Domain\Enums\SessionType::class,
        'mode' => \App\Modules\Bk\Domain\Enums\SessionMode::class,
        'attendance_status' => \App\Modules\Bk\Domain\Enums\AttendanceStatus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(BkAppointment::class, 'appointment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(BkCaseNote::class, 'session_id');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'COMPLETED');
    }

    /**
     * Alias `counselor_user_id` → kolom kanonik `counselor_id`.
     *
     * Sesi konseling menyebut konselornya sebagai pengguna, dan sebagian
     * pemanggil menuliskan nama kolom itu apa adanya (`counselor_user_id`,
     * seperti pada modul karier & beban kerja) alih-alih `counselor_id`.
     * Alih-alih menambah kolom kedua yang bisa saling bertentangan, alias ini
     * memetakannya ke kolom kanonik — sehingga tidak pernah ada dua jawaban atas
     * pertanyaan "siapa konselor sesi ini?".
     */
    public function setCounselorUserIdAttribute(mixed $value): void
    {
        if ($value !== null && empty($this->attributes['counselor_id'])) {
            $this->attributes['counselor_id'] = (int) $value;
        }
    }
}
