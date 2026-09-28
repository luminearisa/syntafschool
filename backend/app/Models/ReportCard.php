<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportCard extends BaseModel
{
    use SoftDeletes;

    public const TYPE_MIDTERM = 'tengah_semester';
    public const TYPE_SEMESTER = 'semester';
    public const TYPE_FINAL = 'akhir';

    public const TYPES = [self::TYPE_MIDTERM, self::TYPE_SEMESTER, self::TYPE_FINAL];

    public const TYPE_LABELS = [
        self::TYPE_MIDTERM => 'Tengah Semester',
        self::TYPE_SEMESTER => 'Semester',
        self::TYPE_FINAL => 'Akhir Tahun',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_READY = 'ready_for_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_IN_PROGRESS => 'Dalam Proses',
        self::STATUS_READY => 'Menunggu Review',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_PUBLISHED => 'Diterbitkan',
    ];

    protected $casts = [
        'average_score' => 'decimal:2',
        'rank' => 'integer',
        'attendance_summary' => 'array',
        'published_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReportCardItem::class)->orderBy('position');
    }
}
