<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Semester;
use App\Modules\Supervisi\Application\Services\AuditLogger;
use App\Modules\Supervisi\Application\Services\SupervisiSettings;
use App\Modules\Supervisi\Domain\Enums\InstrumentCategory;
use App\Modules\Supervisi\Domain\Enums\ProgramStatus;
use App\Modules\Supervisi\Models\SupProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Program supervisi tahunan/semester (dokumen wajib Permendikbud 15/2018). */
class SupProgramController extends SupController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupervisiSettings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $programs = SupProgram::query()
            ->with(['academicYear:id,name', 'semester:id,name'])
            ->withCount(['assignments', 'schedules'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->string('scope')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->orderByDesc('starts_at')
            ->paginate($this->perPage($request))
            ->through(fn (SupProgram $program) => $this->payload($program));

        return $this->successResponse($programs, 'Daftar program supervisi');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $actor = $this->actor($request);

        $program = SupProgram::query()->create([
            ...$data,
            'status' => ProgramStatus::DRAFT->value,
            'created_by' => $actor->id,
        ]);

        if (isset($data['targets'])) {
            foreach ($data['targets'] as $target) {
                $program->targets()->create($target);
            }
        }

        $this->audit->log('program.created', $program, null, $program->toArray(), null, $actor);

        return $this->createdResponse($this->payload($program->load('targets')), 'Program supervisi dibuat');
    }

    public function show(SupProgram $program): JsonResponse
    {
        $program->load(['targets', 'academicYear:id,name', 'semester:id,name', 'evaluations']);

        return $this->successResponse([
            ...$this->payload($program),
            'targets' => $program->targets,
            'minimum_visits' => [
                'guru' => $this->settings->minimumVisitsFor('guru'),
                'tendik' => $this->settings->minimumVisitsFor('tendik'),
                'kepala_sekolah' => $this->settings->minimumVisitsFor('kepala_sekolah'),
            ],
        ], 'Detail program supervisi');
    }

    public function update(Request $request, SupProgram $program): JsonResponse
    {
        $before = $program->toArray();
        $data = $this->validated($request, partial: true);

        $program->fill($data)->save();

        $this->audit->log('program.updated', $program, $before, $program->toArray(), null, $this->actor($request));

        return $this->successResponse($this->payload($program), 'Program supervisi diperbarui');
    }

    public function publish(Request $request, SupProgram $program): JsonResponse
    {
        if ($program->assignments()->count() === 0) {
            $this->invalid('program', 'Program belum memiliki penugasan supervisor. Tambahkan minimal satu penugasan.');
        }

        $before = $program->toArray();
        $program->forceFill([
            'status' => ProgramStatus::PUBLISHED->value,
            'published_at' => now(),
        ])->save();

        $this->audit->log('program.published', $program, $before, $program->toArray(), null, $this->actor($request));

        return $this->successResponse($this->payload($program), 'Program supervisi diterbitkan');
    }

    public function close(Request $request, SupProgram $program): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $before = $program->toArray();

        $program->forceFill(['status' => ProgramStatus::CLOSED->value])->save();
        $this->audit->log('program.closed', $program, $before, $program->toArray(), $data['note'] ?? null, $this->actor($request));

        return $this->successResponse($this->payload($program), 'Program supervisi ditutup');
    }

    /** @return array<string,mixed> */
    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:180'],
            'scope' => ['nullable', 'string', 'in:AKADEMIK,MANAJERIAL,GABUNGAN'],
            'objective' => ['nullable', 'string'],
            'background' => ['nullable', 'string'],
            'academic_year_id' => ['nullable', 'integer', Rule::exists(AcademicYear::class, 'id')],
            'semester_id' => ['nullable', 'integer', Rule::exists(Semester::class, 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'budget_note' => ['nullable', 'string'],
            'technique_plan' => ['nullable', 'array'],
            'technique_plan.*' => ['string', 'in:'.implode(',', [
                'KUNJUNGAN_KELAS', 'OBSERVASI_KELAS', 'PERTEMUAN_INDIVIDUAL', 'KUNJUNGAN_ANTARKELAS',
                'PENILAIAN_DIRI', 'SUPERVISI_SEJAWAT', 'LESSON_STUDY', 'COACHING', 'WORKSHOP',
                'DEMONSTRASI_MENGAJAR', 'TELAAH_DOKUMEN',
            ])],
            'targets' => ['nullable', 'array'],
            'targets.*.target_type' => ['required_with:targets', 'string', 'max:30'],
            'targets.*.target_id' => ['nullable', 'integer'],
            'targets.*.planned_count' => ['nullable', 'integer', 'min:0'],
            'targets.*.note' => ['nullable', 'string'],
        ]);
    }

    /** @return array<string,mixed> */
    protected function payload(SupProgram $program): array
    {
        return [
            'id' => $program->id,
            'title' => $program->title,
            'scope' => $program->scope,
            'objective' => $program->objective,
            'background' => $program->background,
            'status' => $program->status->value,
            'status_label' => $program->status->label(),
            'academic_year' => $program->academicYear?->name,
            'semester' => $program->semester?->name,
            'starts_at' => $program->starts_at?->toDateString(),
            'ends_at' => $program->ends_at?->toDateString(),
            'technique_plan' => $program->technique_plan,
            'assignments_count' => $program->assignments_count ?? $program->assignments()->count(),
            'schedules_count' => $program->schedules_count ?? $program->schedules()->count(),
            'published_at' => $program->published_at?->toIso8601String(),
            'categories' => InstrumentCategory::values(),
        ];
    }
}
