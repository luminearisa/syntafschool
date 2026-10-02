<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiKnowledgeChunk;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiKnowledgeDocument extends Model
{
    protected $table = 'hr_ai_knowledge_documents';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'title' => 'string',
        'category' => 'string',
        'source_type' => 'string',
        'source_id' => 'integer',
        'sensitivity' => 'string',
        'content_hash' => 'string',
        'indexed_at' => 'datetime',
        'status' => 'string',
        'meta' => 'array',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(HrAiKnowledgeChunk::class, 'document_id');
    }
}
