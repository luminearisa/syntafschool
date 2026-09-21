<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Setoran hafalan — pencatatan inti modul Tahfiz.
 *
 * Peserta menyetorkan hafalan (baru/ziyadah/setoran/tasmi) kepada guru pada
 * rentang ayat tertentu. Guru mencatat kesalahan secara terperinci di
 * `TahfizMemorizationMistake`, memberi skor multi-aspek (kelancaran, tajwid,
 * makhraj), lalu menetapkan status:
 *
 * submitted → reviewed → accepted | accepted_with_revision | repeated | rejected
 *
 * Hanya status `accepted` dan `accepted_with_revision` yang dihitung sebagai
 * hafalan oleh progress engine. "Accepted with revision" berarti hafalan
 * belum mutqin — peserta harus mengulang bagian tertentu.
 */
class TahfizMemorizationRecord extends BaseModel
{
    use SoftDeletes;

    public const TYPE_HAFALAN_BARU = 'hafalan_baru';
    public const TYPE_ZIYADAH = 'ziyadah';
    public const TYPE_SETORAN = 'setoran';
    public const TYPE_TASMI = 'tasmi';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_HAFALAN_BARU, self::TYPE_ZIYADAH, self::TYPE_SETORAN, self::TYPE_TASMI,
    ];

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_ACCEPTED_WITH_REVISION = 'accepted_with_revision';
    public const STATUS_REPEATED = 'repeated';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_SUBMITTED, self::STATUS_REVIEWED, self::STATUS_ACCEPTED,
        self::STATUS_ACCEPTED_WITH_REVISION, self::STATUS_REPEATED, self::STATUS_REJECTED,
    ];

    /** Status yang dianggap hafalan sah oleh progress engine. */
    public const COUNTED_STATUSES = [self::STATUS_ACCEPTED, self::STATUS_ACCEPTED_WITH_REVISION];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'score' => 'decimal:2',
        'revision_due_date' => 'date:Y-m-d',
        'reviewed_at' => 'datetime',
    ];

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->whereIn('status', self::COUNTED_STATUSES);
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_REVIEWED]);
    }

    /** Masih bisa diubah: belum mendapat penilaian final. */
    public function isPendingReview(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_REVIEWED], true);
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TahfizSession::class, 'session_id');
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'surah_id');
    }

    public function mistakes(): HasMany
    {
        return $this->hasMany(TahfizMemorizationMistake::class, 'memorization_record_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Jumlah ayat pada setoran ini. */
    public function ayahCount(): int
    {
        return max(0, (int) $this->ayah_end - (int) $this->ayah_start + 1);
    }

    /** Jumlah halaman yang dicakup, bila dicatat. */
    public function pageCount(): ?int
    {
        if ($this->page_start === null || $this->page_end === null) {
            return null;
        }

        return max(0, (int) $this->page_end - (int) $this->page_start + 1);
    }

    public function isAccepted(): bool
    {
        return in_array($this->status, self::COUNTED_STATUSES, true);
    }
}
