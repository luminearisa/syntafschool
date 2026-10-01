<?php

namespace App\Modules\Bk\Models;

use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sesi bimbingan karier.
 *
 * Setiap sesi menunjuk satu fokus, sehingga riwayat bimbingan terbaca sebagai
 * proses yang punya arah, bukan tumpukan catatan.
 *
 * Tabel: `bk_career_guidance_sessions`
 */
class BkCareerGuidanceSession extends BkModel
{
    protected $table = 'bk_career_guidance_sessions';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'session_at' => 'datetime',
        'next_session_date' => 'date',
        'options_explored' => 'array',
        'action_items' => 'array',
        'focus' => \App\Modules\Bk\Domain\Enums\GuidanceFocus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_user_id');
    }
}
