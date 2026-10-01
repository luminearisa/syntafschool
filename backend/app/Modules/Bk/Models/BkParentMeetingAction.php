<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Kesepakatan tindakan hasil pertemuan orang tua.
 *
 * Tabel: `bk_parent_meeting_actions`
 */
class BkParentMeetingAction extends BkModel
{
    protected $table = 'bk_parent_meeting_actions';

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'status' => \App\Modules\Bk\Domain\Enums\FollowUpStatus::class,
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BkParentMeeting::class, 'meeting_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(BkFollowUp::class, 'follow_up_id');
    }
}
