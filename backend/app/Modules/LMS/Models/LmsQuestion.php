<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\DifficultyLevel;
use App\Modules\LMS\Domain\Enums\QuestionType;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Soal di bank soal (§14–§22). */
class LmsQuestion extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity, SoftDeletes;

    protected $table = 'lms_questions';

    protected $casts = [
        'question_type' => QuestionType::class,
        'difficulty' => DifficultyLevel::class,
        'default_mark' => 'decimal:2',
        'settings' => 'array',
        'version' => 'integer',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionBank::class, 'bank_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionCategory::class, 'category_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(LmsQuestionOption::class, 'question_id')->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LmsQuestionVersion::class, 'question_id')->orderBy('version');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeInCategory(Builder $query, int|array $categoryId): Builder
    {
        return $query->whereIn('category_id', (array) $categoryId);
    }

    /** @return list<LmsQuestionOption> */
    public function correctOptions(): array
    {
        return $this->options->where('is_correct', true)->values()->all();
    }

    /**
     * Snapshot soal + opsi + kunci jawaban.
     *
     * Disimpan pada `lms_question_versions` saat soal diubah dan pada attempt
     * kuis sebagai `snapshot`, supaya hasil percobaan lama tidak berubah ketika
     * guru memperbaiki soalnya (§22).
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'question_id' => $this->id,
            'version' => (int) $this->version,
            'question_type' => $this->question_type?->value,
            'name' => $this->name,
            'question_text' => $this->question_text,
            'general_feedback' => $this->general_feedback,
            'default_mark' => (float) $this->default_mark,
            'settings' => $this->settings,
            'options' => $this->options->map(fn (LmsQuestionOption $option) => [
                'id' => $option->id,
                'option_key' => $option->option_key,
                'text' => $option->text,
                'is_correct' => (bool) $option->is_correct,
                'feedback' => $option->feedback,
                'match_target' => $option->match_target,
                'sort_order' => (int) $option->sort_order,
            ])->all(),
        ];
    }
}
