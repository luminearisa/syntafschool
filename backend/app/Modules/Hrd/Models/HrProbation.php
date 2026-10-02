<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Masa percobaan (§37).
 *
 * Siklusnya sendiri: pending → active → passed / extended / failed. Hasil review
 * disimpan apa adanya; penilaian kinerja fase berikutnya merujuk ke baris ini.
 */
class HrProbation extends HrdModel
{
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'review_date' => 'date:Y-m-d',
        'extended_to' => 'date:Y-m-d',
        'decided_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PASSED = 'passed';
    public const STATUS_EXTENDED = 'extended';
    public const STATUS_FAILED = 'failed';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(HrEmploymentRecord::class, 'employment_record_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_EXTENDED], true);
    }
}
