<?php

namespace App\Modules\CBT\Models;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CbtQuestion extends CbtModel
{
    use SoftDeletes;

    protected $table = 'cbt_questions';

    protected $casts = [
        'payload' => 'array',
        'points' => 'decimal:2',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(CbtQuestionOption::class, 'question_id')->orderBy('position');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CbtQuestionRevision::class, 'question_id')->orderByDesc('version');
    }
}
