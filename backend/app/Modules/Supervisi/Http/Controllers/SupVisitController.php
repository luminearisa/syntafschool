<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Models\User;
use App\Modules\Supervisi\Application\Services\VisitAccessService;
use App\Modules\Supervisi\Application\Services\VisitAnswerService;
use App\Modules\Supervisi\Application\Services\VisitFinalizationService;
use App\Modules\Supervisi\Application\Services\VisitLifecycleService;
use App\Modules\Supervisi\Domain\Enums\ActivityKind;
use App\Modules\Supervisi\Domain\Enums\SupervisionApproach;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use App\Modules\Supervisi\Http\Resources\SupVisitPresenter;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pelaksanaan kegiatan supervisi: daftar, pembuatan, pengisian instrumen,
 * pengiriman untuk dianalisis, finalisasi, pembukaan ulang, dan pembatalan.
 */
class SupVisitController extends SupController
{
    public function __construct(
        private readonly VisitLifecycleService $lifecycle,
        private readonly VisitAnswerService $answers,
        private readonly VisitFinalizationService $finalization,
        private readonly VisitAccessService $access,
        private readonly SupVisitPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $visits = $this->access->visibleQuery($actor)
            ->with([
                'supervisor:id,full_name,nip,position,employee_type',
                'supervisee:id,full_name,nip,position,employee_type',
                'instrumentVersion.instrument:id,name,code,category',
                'acknowledgments:id,visit_id,employee_id,acknowledged_at',
            ])
            ->withCount(['answers', 'findings'])
            ->when($request->filled('status'), function (Builder $query) use ($request): void {
                $statuses = (array) $request->input('status');
                $query->whereIn('status', array_map('strtoupper', $statuses));
            })
            ->when($request->boolean('open'), fn (Builder $query) => $query->open())
            ->when($request->filled('activity_kind'), fn (Builder $query) => $query->where('activity_kind', strtoupper((string) $request->string('activity_kind'))))
            ->when($request->filled('program_id'), fn (Builder $query) => $query->where('program_id', $request->integer('program_id')))
            ->when($request->filled('supervisee_employee_id'), fn (Builder $query) => $query->where('supervisee_employee_id', $request->integer('supervisee_employee_id')))
            ->when($request->filled('supervisor_employee_id'), fn (Builder $query) => $query->where('supervisor_employee_id', $request->integer('supervisor_employee_id')))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('observed_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('observed_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', $term)
                    ->orWhere('lesson_topic', 'like', $term));
            })
            ->when($request->filled('mine'), function (Builder $query) use ($request, $actor): void {
                $employeeId = $this->access->employeeIdFor($actor);

                if ($employeeId === null) {
                    return;
                }

                match ($request->string('mine')->toString()) {
                    'supervisee' => $query->where('supervisee_employee_id', $employeeId),
                    'supervisor' => $query->where('supervisor_employee_id', $employeeId),
                    default => $query->where(fn (Builder $inner) => $inner
                        ->where('supervisee_employee_id', $employeeId)
                        ->orWhere('supervisor_employee_id', $employeeId)),
                };
            })
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->through(fn (SupVisit $visit) => $this->presenter->list($visit));

        return $this->successResponse($visits, 'Daftar kegiatan supervisi');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supervisee_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'supervisor_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'instrument_version_id' => ['nullable', 'integer', 'exists:sup_instrument_versions,id'],
            'instrument_id' => ['nullable', 'integer', 'exists:sup_instruments,id'],
            'activity_kind' => ['nullable', 'string', 'in:'.implode(',', ActivityKind::values())],
            'approach' => ['nullable', 'string', 'in:'.implode(',', SupervisionApproach::values())],
            'program_id' => ['nullable', 'integer', 'exists:sup_programs,id'],
            'schedule_id' => ['nullable', 'integer', 'exists:sup_schedules,id'],
            'assignment_id' => ['nullable', 'integer', 'exists:sup_assignments,id'],
            'subject_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'lesson_topic' => ['nullable', 'string', 'max:200'],
            'observed_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'pre_conference_at' => ['nullable', 'date'],
            'post_conference_at' => ['nullable', 'date'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        $actor = $this->actor($request);
        $this->authorizeSup(
            $actor->can('supervisi.visit.create') || $this->access->employeeIdFor($actor) !== null,
            'Anda tidak berwenang membuat kegiatan supervisi.',
        );

        $visit = $this->lifecycle->create($data, $actor);

        return $this->createdResponse(
            $this->presenter->detail($visit, $actor),
            'Kegiatan supervisi '.$visit->code.' dibuat',
        );
    }

    public function show(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanView($actor, $visit);

        return $this->successResponse($this->presenter->detail($this->load($visit), $actor), 'Detail kegiatan supervisi');
    }

    public function update(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanExecute($actor, $visit);

        $data = $request->validate([
            'lesson_topic' => ['nullable', 'string', 'max:200'],
            'observed_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'subject_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'approach' => ['nullable', 'string', 'in:'.implode(',', SupervisionApproach::values())],
            'activity_kind' => ['nullable', 'string', 'in:'.implode(',', ActivityKind::values())],
            'summary' => ['nullable', 'string', 'max:2000'],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'improvements' => ['nullable', 'string', 'max:2000'],
        ]);

        $visit->fill($data)->save();

        return $this->successResponse($this->presenter->detail($this->load($visit), $actor), 'Kegiatan supervisi diperbarui');
    }

    /** Simpan jawaban instrumen (boleh sebagian). */
    public function answers(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanExecute($actor, $visit);

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.indicator_id' => ['required', 'integer'],
            'answers.*.option_id' => ['nullable', 'integer'],
            'answers.*.option_ids' => ['nullable', 'array'],
            'answers.*.option_ids.*' => ['integer'],
            'answers.*.value_number' => ['nullable', 'numeric'],
            'answers.*.value_text' => ['nullable', 'string', 'max:500'],
            'answers.*.value_long_text' => ['nullable', 'string', 'max:5000'],
            'answers.*.value_date' => ['nullable', 'string'],
            'answers.*.is_na' => ['nullable', 'boolean'],
            'answers.*.comment' => ['nullable', 'string', 'max:2000'],
            'answers.*.evidence_note' => ['nullable', 'string', 'max:500'],
            'answers.*.client_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        $preview = $this->answers->save($visit, $data['answers'], $actor);

        return $this->successResponse(
            ['preview' => $preview, 'checklist' => $this->finalization->checklist($this->load($visit))],
            'Jawaban tersimpan',
        );
    }

    /** Nilai sementara tanpa mengubah data (untuk tampilan langsung di layar). */
    public function preview(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanView($actor, $visit);

        return $this->successResponse(
            ['preview' => $this->answers->preview($this->load($visit))],
            'Nilai sementara',
        );
    }

    /** Kirim untuk dianalisis (hitung agregat lalu naikkan status). */
    public function submit(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanExecute($actor, $visit);

        $preview = $this->answers->preview($this->load($visit));
        $missing = $preview['summary']['missing_required'] ?? [];

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'answers' => 'Masih ada '.count($missing).' indikator wajib yang belum diisi.',
            ]);
        }

        $this->answers->syncTotals($visit, $actor);
        $visit = $this->lifecycle->submitForAnalysis($visit, $actor);

        return $this->successResponse(
            $this->presenter->detail($this->load($visit), $actor),
            'Kegiatan dikirim untuk dianalisis',
        );
    }

    /** Kunci hasil (hanya supervisor berwenang finalisasi). */
    public function finalize(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanFinalize($actor, $visit);

        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:2000'],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'improvements' => ['nullable', 'string', 'max:2000'],
        ]);

        $visit = $this->finalization->finalize($visit, $actor, $data);

        return $this->successResponse(
            $this->presenter->detail($this->load($visit), $actor),
            'Hasil supervisi difinalkan dan menunggu tanda tangan yang disupervisi',
        );
    }

    public function reopen(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSup($actor->can('supervisi.visit.reopen'), 'Anda tidak berwenang membuka ulang hasil supervisi.');
        $this->access->assertCanView($actor, $visit);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $visit = $this->finalization->reopen($visit, $actor, $data['reason']);

        return $this->successResponse($this->presenter->detail($this->load($visit), $actor), 'Hasil dibuka ulang untuk koreksi');
    }

    public function cancel(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSup(
            $actor->can('supervisi.visit.cancel') || $this->access->canExecute($actor, $visit),
            'Anda tidak berwenang membatalkan kegiatan ini.',
        );

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $visit = $this->lifecycle->cancel($visit, $actor, $data['reason'] ?? null);

        return $this->successResponse($this->presenter->list($visit), 'Kegiatan dibatalkan');
    }

    public function destroy(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanExecute($actor, $visit);

        if ($visit->status !== VisitStatus::DRAFT) {
            throw ValidationException::withMessages([
                'visit' => 'Hanya kegiatan berstatus draf yang dapat dihapus; gunakan pembatalan agar jejak audit tetap utuh.',
            ]);
        }

        $visit->delete();

        return $this->successResponse(null, 'Draf kegiatan dihapus');
    }

    /** Muat seluruh relasi yang dibutuhkan presenter & pemeriksaan kelengkapan. */
    protected function load(SupVisit $visit): SupVisit
    {
        return $visit->load([
            'supervisor:id,full_name,nip,position,employee_type',
            'supervisee:id,full_name,nip,position,employee_type',
            'instrumentVersion.instrument',
            'instrumentVersion.gradeScale',
            'instrumentVersion.sections.indicators.options',
            'answers',
            'conferences',
            'findings',
            'evidence',
            'acknowledgments',
            'disputes',
            'revisions',
        ])->loadCount(['answers', 'findings']);
    }
}
