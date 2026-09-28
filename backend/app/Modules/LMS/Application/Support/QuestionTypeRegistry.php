<?php

namespace App\Modules\LMS\Application\Support;

use App\Modules\LMS\Domain\Enums\QuestionType;
use InvalidArgumentException;

/**
 * Registry penilaian soal (§15).
 *
 * Mesin kuis tidak pernah menulis `if ($type === 'ESSAY') …`. Setiap tipe
 * mendaftarkan penilainya di sini, sehingga tipe baru (DRAG_DROP, CLOZE,
 * ORDERING) cukup menambah satu entri tanpa menyentuh `QuizGradingService`.
 *
 * Snapshot soal berbentuk:
 *   question_type, settings, options[] (option_key, text, is_correct, match_target)
 * Jawaban siswa (`$response`) berbentuk array dengan kunci bergantung tipe:
 *   MULTIPLE_CHOICE_SINGLE  → option_id
 *   MULTIPLE_CHOICE_MULTIPLE→ option_ids[]
 *   TRUE_FALSE              → option_id | value(bool)
 *   SHORT_ANSWER            → text | answer
 *   NUMERICAL               → value | text
 *   ESSAY                   → text | answer
 *   MATCHING                → pairs[] = [{left_option_id, right}]
 */
class QuestionTypeRegistry
{
    /** @var array<string, callable(array<string,mixed>, array<string,mixed>, float): array<string,mixed>> */
    private array $graders = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(QuestionType $type, callable $grader): void
    {
        $this->graders[$type->value] = $grader;
    }

    public function has(QuestionType $type): bool
    {
        return isset($this->graders[$type->value]);
    }

    /**
     * Nilai satu jawaban.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $response
     * @return array{marks: float, is_correct: bool|null, feedback: string|null, needs_manual: bool}
     */
    public function grade(array $snapshot, array $response, float $marks): array
    {
        $typeValue = (string) ($snapshot['question_type'] ?? '');
        $type = QuestionType::tryFrom($typeValue);

        if ($type === null || ! isset($this->graders[$typeValue])) {
            throw new InvalidArgumentException('Tipe soal '.$typeValue.' belum memiliki penilai.');
        }

        /** @var array{marks: float, is_correct: bool|null, feedback: string|null, needs_manual: bool} $result */
        $result = ($this->graders[$typeValue])($snapshot, $response, $marks);

        return $result;
    }

    protected function registerDefaults(): void
    {
        // ---------------------------------------------------------- pilihan ganda (satu)
        $this->register(QuestionType::MULTIPLE_CHOICE_SINGLE, function (array $snapshot, array $response, float $marks): array {
            $selected = $this->intOrNull($response['option_id'] ?? null);
            $correct = $this->correctOptionIds($snapshot);
            $isCorrect = $selected !== null && in_array($selected, $correct, true);

            return $this->result($isCorrect ? $marks : 0.0, $isCorrect, $this->optionFeedback($snapshot, $selected));
        });

        // ---------------------------------------------------------- pilihan ganda (banyak)
        $this->register(QuestionType::MULTIPLE_CHOICE_MULTIPLE, function (array $snapshot, array $response, float $marks): array {
            $selected = array_values(array_unique(array_map('intval', (array) ($response['option_ids'] ?? []))));
            sort($selected);

            $correct = $this->correctOptionIds($snapshot);
            sort($correct);

            $isCorrect = $selected === $correct;

            return $this->result($isCorrect ? $marks : 0.0, $isCorrect, null);
        });

        // ---------------------------------------------------------- benar / salah
        $this->register(QuestionType::TRUE_FALSE, function (array $snapshot, array $response, float $marks): array {
            $selected = $this->intOrNull($response['option_id'] ?? null);

            if ($selected === null && array_key_exists('value', $response)) {
                $wanted = $this->truthy($response['value']) ? 'TRUE' : 'FALSE';
                $selected = $this->optionIdByKey($snapshot, $wanted);
            }

            $correct = $this->correctOptionIds($snapshot);
            $isCorrect = $selected !== null && in_array($selected, $correct, true);

            return $this->result($isCorrect ? $marks : 0.0, $isCorrect, null);
        });

        // ---------------------------------------------------------- jawaban singkat (§18)
        $this->register(QuestionType::SHORT_ANSWER, function (array $snapshot, array $response, float $marks): array {
            $settings = (array) ($snapshot['settings'] ?? []);
            $accepted = array_values(array_filter(array_map(
                fn ($answer) => trim((string) $answer),
                (array) ($settings['accepted_answers'] ?? []),
            ), fn (string $answer) => $answer !== ''));

            $given = trim((string) ($response['text'] ?? $response['answer'] ?? ''));

            if ($given === '' || $accepted === []) {
                return $this->result(0.0, false, $snapshot['general_feedback'] ?? null);
            }

            $caseSensitive = (bool) ($settings['case_sensitive'] ?? false);
            $exactMatch = (bool) ($settings['exact_match'] ?? true);
            $partialMatch = (bool) ($settings['partial_match'] ?? false);

            foreach ($accepted as $answer) {
                $left = $caseSensitive ? $given : mb_strtolower($given);
                $right = $caseSensitive ? $answer : mb_strtolower($answer);

                if ($exactMatch ? $left === $right : str_contains($left, $right)) {
                    return $this->result($marks, true, null);
                }

                if ($partialMatch) {
                    similar_text($left, $right, $percent);

                    if ($percent >= 85.0) {
                        return $this->result(round($marks * 0.5, 2), false, 'Jawaban mendekati benar (dinilai sebagian).');
                    }
                }
            }

            return $this->result(0.0, false, 'Jawaban yang diterima: '.implode(', ', $accepted).'.');
        });

        // ---------------------------------------------------------- numerik (§19)
        $this->register(QuestionType::NUMERICAL, function (array $snapshot, array $response, float $marks): array {
            $settings = (array) ($snapshot['settings'] ?? []);
            $expected = $settings['correct_answer'] ?? null;
            $tolerance = (float) ($settings['tolerance'] ?? 0);

            $value = $response['value'] ?? $response['text'] ?? null;

            if ($expected === null || ! is_numeric($value)) {
                return $this->result(0.0, false, null);
            }

            $isCorrect = abs((float) $value - (float) $expected) <= $tolerance;

            return $this->result($isCorrect ? $marks : 0.0, $isCorrect, null);
        });

        // ---------------------------------------------------------- esai (§20) — manual
        $this->register(QuestionType::ESSAY, function (array $snapshot, array $response, float $marks): array {
            $text = trim((string) ($response['text'] ?? $response['answer'] ?? ''));

            // Tidak pernah dinilai otomatis: guru yang memberi mark & umpan balik.
            return [
                'marks' => 0.0,
                'is_correct' => null,
                'feedback' => $text === '' ? 'Tidak dijawab.' : null,
                'needs_manual' => true,
            ];
        });

        // ---------------------------------------------------------- menjodohkan (§21)
        $this->register(QuestionType::MATCHING, function (array $snapshot, array $response, float $marks): array {
            $pairs = (array) ($response['pairs'] ?? []);

            if ($pairs === []) {
                return $this->result(0.0, false, null);
            }

            $options = collect((array) ($snapshot['options'] ?? []))->keyBy('id');
            $total = max(1, count($pairs));
            $correctPairs = 0;
            $wrong = [];

            foreach ($pairs as $pair) {
                $option = $options->get((int) ($pair['left_option_id'] ?? 0));

                if ($option === null) {
                    continue;
                }

                $expected = trim((string) ($option['match_target'] ?? ''));
                $given = trim((string) ($pair['right'] ?? ''));

                if ($expected !== '' && mb_strtolower($expected) === mb_strtolower($given)) {
                    $correctPairs++;
                } else {
                    $wrong[] = $option['text'] ?? null;
                }
            }

            $isCorrect = $correctPairs === $total;
            $awarded = round($marks * ($correctPairs / $total), 2);

            return $this->result($awarded, $isCorrect, $wrong === [] ? null : 'Pasangan yang belum tepat: '.implode(', ', array_filter($wrong)).'.');
        });

        // ---------------------------------------------------------- cadangan tipe lanjutan
        foreach ([QuestionType::DRAG_DROP, QuestionType::CLOZE, QuestionType::ORDERING] as $future) {
            $this->register($future, fn (array $snapshot, array $response, float $marks): array => [
                'marks' => 0.0,
                'is_correct' => null,
                'feedback' => 'Tipe soal ini belum aktif.',
                'needs_manual' => true,
            ]);
        }
    }

    /** @return array{marks: float, is_correct: bool|null, feedback: string|null, needs_manual: bool} */
    protected function result(float $marks, ?bool $isCorrect, ?string $feedback): array
    {
        return [
            'marks' => round($marks, 2),
            'is_correct' => $isCorrect,
            'feedback' => $feedback,
            'needs_manual' => false,
        ];
    }

    /** @return list<int> */
    protected function correctOptionIds(array $snapshot): array
    {
        return array_values(array_map('intval', array_column(array_filter(
            (array) ($snapshot['options'] ?? []),
            fn (array $option) => (bool) ($option['is_correct'] ?? false),
        ), 'id')));
    }

    protected function optionIdByKey(array $snapshot, string $key): ?int
    {
        foreach ((array) ($snapshot['options'] ?? []) as $option) {
            if (strcasecmp((string) ($option['option_key'] ?? ''), $key) === 0) {
                return (int) $option['id'];
            }
        }

        return null;
    }

    protected function optionFeedback(array $snapshot, ?int $selected): ?string
    {
        if ($selected === null) {
            return null;
        }

        foreach ((array) ($snapshot['options'] ?? []) as $option) {
            if ((int) $option['id'] === $selected) {
                return $option['feedback'] ?? null;
            }
        }

        return null;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    protected function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
