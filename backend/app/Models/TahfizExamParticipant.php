<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peserta satu ujian beserta nilainya.
 */
class TahfizExamParticipant extends BaseModel
{
    public const STATUS_TERDAFTAR = 'terdaftar';
    public const STATUS_HADIR = 'hadir';
    public const STATUS_TIDAK_HADIR = 'tidak_hadir';
    public const STATUS_MENUNGGU_NILAI = 'menunggu_nilai';
    public const STATUS_DINILAI = 'dinilai';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_TERDAFTAR, self::STATUS_HADIR, self::STATUS_TIDAK_HADIR,
        self::STATUS_MENUNGGU_NILAI, self::STATUS_DINILAI,
    ];

    public const RESULT_LULUS = 'lulus';
    public const RESULT_TIDAK_LULUS = 'tidak_lulus';
    public const RESULT_REMEDIAL = 'remedial';

    protected $casts = [
        'score' => 'decimal:2',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scopeGraded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DINILAI);
    }

    public function scopePassed(Builder $query): Builder
    {
        return $query->where('result', self::RESULT_LULUS);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(TahfizExam::class, 'exam_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TahfizExamItem::class, 'exam_participant_id');
    }
}
