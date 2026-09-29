<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitAnswer;
use App\Modules\Supervisi\Models\SupVisitScore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengisian jawaban instrumen.
 *
 * Prinsip: **frontend tidak pernah mengirim skor**. Klien hanya mengirim
 * pilihan (option_id), angka mentah (value_number), atau jawaban ya/tidak —
 * server menurunkan skor dari definisi indikator pada versi instrumen yang
 * dibekukan saat kegiatan dibuat.
 *
 * Karena itu, revisi/kloning instrumen tidak dapat mengubah arti jawaban yang
 * sudah tersimpan, dan tidak ada celah "titip nilai" dari sisi klien.
 */
class VisitAnswerService
{
    public function __construct(
        private readonly ScoringEngine $engine,
        private readonly AuditLogger $audit,
        private readonly VisitLifecycleService $lifecycle,
    ) {}

    /**
     * Simpan sekumpulan jawaban (boleh sebagian, boleh seluruh instrumen).
     *
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>  ringkasan nilai terkini
     */
    public function save(SupVisit $visit, array $items, User $actor): array
    {
        $this->lifecycle->assertEditable($visit);

        $version = $visit->instrumentVersion;
        if ($version === null) {
            $this->fail('visit', 'Kegiatan ini belum memiliki instrumen.');
        }

        $version->loadMissing('sections.indicators.options');
        $indicators = $version->indicators->keyBy('id');

        $errors = [];
        $prepared = [];

        foreach (array_values($items) as $index => $item) {
            $indicator = $indicators->get((int) ($item['indicator_id'] ?? 0));

            if ($indicator === null) {
                $errors["answers.{$index}.indicator_id"] = 'Indikator tidak termasuk dalam versi instrumen kegiatan ini.';
                continue;
            }

            try {
                $prepared[] = ['indicator' => $indicator, 'attributes' => $this->normalise($indicator, $item)];
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $errors["answers.{$index}.{$field}"] = $messages[0] ?? 'Nilai tidak valid.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($visit, $prepared, $actor): void {
            foreach ($prepared as $row) {
                SupVisitAnswer::query()->updateOrCreate(
                    ['visit_id' => $visit->id, 'indicator_id' => $row['indicator']->id],
                    [...$row['attributes'], 'answered_by' => $actor->id, 'answered_at' => now()],
                );
            }
        });

        $this->audit->log('visit.answers_saved', $visit, null, ['count' => count($prepared)], null, $actor);

        return $this->preview($visit->refresh()->load('answers'), $version);
    }

    /**
     * Ringkasan nilai kegiatan tanpa mengubah data.
     *
     * @return array<string,mixed>
     */
    public function preview(SupVisit $visit, ?SupInstrumentVersion $version = null): array
    {
        $version ??= $visit->instrumentVersion;

        if ($version === null) {
            return ['summary' => null, 'indicator_scores' => [], 'band' => null];
        }

        $summary = $this->engine->scoreVisit($visit, $version);
        $band = $this->engine->bandForVersion($version, $summary['final']);

        $version->loadMissing('sections.indicators.options');
        $answers = $visit->answers->keyBy('indicator_id');

        $perIndicator = [];
        foreach ($version->indicators as $indicator) {
            $answerModel = $answers->get($indicator->id);
            $answer = $answerModel === null ? null : [
                'option_id' => $answerModel->option_id,
                'score' => $answerModel->score,
                'value_number' => $answerModel->value_number,
                'value_text' => $answerModel->value_text,
                'value_long_text' => $answerModel->value_long_text,
                'value_date' => $answerModel->value_date,
                'value_json' => $answerModel->value_json,
                'is_na' => (bool) $answerModel->is_na,
            ];

            $perIndicator[] = [
                'indicator_id' => (int) $indicator->id,
                'is_na' => (bool) ($answerModel->is_na ?? false),
                'computed_score' => $this->engine->indicatorScore($indicator, $answer),
                'max_score' => $this->engine->indicatorMaxScore($indicator),
            ];
        }

        return [
            'summary' => $summary,
            'band' => $band,
            'indicator_scores' => $perIndicator,
        ];
    }

    /**
     * Tulis agregat skor (per bagian + kolom kegiatan) ke basis data.
     *
     * Dipakai saat "kirim untuk dianalisis", saat finalisasi, dan saat nilai
     * dihitung ulang. Ringkasan tetap dapat dihitung ulang kapan pun karena
     * sumbernya jawaban per indikator.
     *
     * @return array<string,mixed>
     */
    public function syncTotals(SupVisit $visit, ?User $actor = null): array
    {
        $version = $visit->instrumentVersion;
        $preview = $this->preview($visit->load('answers'), $version);
        $summary = $preview['summary'];
        $band = $preview['band'];

        if ($summary === null || $version === null) {
            return $preview;
        }

        DB::transaction(function () use ($visit, $summary, $band): void {
            foreach ($summary['sections'] as $section) {
                SupVisitScore::query()->updateOrCreate(
                    ['visit_id' => $visit->id, 'section_id' => $section['section_id']],
                    [
                        'raw_score' => $section['raw'],
                        'max_score' => $section['max'],
                        'weighted_score' => $section['percent'] === null ? null : round($section['percent'] * $section['weight'], 2),
                        'percent' => $section['percent'],
                        'band' => $band['code'] ?? $band['label'] ?? null,
                    ],
                );
            }

            $visit->forceFill([
                'raw_score' => $summary['raw'],
                'max_score' => $summary['max'],
                'final_score' => $summary['final'],
                'grade_band' => $band['code'] ?? $band['label'] ?? null,
                'grade_label' => $band['label'] ?? null,
                'grade_tone' => $band['tone'] ?? null,
                'scored_at' => now(),
            ])->save();
        });

        if ($actor !== null) {
            $this->audit->log('visit.scored', $visit, null, [
                'raw' => $summary['raw'],
                'max' => $summary['max'],
                'final' => $summary['final'],
                'band' => $band['code'] ?? $band['label'] ?? null,
            ], null, $actor);
        }

        return $preview;
    }

    /**
     * Validasi + normalisasi satu jawaban menjadi atribut siap simpan.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    protected function normalise(SupIndicator $indicator, array $item): array
    {
        $isNa = (bool) ($item['is_na'] ?? false);
        $comment = $this->text($item['comment'] ?? null);
        $evidenceNote = $this->text($item['evidence_note'] ?? null);

        $attributes = [
            'option_id' => null,
            'score' => null,
            'value_text' => null,
            'value_long_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_json' => null,
            'is_na' => $isNa,
            'comment' => $comment,
            'evidence_note' => $evidenceNote,
            'client_uuid' => $item['client_uuid'] ?? null,
        ];

        if ($isNa) {
            if (! $indicator->allow_na) {
                $this->fail('is_na', 'Indikator ini tidak boleh ditandai "tidak relevan".');
            }

            return $attributes;
        }

        switch ($indicator->input_type) {
            case IndicatorInputType::SCALE:
                $option = $indicator->options->firstWhere('id', (int) ($item['option_id'] ?? 0));
                if ($option === null) {
                    $this->fail('option_id', 'Pilih salah satu opsi skala untuk indikator ini.');
                }
                $attributes['option_id'] = (int) $option->id;
                $attributes['score'] = (float) $option->score;
                break;

            case IndicatorInputType::MULTI_CHOICE:
                $ids = array_values(array_unique(array_map('intval', (array) ($item['option_ids'] ?? ($item['value_json']['option_ids'] ?? [])))));
                if ($ids === []) {
                    $this->fail('option_ids', 'Pilih minimal satu opsi untuk indikator ini.');
                }
                $options = $indicator->options->whereIn('id', $ids);
                if ($options->count() !== count($ids)) {
                    $this->fail('option_ids', 'Ada pilihan yang bukan bagian dari indikator ini.');
                }
                $attributes['value_json'] = ['option_ids' => $ids];
                $attributes['score'] = (float) $options->sum('score');
                break;

            case IndicatorInputType::NUMBER:
                $raw = $item['value_number'] ?? null;
                if ($raw === null || $raw === '') {
                    $this->fail('value_number', 'Isi angka untuk indikator ini.');
                }

                $value = (float) $raw;
                $min = $indicator->score_min === null ? null : (float) $indicator->score_min;
                $max = $indicator->score_max === null ? null : (float) $indicator->score_max;

                if ($min !== null && $value < $min) {
                    $this->fail('value_number', "Nilai tidak boleh kurang dari {$min}.");
                }
                if ($max !== null && $value > $max) {
                    $this->fail('value_number', "Nilai tidak boleh lebih dari {$max}.");
                }
                if ($indicator->score_step !== null && (float) $indicator->score_step > 0) {
                    $base = $min ?? 0.0;
                    $steps = ($value - $base) / (float) $indicator->score_step;
                    if (abs($steps - round($steps)) > 0.0001) {
                        $this->fail('value_number', 'Nilai harus kelipatan '.$indicator->score_step." dari {$base}.");
                    }
                }

                $attributes['value_number'] = round($value, (int) ($indicator->score_decimals ?? 2));
                $attributes['score'] = $attributes['value_number'];
                break;

            case IndicatorInputType::BOOLEAN:
                $value = strtoupper((string) $this->text($item['value_text'] ?? null));
                if (! in_array($value, ['YES', 'NO', 'PARTIAL'], true)) {
                    $this->fail('value_text', 'Pilih Ya, Tidak, atau Sebagian.');
                }
                if ($value === 'PARTIAL' && $indicator->score_partial === null) {
                    $this->fail('value_text', 'Instrumen ini tidak mengenal jawaban "sebagian".');
                }
                $attributes['value_text'] = $value;
                $attributes['score'] = match ($value) {
                    'YES' => (float) ($indicator->score_yes ?? 1),
                    'PARTIAL' => (float) $indicator->score_partial,
                    default => (float) ($indicator->score_no ?? 0),
                };
                break;

            case IndicatorInputType::DATE:
                $raw = $this->text($item['value_date'] ?? null);
                if ($raw === null) {
                    $this->fail('value_date', 'Isi tanggal untuk indikator ini.');
                }
                try {
                    $attributes['value_date'] = CarbonImmutable::parse($raw)->toDateString();
                } catch (\Throwable) {
                    $this->fail('value_date', 'Format tanggal tidak dikenali.');
                }
                break;

            default:
                // TEXT, LONG_TEXT, FILE: bukti naratif/berkas (tidak berskor).
                $long = $indicator->input_type === IndicatorInputType::LONG_TEXT;
                $text = $this->text($long ? ($item['value_long_text'] ?? null) : ($item['value_text'] ?? null));

                if ($text === null && $indicator->is_required) {
                    $this->fail($long ? 'value_long_text' : 'value_text', 'Indikator ini wajib diisi.');
                }

                if ($long) {
                    $attributes['value_long_text'] = $text;
                } else {
                    $attributes['value_text'] = $text;
                }
        }

        // Ambang komentar memakai skor efektif (setelah pembalikan arah nilai).
        $effective = $this->engine->indicatorScore($indicator, $attributes);
        if ($indicator->requiresCommentFor($effective) && ($comment === null || $comment === '')) {
            $this->fail('comment', "Beri komentar karena skor berada di bawah {$indicator->min_comment_below}.");
        }

        return $attributes;
    }

    protected function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
