<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiKnowledgeDocument;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiKnowledgeChunk extends Model
{
    protected $table = 'hr_ai_knowledge_chunks';

    protected $guarded = [];

    protected $casts = [
        'document_id' => 'integer',
        'chunk_index' => 'integer',
        'content' => 'string',
        'tokens' => 'integer',
        'embedding_ref' => 'string',
        'metadata' => 'array',
        'sensitivity' => 'string',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrAiKnowledgeDocument::class, 'document_id');
    }
}
