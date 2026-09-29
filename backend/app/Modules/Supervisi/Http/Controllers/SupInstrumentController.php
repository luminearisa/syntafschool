<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\InstrumentBuilderService;
use App\Modules\Supervisi\Domain\Enums\IndicatorDirection;
use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use App\Modules\Supervisi\Domain\Enums\InstrumentCategory;
use App\Modules\Supervisi\Domain\Enums\InstrumentTargetType;
use App\Modules\Supervisi\Domain\Enums\InstrumentVersionStatus;
use App\Modules\Supervisi\Domain\Enums\ScoringMode;
use App\Modules\Supervisi\Models\SupGradeScale;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupIndicatorOption;
use App\Modules\Supervisi\Models\SupInstrument;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pembuat instrumen supervisi.
 *
 * Fleksibel: satu indikator dapat memakai **pilihan skala rubrik** (`SCALE`)
 * atau **entri angka langsung** (`NUMBER`), ya/tidak (`BOOLEAN`), pilihan
 * ganda, maupun teks/tanggal/berkas — bebas dicampur dalam satu instrumen.
 */
class SupInstrumentController extends SupController
{
    public function __construct(private readonly InstrumentBuilderService $builder) {}

    /** Metadata pilihan untuk layar pembuat instrumen. */
    public function metadata(): JsonResponse
    {
        return $this->successResponse([
            'categories' => collect(InstrumentCategory::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'is_appraisal' => $case->isAppraisal(),
            ]),
            'target_types' => collect(InstrumentTargetType::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ]),
            'input_types' => collect(IndicatorInputType::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'is_scored' => $case->isScored(),
                'uses_options' => $case->usesOptions(),
                'hint' => $this->inputTypeHint($case),
            ]),
            'scoring_modes' => collect(ScoringMode::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'yields_percent' => $case->yieldsPercent(),
            ]),
            'directions' => collect(IndicatorDirection::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ]),
            'grade_scales' => SupGradeScale::query()->orderByDesc('is_default')->get()->map(fn (SupGradeScale $scale) => [
                'id' => $scale->id,
                'code' => $scale->code,
                'name' => $scale->name,
                'scale_type' => $scale->scale_type,
                'bands' => $scale->bands,
                'is_default' => $scale->is_default,
            ]),
            'scale_presets' => $this->scalePresets(),
            'recommended_supervisors' => collect(InstrumentCategory::cases())
                ->mapWithKeys(fn (InstrumentCategory $category) => [
                    $category->value => $this->builder->recommendedSupervisorKinds($category->value),
                ]),
            'statuses' => collect(InstrumentVersionStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ]),
        ], 'Metadata pembuat instrumen');
    }

    /** Daftar instrumen. */
    public function index(Request $request): JsonResponse
    {
        $instruments = SupInstrument::query()
            ->with(['currentVersion:id,instrument_id,version,status,max_score,scoring_mode,scale_type'])
            ->withCount('versions')
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('target_type'), fn ($q) => $q->where('target_type', $request->string('target_type')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('code', 'like', '%'.$request->string('search').'%')))
            ->orderBy('category')
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->through(fn (SupInstrument $instrument) => $this->instrumentPayload($instrument));

        return $this->successResponse($instruments, 'Daftar instrumen berhasil diambil');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'code' => ['nullable', 'string', 'max:60', 'unique:sup_instruments,code'],
            'category' => ['required', 'string', 'in:'.implode(',', InstrumentCategory::values())],
            'target_type' => ['required', 'string', 'in:'.implode(',', InstrumentTargetType::values())],
            'description' => ['nullable', 'string', 'max:500'],
            'scoring_mode' => ['nullable', 'string', 'in:'.implode(',', ScoringMode::values())],
            'scale_type' => ['nullable', 'string', 'max:20'],
            'grade_scale_id' => ['nullable', 'integer', 'exists:sup_grade_scales,id'],
            'guidance' => ['nullable', 'string'],
            'requires_action_plan' => ['nullable', 'boolean'],
            'allows_multi_source' => ['nullable', 'boolean'],
        ]);

        $instrument = $this->builder->createInstrument($data, $this->actor($request));
        $version = $this->builder->createVersion($instrument, $data, $this->actor($request));

        return $this->createdResponse([
            'instrument' => $this->instrumentPayload($instrument->fresh('currentVersion')),
            'version' => $this->versionPayload($version),
        ], 'Instrumen dibuat sebagai draf versi 1');
    }

    public function show(SupInstrument $instrument): JsonResponse
    {
        $instrument->load(['versions' => fn ($query) => $query->orderByDesc('version')]);

        return $this->successResponse([
            ...$this->instrumentPayload($instrument),
            'versions' => $instrument->versions->map(fn (SupInstrumentVersion $version) => $this->versionPayload($version)),
        ], 'Detail instrumen berhasil diambil');
    }

    public function update(Request $request, SupInstrument $instrument): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', InstrumentCategory::values())],
            'target_type' => ['sometimes', 'string', 'in:'.implode(',', InstrumentTargetType::values())],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->authorizeSup(! $instrument->is_system || $this->actor($request)->can('supervisi.settings.manage'),
            'Instrumen bawaan hanya dapat diubah oleh admin.');

        return $this->successResponse(
            $this->instrumentPayload($this->builder->updateInstrument($instrument, $data, $this->actor($request))->fresh('currentVersion')),
            'Instrumen diperbarui',
        );
    }

    /** Hapus lembut — hanya instrumen buatan sekolah yang belum dipakai. */
    public function destroy(Request $request, SupInstrument $instrument): JsonResponse
    {
        if ($instrument->is_system) {
            $this->invalid('instrument', 'Instrumen bawaan modul tidak dapat dihapus; nonaktifkan saja.');
        }

        if ($instrument->versions()->where('status', InstrumentVersionStatus::PUBLISHED->value)->exists()
            && ! $request->boolean('force')) {
            $this->invalid('instrument', 'Instrumen sudah memiliki versi terbit. Kirim force=true bila tetap ingin menonaktifkannya.');
        }

        $instrument->forceFill(['is_active' => false])->save();
        $instrument->delete();

        return $this->successResponse(null, 'Instrumen dinonaktifkan');
    }

    // ------------------------------------------------------------------
    // Versi & struktur
    // ------------------------------------------------------------------

    public function storeVersion(Request $request, SupInstrument $instrument): JsonResponse
    {
        $data = $request->validate([
            'scoring_mode' => ['nullable', 'string', 'in:'.implode(',', ScoringMode::values())],
            'scale_type' => ['nullable', 'string', 'max:20'],
            'grade_scale_id' => ['nullable', 'integer', 'exists:sup_grade_scales,id'],
            'guidance' => ['nullable', 'string'],
            'requires_action_plan' => ['nullable', 'boolean'],
            'allows_multi_source' => ['nullable', 'boolean'],
            'change_note' => ['nullable', 'string', 'max:500'],
            'clone_from_version_id' => ['nullable', 'integer', 'exists:sup_instrument_versions,id'],
        ]);

        if (isset($data['clone_from_version_id'])) {
            $source = SupInstrumentVersion::query()->findOrFail($data['clone_from_version_id']);
            $version = $this->builder->cloneVersion($source, $this->actor($request), $data['change_note'] ?? null);
        } else {
            $version = $this->builder->createVersion($instrument, $data, $this->actor($request));
        }

        return $this->createdResponse($this->versionPayload($version), 'Versi baru dibuat sebagai draf');
    }

    public function showVersion(SupInstrumentVersion $version): JsonResponse
    {
        $version->load(['instrument', 'gradeScale', 'sections.indicators.options']);

        return $this->successResponse([
            ...$this->versionPayload($version),
            'instrument' => $this->instrumentPayload($version->instrument),
            'grade_scale' => $version->gradeScale,
            'structure' => $version->sections->map(fn (SupSection $section) => $this->sectionPayload($section)),
            'checklist' => $this->builder->checklist($version),
        ], 'Versi instrumen berhasil diambil');
    }

    /**
     * Simpan struktur (bagian → indikator → opsi).
     *
     * Inilah endpoint yang melayani pembuat instrumen di layar: tiap indikator
     * menyatakan `input_type` (skala atau angka) beserta parameternya.
     */
    public function syncStructure(Request $request, SupInstrumentVersion $version): JsonResponse
    {
        $data = $request->validate($this->structureRules());
        $version = $this->builder->syncStructure($version, $data['sections'], $this->actor($request));

        return $this->successResponse([
            ...$this->versionPayload($version),
            'structure' => $version->sections->map(fn (SupSection $section) => $this->sectionPayload($section)),
            'checklist' => $this->builder->checklist($version),
        ], 'Struktur instrumen disimpan');
    }

    /** Pratinjau kelayakan sebelum diterbitkan. */
    public function checklist(SupInstrumentVersion $version): JsonResponse
    {
        $issues = $this->builder->checklist($version->load('sections.indicators.options'));

        return $this->successResponse([
            'ready' => $issues === [],
            'issues' => $issues,
        ], $issues === [] ? 'Instrumen siap diterbitkan' : 'Masih ada yang perlu dilengkapi');
    }

    public function publish(Request $request, SupInstrumentVersion $version): JsonResponse
    {
        $version = $this->builder->publish($version, $this->actor($request));

        return $this->successResponse($this->versionPayload($version), "Versi {$version->version} diterbitkan");
    }

    public function cloneVersion(Request $request, SupInstrumentVersion $version): JsonResponse
    {
        $data = $request->validate(['change_note' => ['nullable', 'string', 'max:500']]);
        $draft = $this->builder->cloneVersion($version, $this->actor($request), $data['change_note'] ?? null);

        return $this->createdResponse($this->versionPayload($draft), 'Versi dikloning menjadi draf baru');
    }

    public function exportVersion(SupInstrumentVersion $version): JsonResponse
    {
        return $this->successResponse($this->builder->exportVersion($version), 'Definisi instrumen diekspor');
    }

    public function importVersion(Request $request, SupInstrument $instrument): JsonResponse
    {
        $data = $request->validate([
            'payload' => ['required', 'array'],
            'payload.version' => ['required', 'array'],
            'payload.sections' => ['required', 'array'],
        ]);

        $version = $this->builder->importVersion($instrument, $data['payload'], $this->actor($request));

        return $this->createdResponse($this->versionPayload($version), 'Instrumen diimpor sebagai draf baru');
    }

    // ------------------------------------------------------------------
    // Aturan validasi & serialisasi
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    protected function structureRules(): array
    {
        return [
            'sections' => ['present', 'array'],
            'sections.*.code' => ['nullable', 'string', 'max:30'],
            'sections.*.title' => ['required', 'string', 'max:180'],
            'sections.*.description' => ['nullable', 'string'],
            'sections.*.weight' => ['nullable', 'numeric', 'min:0'],
            'sections.*.sort_order' => ['nullable', 'integer'],
            'sections.*.indicators' => ['present', 'array'],
            'sections.*.indicators.*.code' => ['nullable', 'string', 'max:30'],
            'sections.*.indicators.*.statement' => ['required', 'string', 'max:500'],
            'sections.*.indicators.*.guidance' => ['nullable', 'string'],
            'sections.*.indicators.*.input_type' => ['required', 'string', 'in:'.implode(',', IndicatorInputType::values())],
            'sections.*.indicators.*.scale_code' => ['nullable', 'string', 'max:30'],
            'sections.*.indicators.*.score_min' => ['nullable', 'numeric'],
            'sections.*.indicators.*.score_max' => ['nullable', 'numeric'],
            'sections.*.indicators.*.score_step' => ['nullable', 'numeric', 'gt:0'],
            'sections.*.indicators.*.score_decimals' => ['nullable', 'integer', 'min:0', 'max:4'],
            'sections.*.indicators.*.score_unit' => ['nullable', 'string', 'max:30'],
            'sections.*.indicators.*.direction' => ['nullable', 'string', 'in:'.implode(',', IndicatorDirection::values())],
            'sections.*.indicators.*.score_yes' => ['nullable', 'numeric'],
            'sections.*.indicators.*.score_no' => ['nullable', 'numeric'],
            'sections.*.indicators.*.score_partial' => ['nullable', 'numeric'],
            'sections.*.indicators.*.is_scored' => ['nullable', 'boolean'],
            'sections.*.indicators.*.is_required' => ['nullable', 'boolean'],
            'sections.*.indicators.*.allow_na' => ['nullable', 'boolean'],
            'sections.*.indicators.*.is_critical' => ['nullable', 'boolean'],
            'sections.*.indicators.*.weight' => ['nullable', 'numeric', 'min:0'],
            'sections.*.indicators.*.min_comment_below' => ['nullable', 'numeric'],
            'sections.*.indicators.*.evidence_hint' => ['nullable', 'string', 'max:255'],
            'sections.*.indicators.*.sort_order' => ['nullable', 'integer'],
            'sections.*.indicators.*.options' => ['nullable', 'array'],
            'sections.*.indicators.*.options.*.label' => ['required', 'string', 'max:180'],
            'sections.*.indicators.*.options.*.descriptor' => ['nullable', 'string'],
            'sections.*.indicators.*.options.*.score' => ['required', 'numeric'],
            'sections.*.indicators.*.options.*.tone' => ['nullable', 'string', 'in:GREEN,AMBER,RED,GRAY'],
            'sections.*.indicators.*.options.*.is_default' => ['nullable', 'boolean'],
            'sections.*.indicators.*.options.*.sort_order' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string,mixed> */
    protected function instrumentPayload(SupInstrument $instrument): array
    {
        return [
            'id' => $instrument->id,
            'code' => $instrument->code,
            'name' => $instrument->name,
            'category' => $instrument->category->value,
            'category_label' => $instrument->category->label(),
            'target_type' => $instrument->target_type->value,
            'target_type_label' => $instrument->target_type->label(),
            'description' => $instrument->description,
            'is_system' => $instrument->is_system,
            'is_active' => $instrument->is_active,
            'versions_count' => $instrument->versions_count ?? $instrument->versions()->count(),
            'current_version' => $instrument->currentVersion === null ? null : $this->versionPayload($instrument->currentVersion),
            'recommended_supervisor_kinds' => $this->builder->recommendedSupervisorKinds($instrument->category->value),
        ];
    }

    /** @return array<string,mixed> */
    protected function versionPayload(SupInstrumentVersion $version): array
    {
        return [
            'id' => $version->id,
            'instrument_id' => $version->instrument_id,
            'version' => $version->version,
            'status' => $version->status->value,
            'status_label' => $version->status->label(),
            'scoring_mode' => $version->scoring_mode->value,
            'scoring_mode_label' => $version->scoring_mode->label(),
            // Skala nilai akhir: mode berbobot/persentase menghasilkan 0–100;
            // mode jumlah menghasilkan skor mentah; naratif tanpa nilai.
            'final_scale' => match ($version->scoring_mode->value) {
                'SUM' => 'RAW',
                'NARRATIVE' => 'NONE',
                default => 'PERCENT',
            },
            'scale_type' => $version->scale_type,
            'grade_scale_id' => $version->grade_scale_id,
            'max_score' => $version->max_score,
            'min_passing_score' => $version->min_passing_score,
            'requires_action_plan' => $version->requires_action_plan,
            'allows_multi_source' => $version->allows_multi_source,
            'guidance' => $version->guidance,
            'change_note' => $version->change_note,
            'is_editable' => $version->isEditable(),
            'checksum' => $version->checksum,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    protected function sectionPayload(SupSection $section): array
    {
        return [
            'id' => $section->id,
            'code' => $section->code,
            'title' => $section->title,
            'description' => $section->description,
            'weight' => $section->weight,
            'sort_order' => $section->sort_order,
            'indicators' => $section->indicators->map(fn (SupIndicator $indicator) => [
                'id' => $indicator->id,
                'code' => $indicator->code,
                'statement' => $indicator->statement,
                'guidance' => $indicator->guidance,
                'input_type' => $indicator->input_type->value,
                'input_type_label' => $indicator->input_type->label(),
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
                'max_score' => $indicator->maximumScore(),
                'options' => $indicator->options->map(fn (SupIndicatorOption $option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'descriptor' => $option->descriptor,
                    'score' => $option->score,
                    'tone' => $option->tone->value,
                    'is_default' => $option->is_default,
                    'sort_order' => $option->sort_order,
                ]),
            ]),
        ];
    }

    protected function inputTypeHint(IndicatorInputType $type): string
    {
        return match ($type) {
            IndicatorInputType::SCALE => 'Supervisor memilih satu tingkat pada rubrik (mis. 1–4). Isi panduan penilaian agar antar penilai konsisten.',
            IndicatorInputType::NUMBER => 'Supervisor mengetik angka apa adanya — tentukan minimum, maksimum, dan langkah kenaikannya.',
            IndicatorInputType::BOOLEAN => 'Cocok untuk daftar periksa (ya/tidak), dengan opsi “sebagian” bila perlu.',
            IndicatorInputType::MULTI_CHOICE => 'Beberapa komponen dipilih sekaligus lalu skornya dijumlahkan.',
            IndicatorInputType::TEXT => 'Isian pendek yang tidak diskor (mis. nama dokumen yang diperiksa).',
            IndicatorInputType::LONG_TEXT => 'Uraian bebas, mis. jawaban wawancara pra/pasca-observasi atau catatan fakta kelas.',
            IndicatorInputType::DATE => 'Tanggal (mis. tanggal penyerahan perangkat pembelajaran).',
            IndicatorInputType::FILE => 'Lampiran bukti (foto, dokumen, video).',
        };
    }

    /** @return list<array<string,mixed>> */
    protected function scalePresets(): array
    {
        return [
            ['code' => 'SCALE_1_4', 'label' => 'Skala 1–4 (Kemendikbud)', 'options' => [
                ['label' => 'Kurang', 'score' => 1, 'tone' => 'RED'],
                ['label' => 'Cukup', 'score' => 2, 'tone' => 'AMBER'],
                ['label' => 'Baik', 'score' => 3, 'tone' => 'GREEN'],
                ['label' => 'Amat Baik', 'score' => 4, 'tone' => 'GREEN'],
            ]],
            ['code' => 'SCALE_0_4', 'label' => 'Skala 0–4 (Marzano)', 'options' => [
                ['label' => 'Belum tampak', 'score' => 0, 'tone' => 'RED'],
                ['label' => 'Memulai', 'score' => 1, 'tone' => 'RED'],
                ['label' => 'Berkembang', 'score' => 2, 'tone' => 'AMBER'],
                ['label' => 'Menerapkan', 'score' => 3, 'tone' => 'GREEN'],
                ['label' => 'Inovatif', 'score' => 4, 'tone' => 'GREEN'],
            ]],
            ['code' => 'SCALE_0_2', 'label' => 'Skala 0–2 (telaah RPP)', 'options' => [
                ['label' => 'Tidak ada', 'score' => 0, 'tone' => 'RED'],
                ['label' => 'Ada, kurang lengkap', 'score' => 1, 'tone' => 'AMBER'],
                ['label' => 'Ada dan lengkap', 'score' => 2, 'tone' => 'GREEN'],
            ]],
            ['code' => 'SCALE_1_5', 'label' => 'Skala 1–5 (tinjauan pegawai)', 'options' => [
                ['label' => 'Tidak memenuhi', 'score' => 1, 'tone' => 'RED'],
                ['label' => 'Perlu perbaikan', 'score' => 2, 'tone' => 'RED'],
                ['label' => 'Sesuai harapan', 'score' => 3, 'tone' => 'AMBER'],
                ['label' => 'Melebihi harapan', 'score' => 4, 'tone' => 'GREEN'],
                ['label' => 'Istimewa', 'score' => 5, 'tone' => 'GREEN'],
            ]],
            ['code' => 'YES_NO', 'label' => 'Ya / Tidak (+ Sebagian)', 'options' => [
                ['label' => 'Tidak', 'score' => 0, 'tone' => 'RED'],
                ['label' => 'Sebagian', 'score' => 0.5, 'tone' => 'AMBER'],
                ['label' => 'Ya', 'score' => 1, 'tone' => 'GREEN'],
            ]],
        ];
    }
}
