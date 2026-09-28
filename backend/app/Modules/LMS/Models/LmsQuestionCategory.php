<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kategori soal bertingkat di dalam bank soal (§14). */
class LmsQuestionCategory extends BaseModel
{
    protected $table = 'lms_question_categories';

    public function bank(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionBank::class, 'bank_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(LmsQuestion::class, 'category_id');
    }

    /** Termasuk seluruh sub-kategori — dipakai pemilihan soal acak (§23). */
    public function descendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->descendantIds());
        }

        return $ids;
    }
}
