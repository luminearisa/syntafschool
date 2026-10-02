<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiConversation extends Model
{
    protected $table = 'hr_ai_conversations';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'user_id' => 'integer',
        'channel' => 'string',
        'context' => 'array',
        'employee_id' => 'integer',
        'status' => 'string',
        'started_at' => 'datetime',
        'last_message_at' => 'datetime',
        'summary' => 'string',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
