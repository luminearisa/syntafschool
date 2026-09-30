<?php

namespace App\Modules\CBT\Application\Services;

use App\Modules\CBT\Models\CbtBlueprint;
use App\Modules\CBT\Models\CbtQuestion;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Menyusun paket soal untuk satu percobaan.
 *
 * Butir `required` selalu masuk. Sisa kuota diisi acak dari bank sesuai
 * aturan kisi (topik / tingkat kesukaran / tipe), tanpa mengulang soal.
 */
class CbtAssemblyService
{
    /**
     * @return Collection<int, array{question: CbtQuestion, points: float}>
     */
    public function assemble(CbtBlueprint $blueprint, int $seed): Collection
    {
        $blueprint->loadMissing(['items.question.options', 'rules']);

        $picked = collect();
        $used = [];

        foreach ($blueprint->items as $item) {
            $question = $item->question;
            if ($question === null || ! $question->is_active) {
                continue;
            }
            $picked->push([
                'question' => $question,
                'points' => (float) ($item->points ?? $question->points),
            ]);
            $used[$question->id] = true;
        }

        $target = max($picked->count(), (int) $blueprint->question_count);
        $rng = $seed;

        foreach ($blueprint->rules as $rule) {
            if ($picked->count() >= $target) {
                break;
            }
            $need = min((int) $rule->count, $target - $picked->count());
            if ($need < 1) {
                continue;
            }

            $pool = CbtQuestion::query()
                ->with('options')
                ->where('is_active', true)
                ->when($blueprint->subject_id, fn ($q) => $q->where('subject_id', $blueprint->subject_id))
                ->when($rule->topic, fn ($q) => $q->where('topic', $rule->topic))
                ->when($rule->difficulty, fn ($q) => $q->where('difficulty', $rule->difficulty))
                ->when($rule->question_type, fn ($q) => $q->where('question_type', $rule->question_type))
                ->when($used !== [], fn ($q) => $q->whereNotIn('id', array_keys($used)))
                ->get();

            foreach ($this->sample($pool, $need, $rng) as $question) {
                $picked->push([
                    'question' => $question,
                    'points' => (float) ($rule->points ?? $question->points),
                ]);
                $used[$question->id] = true;
                $rng = ($rng * 1103515245 + 12345) & 0x7fffffff;
            }
        }

        if ($picked->isEmpty()) {
            throw new RuntimeException('Tidak ada soal aktif yang dapat disusun untuk kisi ini.');
        }

        if ($blueprint->question_count > 0 && $picked->count() > (int) $blueprint->question_count) {
            $picked = $picked->take((int) $blueprint->question_count);
        }

        return $picked->values();
    }

    /**
     * @param  Collection<int, CbtQuestion>  $pool
     * @return list<CbtQuestion>
     */
    private function sample(Collection $pool, int $need, int $seed): array
    {
        $items = $pool->values()->all();
        $n = count($items);
        if ($n === 0 || $need < 1) {
            return [];
        }

        for ($i = $n - 1; $i > 0; $i--) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $j = $seed % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_slice($items, 0, min($need, $n));
    }

    /**
     * @param  list<int|string>  $keys
     * @return list<int|string>
     */
    public function shuffleKeys(array $keys, int $seed): array
    {
        $n = count($keys);
        for ($i = $n - 1; $i > 0; $i--) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $j = $seed % ($i + 1);
            [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
        }

        return array_values($keys);
    }
}
