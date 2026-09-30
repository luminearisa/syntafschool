<?php

namespace App\Modules\CBT\Application\Services;

use App\Modules\CBT\Domain\Enums\QuestionType;
use App\Modules\CBT\Models\CbtQuestion;

/**
 * Penilaian murni: tidak menyentuh database.
 *
 * Kontrak jawaban peserta (`response`):
 *   multiple_choice / true_false : { "key": "A" }
 *   multi_select                 : { "keys": ["A","C"] }
 *   short_answer                 : { "text": "..." }
 *   numeric                      : { "value": 3.14 }
 *   matching                     : { "pairs": { "A": "2", "B": "1" } }
 *   ordering                     : { "order": ["C","A","B"] }
 *   essay                        : { "text": "..." }  — tidak dinilai otomatis
 */
class CbtScoringService
{
    /**
     * @param  array<string, mixed>|null  $response
     * @return array{awarded: float, max: float, correct: ?bool, auto: bool}
     */
    public function grade(CbtQuestion $question, ?array $response, float $maxPoints, bool $negative, float $negativeFraction): array
    {
        $max = round(max(0, $maxPoints), 2);

        if (! QuestionType::autoGraded($question->question_type)) {
            return ['awarded' => 0.0, 'max' => $max, 'correct' => null, 'auto' => false];
        }

        if ($response === null || $response === []) {
            return ['awarded' => 0.0, 'max' => $max, 'correct' => false, 'auto' => true];
        }

        $fraction = $this->fraction($question, $response);
        $awarded = round($max * $fraction, 2);

        if ($fraction < 0 && $negative) {
            $awarded = round(-1 * $max * min(1, max(0, $negativeFraction)), 2);
        } elseif ($fraction < 0) {
            $awarded = 0.0;
        }

        return [
            'awarded' => $awarded,
            'max' => $max,
            'correct' => $fraction >= 0.999,
            'auto' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function fraction(CbtQuestion $question, array $response): float
    {
        $options = $question->relationLoaded('options')
            ? $question->options
            : $question->options()->get();

        return match ($question->question_type) {
            QuestionType::MULTIPLE_CHOICE, QuestionType::TRUE_FALSE => $this->single($options, $response),
            QuestionType::MULTI_SELECT => $this->multi($options, $response),
            QuestionType::SHORT_ANSWER => $this->short($question, $response),
            QuestionType::NUMERIC => $this->numeric($question, $response),
            QuestionType::MATCHING => $this->matching($options, $response),
            QuestionType::ORDERING => $this->ordering($question, $options, $response),
            default => 0.0,
        };
    }

    private function single($options, array $response): float
    {
        $key = strtoupper(trim((string) ($response['key'] ?? '')));
        if ($key === '') {
            return 0.0;
        }
        $correct = $options->firstWhere('is_correct', true);

        return $correct && strtoupper((string) $correct->option_key) === $key ? 1.0 : 0.0;
    }

    private function multi($options, array $response): float
    {
        $given = collect($response['keys'] ?? [])
            ->map(fn ($k) => strtoupper(trim((string) $k)))
            ->filter()
            ->unique()
            ->values();
        $right = $options->where('is_correct', true)->map(fn ($o) => strtoupper((string) $o->option_key))->values();

        if ($right->isEmpty() || $given->isEmpty()) {
            return 0.0;
        }

        $hits = $given->intersect($right)->count();
        $misses = $given->diff($right)->count();
        $fraction = ($hits - $misses) / $right->count();

        return max(-1.0, min(1.0, $fraction));
    }

    private function short(CbtQuestion $question, array $response): float
    {
        $text = $this->normalize((string) ($response['text'] ?? ''));
        if ($text === '') {
            return 0.0;
        }
        $accepted = collect($question->payload['accepted'] ?? [])
            ->map(fn ($v) => $this->normalize((string) $v))
            ->filter();

        return $accepted->contains($text) ? 1.0 : 0.0;
    }

    private function numeric(CbtQuestion $question, array $response): float
    {
        if (! isset($response['value']) || ! is_numeric($response['value'])) {
            return 0.0;
        }
        $expected = (float) ($question->payload['expected'] ?? 0);
        $tolerance = (float) ($question->payload['tolerance'] ?? 0);

        return abs(((float) $response['value']) - $expected) <= $tolerance + 1e-9 ? 1.0 : 0.0;
    }

    private function matching($options, array $response): float
    {
        $pairs = $response['pairs'] ?? [];
        if (! is_array($pairs) || $pairs === []) {
            return 0.0;
        }
        $expected = $options->filter(fn ($o) => $o->match_key !== null && $o->match_key !== '');
        if ($expected->isEmpty()) {
            return 0.0;
        }
        $hits = 0;
        foreach ($expected as $option) {
            $given = strtoupper(trim((string) ($pairs[$option->option_key] ?? $pairs[strtoupper((string) $option->option_key)] ?? '')));
            if ($given === strtoupper((string) $option->match_key)) {
                $hits++;
            }
        }

        return $hits / $expected->count();
    }

    private function ordering(CbtQuestion $question, $options, array $response): float
    {
        $given = collect($response['order'] ?? [])->map(fn ($k) => strtoupper(trim((string) $k)))->filter()->values();
        $expected = collect($question->payload['order'] ?? $options->sortBy('position')->pluck('option_key')->all())
            ->map(fn ($k) => strtoupper(trim((string) $k)))
            ->values();

        if ($expected->isEmpty() || $given->count() !== $expected->count()) {
            return $given->isEmpty() ? 0.0 : 0.0;
        }

        $hits = 0;
        foreach ($expected as $i => $key) {
            if (($given[$i] ?? null) === $key) {
                $hits++;
            }
        }

        return $hits / $expected->count();
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }
}
