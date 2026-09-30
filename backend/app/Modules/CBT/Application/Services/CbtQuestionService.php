<?php

namespace App\Modules\CBT\Application\Services;

use App\Modules\CBT\Domain\Enums\QuestionType;
use App\Modules\CBT\Models\CbtQuestion;
use App\Modules\CBT\Models\CbtQuestionRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CbtQuestionService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $options
     */
    public function create(array $data, array $options, ?int $userId): CbtQuestion
    {
        $this->assertWellFormed($data['question_type'], $data['payload'] ?? [], $options);

        return DB::transaction(function () use ($data, $options, $userId) {
            $question = CbtQuestion::create([
                ...$data,
                'payload' => $data['payload'] ?? null,
                'version' => 1,
                'created_by' => $userId,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->syncOptions($question, $options);
            $this->snapshot($question, $userId);

            return $question->load('options');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $options
     */
    public function update(CbtQuestion $question, array $data, ?array $options, ?int $userId): CbtQuestion
    {
        $type = $data['question_type'] ?? $question->question_type;
        $payload = array_key_exists('payload', $data) ? ($data['payload'] ?? []) : ($question->payload ?? []);
        $optionRows = $options ?? $question->options()->get()->map(fn ($o) => [
            'option_key' => $o->option_key,
            'text' => $o->text,
            'is_correct' => $o->is_correct,
            'match_key' => $o->match_key,
            'position' => $o->position,
        ])->all();

        $this->assertWellFormed($type, $payload, $optionRows);

        return DB::transaction(function () use ($question, $data, $options, $userId) {
            $question->fill($data);
            $question->version = (int) $question->version + 1;
            $question->save();

            if ($options !== null) {
                $question->options()->delete();
                $this->syncOptions($question, $options);
            }

            $this->snapshot($question, $userId);

            return $question->fresh('options');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $options
     */
    public function assertWellFormed(string $type, array $payload, array $options): void
    {
        if (! in_array($type, QuestionType::all(), true)) {
            throw ValidationException::withMessages(['question_type' => 'Tipe soal tidak dikenal.']);
        }

        $keys = collect($options)->pluck('option_key')->map(fn ($k) => strtoupper(trim((string) $k)));
        if ($keys->count() !== $keys->unique()->count()) {
            throw ValidationException::withMessages(['options' => 'Kunci opsi tidak boleh ganda.']);
        }

        $correct = collect($options)->filter(fn ($o) => (bool) ($o['is_correct'] ?? false))->count();

        if (in_array($type, [QuestionType::MULTIPLE_CHOICE, QuestionType::TRUE_FALSE], true) && $correct !== 1) {
            throw ValidationException::withMessages(['options' => 'Tipe ini wajib memiliki tepat satu jawaban benar.']);
        }
        if ($type === QuestionType::MULTI_SELECT && $correct < 1) {
            throw ValidationException::withMessages(['options' => 'Pilih minimal satu jawaban benar.']);
        }
        if (in_array($type, [QuestionType::MULTIPLE_CHOICE, QuestionType::MULTI_SELECT, QuestionType::MATCHING, QuestionType::ORDERING], true) && count($options) < 2) {
            throw ValidationException::withMessages(['options' => 'Minimal dua opsi.']);
        }
        if ($type === QuestionType::TRUE_FALSE && count($options) !== 2) {
            throw ValidationException::withMessages(['options' => 'Benar/salah harus tepat dua opsi.']);
        }
        if ($type === QuestionType::SHORT_ANSWER && collect($payload['accepted'] ?? [])->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
            throw ValidationException::withMessages(['payload' => 'Isian singkat butuh minimal satu jawaban yang diterima.']);
        }
        if ($type === QuestionType::NUMERIC && ! isset($payload['expected'])) {
            throw ValidationException::withMessages(['payload' => 'Soal numerik butuh nilai expected.']);
        }
        if ($type === QuestionType::MATCHING) {
            $missing = collect($options)->contains(fn ($o) => trim((string) ($o['match_key'] ?? '')) === '');
            if ($missing) {
                throw ValidationException::withMessages(['options' => 'Setiap pasangan menjodohkan butuh match_key.']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function syncOptions(CbtQuestion $question, array $options): void
    {
        foreach (array_values($options) as $i => $option) {
            $question->options()->create([
                'option_key' => strtoupper(trim((string) ($option['option_key'] ?? chr(65 + $i)))),
                'text' => (string) ($option['text'] ?? ''),
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'match_key' => isset($option['match_key']) ? strtoupper(trim((string) $option['match_key'])) : null,
                'position' => (int) ($option['position'] ?? $i),
            ]);
        }
    }

    private function snapshot(CbtQuestion $question, ?int $userId): void
    {
        $question->load('options');
        CbtQuestionRevision::updateOrCreate(
            ['question_id' => $question->id, 'version' => $question->version],
            [
                'edited_by' => $userId,
                'snapshot' => [
                    'stem' => $question->stem,
                    'question_type' => $question->question_type,
                    'difficulty' => $question->difficulty,
                    'payload' => $question->payload,
                    'points' => $question->points,
                    'options' => $question->options->map->only(['option_key', 'text', 'is_correct', 'match_key', 'position'])->all(),
                ],
            ],
        );
    }
}
