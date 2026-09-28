<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\QuestionType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsQuestion;
use App\Modules\LMS\Models\LmsQuestionBank;
use App\Modules\LMS\Models\LmsQuestionCategory;
use App\Modules\LMS\Models\LmsQuestionOption;
use App\Modules\LMS\Models\LmsQuestionVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soal, opsi, dan versioning (§14–§22).
 *
 * Setiap perubahan soal menaikkan `version` dan menyimpan snapshot pada
 * `lms_question_versions`. Attempt kuis menyimpan snapshot-nya sendiri, jadi
 * memperbaiki soal hari ini tidak mengubah hasil kuis kemarin (§22).
 */
class QuestionService
{
    use LogsLmsAudit;

    /**
     * Buat soal beserta opsinya.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, LmsActor $actor): LmsQuestion
    {
        $type = QuestionType::tryFrom((string) ($data['question_type'] ?? ''));

        if ($type === null) {
            throw ValidationException::withMessages(['question_type' => 'Tipe soal tidak dikenal.']);
        }

        $this->assertTypeImplemented($type);
        $this->assertSettingsConsistent($type, $data);

        return DB::transaction(function () use ($data, $actor, $type): LmsQuestion {
            $question = LmsQuestion::create([
                'bank_id' => $data['bank_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'course_id' => $data['course_id'] ?? $this->courseFromCategory($data['category_id'] ?? null),
                'name' => $data['name'] ?? mb_substr(strip_tags((string) $data['question_text']), 0, 120),
                'question_type' => $type->value,
                'question_text' => $data['question_text'],
                'general_feedback' => $data['general_feedback'] ?? null,
                'default_mark' => $data['default_mark'] ?? 1,
                'difficulty' => $data['difficulty'] ?? 'MEDIUM',
                'status' => $data['status'] ?? 'ACTIVE',
                'version' => 1,
                'settings' => $this->normalizeSettings($type, $data),
                'created_by' => $actor->id,
            ]);

            $this->syncOptions($question, $data['options'] ?? []);
            $this->snapshot($question, $actor);

            $this->audit('LMS_QUESTION_CREATED', $question, [
                'question_type' => $type->value,
                'version' => 1,
            ]);

            return $question->fresh(['options', 'versions']);
        });
    }

    /**
     * Ubah soal. Bila sudah dipakai attempt, versi dinaikkan dan versi lama
     * tetap utuh untuk audit (§22).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(LmsQuestion $question, array $data, LmsActor $actor): LmsQuestion
    {
        $type = array_key_exists('question_type', $data)
            ? QuestionType::tryFrom((string) $data['question_type'])
            : $question->question_type;

        if ($type === null) {
            throw ValidationException::withMessages(['question_type' => 'Tipe soal tidak dikenal.']);
        }

        $this->assertTypeImplemented($type);

        return DB::transaction(function () use ($question, $data, $actor, $type): LmsQuestion {
            $contentChanged = ($data['question_text'] ?? $question->question_text) !== $question->question_text
                || array_key_exists('options', $data)
                || array_key_exists('settings', $data)
                || ($type->value !== $question->question_type?->value);

            $question->fill(collect($data)->only([
                'bank_id', 'category_id', 'course_id', 'name', 'question_text',
                'general_feedback', 'default_mark', 'difficulty', 'status',
            ])->all());

            $question->question_type = $type->value;
            $question->settings = $this->normalizeSettings($type, array_merge(
                ['settings' => $question->settings],
                $data,
            ));

            if ($contentChanged) {
                $question->version = (int) $question->version + 1;
            }

            $question->updated_by = $actor->id;
            $question->save();

            if (array_key_exists('options', $data)) {
                $question->options()->delete();
                $this->syncOptions($question, (array) $data['options']);
            }

            if ($contentChanged) {
                $this->snapshot($question->fresh('options'), $actor);
            }

            $this->audit('LMS_QUESTION_UPDATED', $question, [
                'version' => (int) $question->version,
                'content_changed' => $contentChanged,
            ]);

            return $question->fresh(['options', 'versions']);
        });
    }

    public function delete(LmsQuestion $question): void
    {
        $inUse = DB::table('lms_quiz_attempt_questions')->where('question_id', $question->id)->exists()
            || DB::table('lms_quiz_questions')->where('question_id', $question->id)->exists();

        if ($inUse) {
            // Tidak dihapus permanen: cukup diarsipkan supaya hasil lama tetap sah.
            $question->forceFill(['status' => 'ARCHIVED'])->save();

            $this->audit('LMS_QUESTION_ARCHIVED', $question, ['reason' => 'already_used']);

            return;
        }

        $question->delete();

        $this->audit('LMS_QUESTION_DELETED', $question, ['name' => $question->name]);
    }

    /** Duplikasi soal (dipakai saat guru menyalin soal ke bank lain). */
    public function duplicate(LmsQuestion $question, LmsActor $actor, ?array $overrides = []): LmsQuestion
    {
        return $this->create(array_merge([
            'bank_id' => $question->bank_id,
            'category_id' => $question->category_id,
            'course_id' => $question->course_id,
            'name' => $question->name.' (salinan)',
            'question_type' => $question->question_type?->value,
            'question_text' => $question->question_text,
            'general_feedback' => $question->general_feedback,
            'default_mark' => $question->default_mark,
            'difficulty' => $question->difficulty?->value,
            'settings' => $question->settings,
            'options' => $question->options->map(fn (LmsQuestionOption $option) => [
                'option_key' => $option->option_key,
                'text' => $option->text,
                'is_correct' => $option->is_correct,
                'feedback' => $option->feedback,
                'match_target' => $option->match_target,
                'sort_order' => $option->sort_order,
            ])->all(),
        ], $overrides), $actor);
    }

    /**
     * Daftar soal pada sebuah bank/kategori.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = [], int $perPage = 25)
    {
        return LmsQuestion::query()
            ->when($filters['bank_id'] ?? null, fn (Builder $q, $bank) => $q->where('bank_id', $bank))
            ->when($filters['category_id'] ?? null, function (Builder $q, $category): void {
                $ids = LmsQuestionCategory::query()->find((int) $category)?->descendantIds() ?? [(int) $category];
                $q->whereIn('category_id', $ids);
            })
            ->when($filters['course_id'] ?? null, fn (Builder $q, $course) => $q->where('course_id', $course))
            ->when($filters['question_type'] ?? null, fn (Builder $q, $type) => $q->where('question_type', $type))
            ->when($filters['difficulty'] ?? null, fn (Builder $q, $difficulty) => $q->where('difficulty', $difficulty))
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('question_text', 'like', '%'.$term.'%')))
            ->when(! ($filters['include_archived'] ?? false), fn (Builder $q) => $q->where('status', '!=', 'ARCHIVED'))
            ->with(['options', 'category'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $id): ?LmsQuestion
    {
        return LmsQuestion::query()->with(['options', 'category', 'bank'])->find($id);
    }

    /** Versi soal (audit perubahan). */
    public function versions(LmsQuestion $question): Collection
    {
        return $question->versions()->orderByDesc('version')->get();
    }

    /**
     * Ambil soal acak dari sebuah kategori — dipakai slot acak kuis (§23).
     *
     * @return Collection<int, LmsQuestion>
     */
    public function randomFromCategory(?LmsQuestionCategory $category, int $count, array $excludeIds = []): Collection
    {
        $query = LmsQuestion::query()
            ->where('status', 'ACTIVE')
            ->whereNotIn('id', $excludeIds);

        if ($category !== null) {
            $query->whereIn('category_id', $category->descendantIds());
        }

        return $query->inRandomOrder()->limit(max(0, $count))->get();
    }

    // ------------------------------------------------------------------ internal

    /** @param list<array<string, mixed>> $options */
    public function syncOptions(LmsQuestion $question, array $options): void
    {
        $type = $question->question_type;

        $normalized = collect($options)->map(function (array $option, int $index) use ($type): array {
            $isCorrect = (bool) ($option['is_correct'] ?? false);

            if ($type === QuestionType::TRUE_FALSE && ! isset($option['option_key'])) {
                $isCorrect = filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOL);
            }

            return [
                'option_key' => $option['option_key'] ?? $this->defaultKey($type, $index),
                'text' => (string) $option['text'],
                'is_correct' => $isCorrect,
                'feedback' => $option['feedback'] ?? null,
                'match_target' => $option['match_target'] ?? null,
                'sort_order' => $option['sort_order'] ?? $index + 1,
            ];
        })->all();

        foreach ($normalized as $option) {
            $question->options()->create($option);
        }

        if ($type === QuestionType::TRUE_FALSE && count($normalized) === 0) {
            foreach ([['TRUE', 'Benar', true], ['FALSE', 'Salah', false]] as [$key, $text, $correct]) {
                $question->options()->create([
                    'option_key' => $key,
                    'text' => $text,
                    'is_correct' => $correct,
                    'sort_order' => $key === 'TRUE' ? 1 : 2,
                ]);
            }
        }
    }

    protected function defaultKey(QuestionType $type, int $index): ?string
    {
        if ($type === QuestionType::MATCHING) {
            return 'L'.($index + 1);
        }

        return chr(65 + $index);
    }

    /**
     * Validasi konfigurasi per tipe supaya soal tidak pernah "setengah jadi".
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertSettingsConsistent(QuestionType $type, array $data): void
    {
        $options = (array) ($data['options'] ?? []);
        $settings = (array) ($data['settings'] ?? []);

        if (in_array($type, [QuestionType::MULTIPLE_CHOICE_SINGLE, QuestionType::MULTIPLE_CHOICE_MULTIPLE, QuestionType::TRUE_FALSE], true)) {
            $correct = collect($options)->filter(fn (array $option) => (bool) ($option['is_correct'] ?? false))->count();

            if ($type === QuestionType::MULTIPLE_CHOICE_SINGLE && $correct !== 1) {
                throw ValidationException::withMessages(['options' => 'Pilihan ganda satu jawaban harus punya tepat satu kunci.']);
            }

            if ($type === QuestionType::MULTIPLE_CHOICE_MULTIPLE && $correct < 1) {
                throw ValidationException::withMessages(['options' => 'Pilih minimal satu jawaban benar.']);
            }
        }

        if ($type === QuestionType::SHORT_ANSWER && empty($settings['accepted_answers'])) {
            throw ValidationException::withMessages(['settings.accepted_answers' => 'Isi minimal satu jawaban yang diterima.']);
        }

        if ($type === QuestionType::NUMERICAL && ! isset($settings['correct_answer'])) {
            throw ValidationException::withMessages(['settings.correct_answer' => 'Isi jawaban benar untuk soal numerik.']);
        }

        if ($type === QuestionType::MATCHING) {
            $targets = collect($options)->filter(fn (array $option) => trim((string) ($option['match_target'] ?? '')) !== '')->count();

            if ($targets === 0) {
                throw ValidationException::withMessages(['options' => 'Isi pasangan kanan untuk soal menjodohkan.']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function normalizeSettings(QuestionType $type, array $data): ?array
    {
        $settings = (array) ($data['settings'] ?? []);

        return match ($type) {
            QuestionType::SHORT_ANSWER => [
                'accepted_answers' => array_values(array_filter(array_map('trim', (array) ($settings['accepted_answers'] ?? [])))),
                'case_sensitive' => (bool) ($settings['case_sensitive'] ?? false),
                'exact_match' => (bool) ($settings['exact_match'] ?? true),
                'partial_match' => (bool) ($settings['partial_match'] ?? false),
            ],
            QuestionType::NUMERICAL => [
                'correct_answer' => isset($settings['correct_answer']) ? (float) $settings['correct_answer'] : null,
                'tolerance' => (float) ($settings['tolerance'] ?? 0),
            ],
            QuestionType::ESSAY => [
                'word_limit' => isset($settings['word_limit']) ? (int) $settings['word_limit'] : null,
                'attachment_allowed' => (bool) ($settings['attachment_allowed'] ?? false),
            ],
            default => $settings === [] ? null : $settings,
        };
    }

    protected function snapshot(LmsQuestion $question, LmsActor $actor): LmsQuestionVersion
    {
        $question->loadMissing('options');

        return LmsQuestionVersion::create([
            'question_id' => $question->id,
            'version' => (int) $question->version,
            'question_type' => (string) $question->question_type?->value,
            'question_text' => (string) $question->question_text,
            'general_feedback' => $question->general_feedback,
            'default_mark' => $question->default_mark,
            'settings' => $question->settings,
            'options' => $question->toSnapshot()['options'],
            'created_by' => $actor->id,
        ]);
    }

    protected function assertTypeImplemented(QuestionType $type): void
    {
        if (! $type->isImplemented()) {
            throw ValidationException::withMessages([
                'question_type' => 'Tipe soal '.$type->label().' disiapkan untuk phase berikutnya.',
            ]);
        }
    }

    protected function courseFromCategory(?int $categoryId): ?int
    {
        if ($categoryId === null) {
            return null;
        }

        return LmsQuestionCategory::query()->find($categoryId)?->bank?->course_id;
    }
}
