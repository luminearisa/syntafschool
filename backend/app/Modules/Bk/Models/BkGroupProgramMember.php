<?php

namespace App\Modules\Bk\Models;

use App\Models\Student;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Keikutsertaan siswa pada program kelompok.
 *
 * Persetujuan (bila disyaratkan program) dirujuk lewat `consent_id`, sehingga
 * pertanyaan siapa yang mengizinkan dapat dijawab.
 *
 * Tabel: `bk_group_program_members`
 */
class BkGroupProgramMember extends BkModel
{
    protected $table = 'bk_group_program_members';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'joined_at' => 'datetime',
        'completed_at' => 'datetime',
        'evaluation' => 'array',
        'status' => \App\Modules\Bk\Domain\Enums\GroupProgramMemberStatus::class,
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(BkGroupProgram::class, 'program_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(BkConsent::class, 'consent_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(BkGroupProgramAttendance::class, 'member_id');
    }
}
