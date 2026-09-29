<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use App\Modules\Supervisi\Domain\Enums\InstrumentVersionStatus;
use App\Modules\Supervisi\Domain\Enums\SupervisorKind;
use App\Modules\Supervisi\Models\SupGradeScale;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupIndicatorOption;
use App\Modules\Supervisi\Models\SupInstrument;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pembuat & pemelihara instrumen supervisi.
 *
 * Instrumen dibuat **fleksibel**: tiap indikator dapat meminta pilihan skala
 * rubrik (`SCALE`), entri angka langsung (`NUMBER`), ya/tidak (`BOOLEAN`),
 * pilihan ganda, maupun isian teks/tanggal/berkas — semuanya dalam satu
 * instrumen bila perlu.
 *
 * Versi yang sudah diterbitkan tidak dapat diubah; perubahan dilakukan dengan
 * mengkloning versi baru (menjaga nilai lama tetap sahih).
 */
class InstrumentBuilderService
{
    public function __construct(
        private readonly ScoringEngine $scoring,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Instrumen
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function createInstrument(array $data, ?User $actor = null): SupInstrument
    {
        $instrument = SupInstrument::query()->create([
            'school_id' => $data['school_id'] ?? null,
            'code' => $data['code'] ?? $this->uniqueCode($data['name']),
            'name' => $data['name'],
            'category' => $data['category'] ?? 'AKADEMIK',
            'target_type' => $data['target_type'] ?? 'GURU',
            'description' => $data['description'] ?? null,
            'is_system' => false,
            'is_active' => true,
            'created_by' => $actor?->id,
        ]);

        $this->audit->log('instrument.created', $instrument, null, $instrument->toArray(), null, $actor);

        return $instrument;
    }

    /** @param array<string,mixed> $data */
    public function updateInstrument(SupInstrument $instrument, array $data, ?User $actor = null): SupInstrument
    {
        $before = $instrument->toArray();

        $instrument->fill(array_filter([
            'name' => $data['name'] ?? null,
            'category' => $data['category'] ?? null,
            'target_type' => $data['target_type'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? null,
        ], fn ($value) => $value !== null));

        $instrument->save();

        $this->audit->log('instrument.updated', $instrument, $before, $instrument->toArray(), null, $actor);

        return $instrument;
    }

    // ------------------------------------------------------------------
    // Versi
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function createVersion(SupInstrument $instrument, array $data = [], ?User $actor = null): SupInstrumentVersion
    {
        $next = (int) $instrument->versions()->max('version') + 1;

        $version = $instrument->versions()->create([
            'version' => $next,
            'status' => InstrumentVersionStatus::DRAFT->value,
            'scoring_mode' => $data['scoring_mode'] ?? 'PERCENT',
            'scale_type' => $data['scale_type'] ?? 'SCALE_1_4',
            'grade_scale_id' => $data['grade_scale_id'] ?? $this->defaultGradeScale()?->id,
            'requires_action_plan' => $data['requires_action_plan'] ?? true,
            'allows_multi_source' => $data['allows_multi_source'] ?? false,
            'guidance' => $data['guidance'] ?? null,
            'change_note' => $data['change_note'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $this->audit->log('instrument.version_created', $version, null, [
            'instrument_id' => $instrument->id,
            'version' => $next,
        ], null, $actor);

        return $version;
    }

    /**
     * Susun ulang struktur versi (bagian → indikator → opsi) dari payload UI.
     *
     * Hanya boleh pada versi DRAF: versi terbit bersifat beku.
     *
     * @param  list<array<string,mixed>>  $sections
     */
    public function syncStructure(SupInstrumentVersion $version, array $sections, ?User $actor = null): SupInstrumentVersion
    {
        $this->assertEditable($version);

        DB::transaction(function () use ($version, $sections): void {
            $version->sections()->delete();

            foreach (array_values($sections) as $sectionIndex => $sectionPayload) {
                $section = $version->sections()->create([
                    'code' => $sectionPayload['code'] ?? chr(65 + $sectionIndex),
                    'title' => $sectionPayload['title'] ?? 'Bagian '.($sectionIndex + 1),
                    'description' => $sectionPayload['description'] ?? null,
                    'weight' => $sectionPayload['weight'] ?? 1,
                    'sort_order' => $sectionPayload['sort_order'] ?? $sectionIndex,
                ]);

                foreach (array_values($sectionPayload['indicators'] ?? []) as $indicatorIndex => $indicatorPayload) {
                    $this->createIndicator($section, $indicatorPayload, $indicatorIndex);
                }
            }

            $version->forceFill(['max_score' => $this->scoring->versionMaxScore($version)])->save();
        });

        $this->audit->log('instrument.structure_synced', $version, null, [
            'sections' => count($sections),
            'max_score' => $version->fresh()->max_score,
        ], null, $actor);

        return $version->fresh(['sections.indicators.options']);
    }

    /** @param array<string,mixed> $data */
    public function createIndicator(SupSection $section, array $data, int $sortOrder = 0): SupIndicator
    {
        $type = IndicatorInputType::from($data['input_type'] ?? 'SCALE');

        $indicator = $section->indicators()->create([
            'code' => $data['code'] ?? null,
            'statement' => $data['statement'],
            'guidance' => $data['guidance'] ?? null,
            'input_type' => $type->value,
            'scale_code' => $data['scale_code'] ?? null,
            'score_min' => $data['score_min'] ?? null,
            'score_max' => $data['score_max'] ?? null,
            'score_step' => $data['score_step'] ?? null,
            'score_decimals' => $data['score_decimals'] ?? 2,
            'score_unit' => $data['score_unit'] ?? null,
            'direction' => $data['direction'] ?? 'HIGHER_BETTER',
            'score_yes' => $data['score_yes'] ?? ($type === IndicatorInputType::BOOLEAN ? 1 : null),
            'score_no' => $data['score_no'] ?? ($type === IndicatorInputType::BOOLEAN ? 0 : null),
            'score_partial' => $data['score_partial'] ?? null,
            'is_scored' => $data['is_scored'] ?? $type->isScored(),
            'is_required' => $data['is_required'] ?? true,
            'allow_na' => $data['allow_na'] ?? false,
            'is_critical' => $data['is_critical'] ?? false,
            'weight' => $data['weight'] ?? 1,
            'min_comment_below' => $data['min_comment_below'] ?? null,
            'evidence_hint' => $data['evidence_hint'] ?? null,
            'sort_order' => $data['sort_order'] ?? $sortOrder,
        ]);

        foreach (array_values($data['options'] ?? []) as $optionIndex => $optionPayload) {
            $indicator->options()->create([
                'label' => $optionPayload['label'],
                'descriptor' => $optionPayload['descriptor'] ?? null,
                'score' => (float) $optionPayload['score'],
                'tone' => $optionPayload['tone'] ?? 'GRAY',
                'is_default' => (bool) ($optionPayload['is_default'] ?? false),
                'sort_order' => $optionPayload['sort_order'] ?? $optionIndex,
            ]);
        }

        return $indicator->fresh('options');
    }

    /**
     * Terbitkan versi instrumen bila seluruh syarat terpenuhi.
     *
     * @throws ValidationException
     */
    public function publish(SupInstrumentVersion $version, ?User $actor = null): SupInstrumentVersion
    {
        $this->assertEditable($version);

        $issues = $this->checklist($version);
        if ($issues !== []) {
            throw ValidationException::withMessages([
                'version' => $issues,
            ]);
        }

        $version->loadMissing('sections.indicators.options');
        $maxScore = $this->scoring->versionMaxScore($version);

        $before = $version->toArray();

        $version->forceFill([
            'status' => InstrumentVersionStatus::PUBLISHED->value,
            'max_score' => $maxScore,
            'checksum' => $this->checksum($version),
            'published_at' => now(),
            'published_by' => $actor?->id,
        ])->save();

        $version->instrument()->update(['current_version_id' => $version->id]);
        $this->audit->log('instrument.version_published', $version, $before, $version->toArray(), null, $actor);

        return $version;
    }

    /** Daftar masalah yang menghalangi publikasi (dipakai UI sebagai checklist). */
    public function checklist(SupInstrumentVersion $version): array
    {
        $version->loadMissing('sections.indicators.options');
        $issues = [];

        if ($version->sections->isEmpty()) {
            $issues[] = 'Instrumen belum memiliki bagian (section).';
        }

        $indicatorCount = 0;
        $scoredCount = 0;

        foreach ($version->sections as $section) {
            if (trim((string) $section->title) === '') {
                $issues[] = 'Ada bagian yang belum diberi judul.';
            }

            foreach ($section->indicators as $indicator) {
                $indicatorCount++;

                if ($indicator->is_scored) {
                    $scoredCount++;
                }

                if (trim((string) $indicator->statement) === '') {
                    $issues[] = "Indikator #{$indicator->id} belum memiliki pernyataan.";
                }

                $type = $indicator->input_type;

                if ($type->usesOptions() && $indicator->options->isEmpty()) {
                    $issues[] = "Indikator \"{$indicator->statement}\" bertipe {$type->label()} tetapi belum punya opsi jawaban.";
                }

                if ($type === IndicatorInputType::SCALE && trim((string) $indicator->guidance) === '') {
                    $issues[] = "Indikator \"{$indicator->statement}\" memakai skala rubrik sehingga wajib memiliki panduan penilaian.";
                }

                if ($type === IndicatorInputType::NUMBER) {
                    if ($indicator->score_max === null) {
                        $issues[] = "Indikator \"{$indicator->statement}\" bertipe angka sehingga batas nilai maksimum wajib diisi.";
                    } elseif ($indicator->score_min !== null && $indicator->score_max <= $indicator->score_min) {
                        $issues[] = "Indikator \"{$indicator->statement}\" memiliki rentang angka yang tidak valid (maksimum harus lebih besar dari minimum).";
                    }
                }

                if ($type === IndicatorInputType::BOOLEAN && $indicator->score_yes === null && $indicator->score_partial === null) {
                    $issues[] = "Indikator \"{$indicator->statement}\" memakai ya/tidak sehingga skor jawaban wajib diisi.";
                }
            }
        }

        if ($indicatorCount === 0) {
            $issues[] = 'Instrumen belum memiliki indikator.';
        }

        if ($scoredCount === 0 && $version->scoring_mode !== \App\Modules\Supervisi\Domain\Enums\ScoringMode::NARRATIVE) {
            $issues[] = 'Instrumen harus memiliki minimal satu indikator yang bernilai skor (atau ubah mode penghitungan menjadi “Naratif”).';
        }

        return $issues;
    }

    /** Salin versi menjadi draf baru (dasar perubahan instrumen). */
    public function cloneVersion(SupInstrumentVersion $source, ?User $actor = null, ?string $changeNote = null): SupInstrumentVersion
    {
        $source->loadMissing('sections.indicators.options');

        return DB::transaction(function () use ($source, $actor, $changeNote): SupInstrumentVersion {
            $draft = $this->createVersion($source->instrument, [
                'scoring_mode' => $source->scoring_mode->value,
                'scale_type' => $source->scale_type,
                'grade_scale_id' => $source->grade_scale_id,
                'requires_action_plan' => $source->requires_action_plan,
                'allows_multi_source' => $source->allows_multi_source,
                'guidance' => $source->guidance,
                'change_note' => $changeNote ?? "Salinan dari versi {$source->version}",
            ], $actor);

            $sections = $source->sections->map(fn (SupSection $section) => [
                'code' => $section->code,
                'title' => $section->title,
                'description' => $section->description,
                'weight' => $section->weight,
                'sort_order' => $section->sort_order,
                'indicators' => $section->indicators->map(fn (SupIndicator $indicator) => [
                    'code' => $indicator->code,
                    'statement' => $indicator->statement,
                    'guidance' => $indicator->guidance,
                    'input_type' => $indicator->input_type->value,
                    'scale_code' => $indicator->scale_code,
                    'score_min' => $indicator->score_min,
                    'score_max' => $indicator->score_max,
                    'score_step' => $indicator->score_step,
                    'score_decimals' => $indicator->score_decimals,
                    'score_unit' => $indicator->score_unit,
                    'direction' => $indicator->direction->value,
                    'score_yes' => $indicator->score_yes,
                    'score_no' => $indicator->score_no,
                    'score_partial' => $indicator->score_partial,
                    'is_scored' => $indicator->is_scored,
                    'is_required' => $indicator->is_required,
                    'allow_na' => $indicator->allow_na,
                    'is_critical' => $indicator->is_critical,
                    'weight' => $indicator->weight,
                    'min_comment_below' => $indicator->min_comment_below,
                    'evidence_hint' => $indicator->evidence_hint,
                    'sort_order' => $indicator->sort_order,
                    'options' => $indicator->options->map(fn (SupIndicatorOption $option) => [
                        'label' => $option->label,
                        'descriptor' => $option->descriptor,
                        'score' => $option->score,
                        'tone' => $option->tone->value,
                        'is_default' => $option->is_default,
                        'sort_order' => $option->sort_order,
                    ])->all(),
                ])->all(),
            ])->all();

            return $this->syncStructure($draft, $sections, $actor);
        });
    }

    /** Ekspor definisi versi (untuk arsip, saling tukar antar sekolah). */
    public function exportVersion(SupInstrumentVersion $version): array
    {
        $version->loadMissing(['instrument', 'sections.indicators.options', 'gradeScale']);

        return [
            'instrument' => [
                'code' => $version->instrument->code,
                'name' => $version->instrument->name,
                'category' => $version->instrument->category->value,
                'target_type' => $version->instrument->target_type->value,
                'description' => $version->instrument->description,
            ],
            'version' => [
                'version' => $version->version,
                'scoring_mode' => $version->scoring_mode->value,
                'scale_type' => $version->scale_type,
                'grade_scale_code' => $version->gradeScale?->code,
                'requires_action_plan' => $version->requires_action_plan,
                'allows_multi_source' => $version->allows_multi_source,
                'guidance' => $version->guidance,
                'max_score' => $version->max_score,
            ],
            'sections' => $version->sections->map(fn (SupSection $section) => [
                'code' => $section->code,
                'title' => $section->title,
                'description' => $section->description,
                'weight' => $section->weight,
                'sort_order' => $section->sort_order,
                'indicators' => $section->indicators->map(fn (SupIndicator $indicator) => [
                    'code' => $indicator->code,
                    'statement' => $indicator->statement,
                    'guidance' => $indicator->guidance,
                    'input_type' => $indicator->input_type->value,
                    'scale_code' => $indicator->scale_code,
                    'score_min' => $indicator->score_min,
                    'score_max' => $indicator->score_max,
                    'score_step' => $indicator->score_step,
                    'score_decimals' => $indicator->score_decimals,
                    'score_unit' => $indicator->score_unit,
                    'direction' => $indicator->direction->value,
                    'score_yes' => $indicator->score_yes,
                    'score_no' => $indicator->score_no,
                    'score_partial' => $indicator->score_partial,
                    'is_scored' => $indicator->is_scored,
                    'is_required' => $indicator->is_required,
                    'allow_na' => $indicator->allow_na,
                    'is_critical' => $indicator->is_critical,
                    'weight' => $indicator->weight,
                    'min_comment_below' => $indicator->min_comment_below,
                    'evidence_hint' => $indicator->evidence_hint,
                    'sort_order' => $indicator->sort_order,
                    'options' => $indicator->options->map(fn (SupIndicatorOption $option) => [
                        'label' => $option->label,
                        'descriptor' => $option->descriptor,
                        'score' => $option->score,
                        'tone' => $option->tone->value,
                        'sort_order' => $option->sort_order,
                    ])->all(),
                ])->all(),
            ])->all(),
        ];
    }

    /** Impor definisi versi menjadi draf baru pada instrumen tujuan. */
    public function importVersion(SupInstrument $instrument, array $payload, ?User $actor = null): SupInstrumentVersion
    {
        $draft = $this->createVersion($instrument, [
            'scoring_mode' => $payload['version']['scoring_mode'] ?? 'PERCENT',
            'scale_type' => $payload['version']['scale_type'] ?? 'SCALE_1_4',
            'grade_scale_id' => SupGradeScale::query()
                ->where('code', $payload['version']['grade_scale_code'] ?? '')
                ->value('id'),
            'requires_action_plan' => $payload['version']['requires_action_plan'] ?? true,
            'allows_multi_source' => $payload['version']['allows_multi_source'] ?? false,
            'guidance' => $payload['version']['guidance'] ?? null,
            'change_note' => 'Impor dari berkas instrumen',
        ], $actor);

        return $this->syncStructure($draft, $payload['sections'] ?? [], $actor);
    }

    // ------------------------------------------------------------------
    // Pembantu
    // ------------------------------------------------------------------

    public function assertEditable(SupInstrumentVersion $version): void
    {
        if (! $version->isEditable()) {
            throw ValidationException::withMessages([
                'version' => "Versi {$version->version} sudah berstatus {$version->status->label()} dan tidak dapat diubah. Kloning menjadi versi baru terlebih dahulu.",
            ]);
        }
    }

    /** Kode instrumen unik dari nama (mis. “Observasi Kelas” → observasi-kelas). */
    public function uniqueCode(string $name): string
    {
        $base = Str::slug($name) ?: 'instrumen';
        $code = $base;
        $suffix = 1;

        while (SupInstrument::withTrashed()->where('code', $code)->exists()) {
            $code = $base.'-'.(++$suffix);
        }

        return $code;
    }

    /** Sidik jari struktur versi (mendeteksi perubahan definisi). */
    protected function checksum(SupInstrumentVersion $version): string
    {
        $version->loadMissing('sections.indicators.options');

        $payload = $version->sections->map(fn (SupSection $section) => [
            'code' => $section->code,
            'weight' => $section->weight,
            'indicators' => $section->indicators->map(fn (SupIndicator $indicator) => [
                'code' => $indicator->code,
                'input_type' => $indicator->input_type->value,
                'weight' => $indicator->weight,
                'max' => $this->scoring->indicatorMaxScore($indicator),
                'options' => $indicator->options->map(fn (SupIndicatorOption $option) => [$option->label, $option->score])->all(),
            ])->all(),
        ])->all();

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /** Skala predikat bawaan (dapat diganti sekolah lewat `sup_grade_scales`). */
    public function defaultGradeScale(): ?SupGradeScale
    {
        return SupGradeScale::query()
            ->where('is_default', true)
            ->orWhere('code', 'kemendikbud_90_80_70')
            ->orderByDesc('is_default')
            ->first();
    }

    /**
     * Daftar jenis supervisor yang boleh memakai instrumen kategori tertentu —
     * dipakai layar pengaturan untuk menyarankan penilai.
     *
     * @return list<string>
     */
    public function recommendedSupervisorKinds(string $category): array
    {
        return match ($category) {
            'AKADEMIK', 'PKG' => [
                SupervisorKind::KEPALA_SEKOLAH->value,
                SupervisorKind::WAKASEK->value,
                SupervisorKind::KOORDINATOR->value,
                SupervisorKind::PENGAWAS->value,
                SupervisorKind::MENTOR->value,
            ],
            'MANAJERIAL' => [
                SupervisorKind::KEPALA_SEKOLAH->value,
                SupervisorKind::WAKASEK->value,
                SupervisorKind::PENGAWAS->value,
            ],
            'KEPALA_SEKOLAH' => [SupervisorKind::PENGAWAS->value, SupervisorKind::EKSTERNAL->value],
            default => [
                SupervisorKind::KEPALA_SEKOLAH->value,
                SupervisorKind::WAKASEK->value,
                SupervisorKind::PENGAWAS->value,
            ],
        };
    }
}
