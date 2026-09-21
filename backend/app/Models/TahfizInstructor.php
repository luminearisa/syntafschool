<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Profil kekhususan instruktur Tahfiz.
 *
 * Guru/musyrif/musyrifah adalah pegawai (`employees`); tabel ini hanya menyimpan
 * kekhususan mengajar Al-Quran mereka, bukan meniru data pegawai. Inilah yang
 * membedakan peran pengajar (instructor/assistant) dengan peran pengawasan
 * (musyrif/musyrifah).
 */
class TahfizInstructor extends BaseModel
{
    use SoftDeletes;

    public const ROLE_INSTRUCTOR = 'instructor';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_MUSYRIF = 'musyrif';
    public const ROLE_MUSYRIFAH = 'musyrifah';

    /** @var list<string> */
    public const ROLES = [
        self::ROLE_INSTRUCTOR, self::ROLE_ASSISTANT, self::ROLE_MUSYRIF, self::ROLE_MUSYRIFAH,
    ];

    public const SPECIALIZATIONS = ['tahsin', 'tahfiz', 'tilawah', 'tasmi', 'murojaah'];

    protected $casts = [
        'specialization' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function halaqahs(): HasMany
    {
        return $this->hasMany(TahfizHalaqah::class, 'instructor_id');
    }
}
