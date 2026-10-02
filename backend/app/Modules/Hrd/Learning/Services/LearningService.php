<?php

namespace App\Modules\Hrd\Learning\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Services\CompetencyService as Phase1CompetencyService;
use App\Modules\Hrd\Core\Domain\Contracts\LearningDeliveryProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\LearningProviderInterface;
use App\Modules\Hrd\Core\Services\EligibilityEngine;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Learning\Models\HrLearningProfile;
use App\Modules\Hrd\Learning\Models\HrTrainingAssessment;
use App\Modules\Hrd\Learning\Models\HrTrainingAssignment;
use App\Modules\Hrd\Learning\Models\HrTrainingCatalog;
use App\Modules\Hrd\Learning\Models\HrTrainingCompletion;
use App\Modules\Hrd\Learning\Models\HrTrainingCost;
use App\Modules\Hrd\Learning\Models\HrTrainingRequest;
use App\Modules\Hrd\Learning\Models\HrTrainingRequirement;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrSkill;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pembelajaran: HRD menyusun, LMS menyampaikan.
 *
 * Modul ini tidak menggandakan LMS. Ia menentukan pelatihan apa yang **wajib**
 * bagi seorang pegawai, menyetujui pembiayaannya, mendaftarkannya ke LMS, dan
 * menutup lingkaran: pelatihan selesai → asesmen → keterampilan diperbarui pada
 * fondasi Phase 1 → sertifikasi dan rencana pengembangan ikut menyesuaikan.
 */
class LearningService implements LearningProviderInterface
{
    public function __construct(
        private readonly EligibilityEngine $eligibility,
        private readonly LearningDeliveryProviderInterface $lms,
        private readonly Phase1CompetencyService $skills,
        private readonly HrdPhase2Events $events,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createTraining(array $attributes, User $actor): HrTrainingCatalog
    {
        return HrTrainingCatalog::query()->updateOrCreate(
            ['code' => strtoupper((string) $attributes['code'])],
            [
                'title' => $attributes['title'] ?? $attributes['code'],
                'training_type' => strtoupper((string) ($attributes['training_type'] ?? 'COURSE')),
                'provider' => strtoupper((string) ($attributes['provider'] ?? 'INTERNAL')),
                'provider_name' => $attributes['provider_name'] ?? null,
                'description' => $attributes['description'] ?? null,
                'duration_hours' => (float) ($attributes['duration_hours'] ?? 0),
                'cost' => (float) ($attributes['cost'] ?? 0),
                'currency' => $attributes['currency'] ?? 'IDR',
                'delivery_mode' => strtoupper((string) ($attributes['delivery_mode'] ?? 'ONSITE')),
                'lms_course_ref' => $attributes['lms_course_ref'] ?? null,
                'skills' => $attributes['skills'] ?? null,
                'competencies' => $attributes['competencies'] ?? null,
                'certification_code' => $attributes['certification_code'] ?? null,
                'validity_months' => $attributes['validity_months'] ?? null,
                'is_mandatory' => (bool) ($attributes['is_mandatory'] ?? false),
            ],
        );
    }

    /**
     * Pelatihan wajib berdasarkan posisi/jabatan/departemen/unit/jenis
     * kepegawaian/golongan, atau lewat aturan kelayakan (§62).
     *
     * @param array<string, mixed> $attributes
     */
    public function addRequirement(array $attributes, User $actor): HrTrainingRequirement
    {
        $training = isset($attributes['training_id'])
            ? HrTrainingCatalog::query()->findOrFail($attributes['training_id'])
            : HrTrainingCatalog::query()->where('code', strtoupper((string) ($attributes['training_code'] ?? '')))->first();

        if ($training === null) {
            throw HrdValidationException::forField('training_id', 'Pelatihan tidak ditemukan.', 'learning.training_missing');
        }

        return HrTrainingRequirement::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'REQ-'.$training->code.'-'.now()->format('YmdHis'))),
            'training_id' => $training->getKey(),
            'scope' => strtoupper((string) ($attributes['scope'] ?? 'POSITION')),
            'position_id' => $attributes['position_id'] ?? null,
            'job_id' => $attributes['job_id'] ?? null,
            'department_id' => $attributes['department_id'] ?? null,
            'organization_id' => $attributes['organization_id'] ?? null,
            'employment_type' => $attributes['employment_type'] ?? null,
            'grade_code' => $attributes['grade_code'] ?? null,
            'rule_id' => $attributes['rule_id'] ?? null,
            'is_mandatory' => (bool) ($attributes['is_mandatory'] ?? true),
            'due_days' => $attributes['due_days'] ?? null,
            'recurrence_months' => $attributes['recurrence_months'] ?? null,
            'effective_from' => $attributes['effective_from'] ?? null,
            'effective_to' => $attributes['effective_to'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    /**
     * Pelatihan wajib bagi seorang pegawai, lengkap dengan statusnya.
     *
     * @return list<array<string, mixed>>
     */
    public function requiredTraining(int $employeeId): array
    {
        $employee = HrEmployee::query()->find($employeeId);

        if ($employee === null) {
            return [];
        }

        $requirements = HrTrainingRequirement::query()
            ->with('training')
            ->where('is_active', true)
            ->where(function ($query) use ($employee) {
                $query->whereNull('position_id')->orWhere('position_id', $employee->position_id);
            })
            ->where(function ($query) use ($employee) {
                $query->whereNull('department_id')->orWhere('department_id', $employee->department_id);
            })
            ->where(function ($query) use ($employee) {
                $query->whereNull('organization_id')->orWhere('organization_id', $employee->organization_id);
            })
            ->where(function ($query) use ($employee) {
                $query->whereNull('employment_type')->orWhere('employment_type', $employee->employment_type);
            })
            ->get()
            ->filter(function (HrTrainingRequirement $requirement) use ($employee) {
                if ($requirement->rule_id === null) {
                    return true;
                }

                return $this->eligibility->forTraining($employee, ['rule_id' => $requirement->rule_id])['eligible'];
            });

        return $requirements
            ->map(function (HrTrainingRequirement $requirement) use ($employee) {
                $assignment = HrTrainingAssignment::query()
                    ->where('employee_id', $employee->getKey())
                    ->where('training_id', $requirement->training_id)
                    ->orderByDesc('id')
                    ->first();

                $completion = $assignment?->completion;

                $status = match (true) {
                    $completion !== null && $completion->expires_at !== null && $completion->expires_at->isPast() => 'EXPIRED',
                    $completion !== null => 'COMPLETED',
                    $assignment === null => 'PENDING',
                    $assignment->status === 'OVERDUE' => 'OVERDUE',
                    default => $assignment->status,
                };

                return [
                    'requirement_code' => $requirement->code,
                    'training_code' => $requirement->training?->code,
                    'training_title' => $requirement->training?->title,
                    'mandatory' => (bool) $requirement->is_mandatory,
                    'recurrence_months' => $requirement->recurrence_months !== null ? (int) $requirement->recurrence_months : null,
                    'status' => $status,
                    'due_date' => $assignment?->due_date?->toDateString(),
                    'completed_at' => $completion?->completed_at?->toDateTimeString(),
                    'expires_at' => $completion?->expires_at?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function trainingCompliance(int $employeeId): array
    {
        $required = collect($this->requiredTraining($employeeId));

        return [
            'employee_id' => $employeeId,
            'required' => $required->count(),
            'completed' => $required->where('status', 'COMPLETED')->count(),
            'pending' => $required->whereIn('status', ['PENDING', 'ASSIGNED', 'ENROLLED', 'IN_PROGRESS'])->count(),
            'overdue' => $required->where('status', 'OVERDUE')->count(),
            'expired' => $required->where('status', 'EXPIRED')->count(),
            'compliant' => $required->whereIn('status', ['COMPLETED'])->count() === $required->count(),
            'items' => $required->all(),
        ];
    }

    public function requestTraining(int $employeeId, array $payload, ?int $actorUserId = null): array
    {
        $employee = HrEmployee::query()->findOrFail($employeeId);
        $actor = $actorUserId !== null ? User::query()->findOrFail($actorUserId) : $employee->user;

        if ($actor === null) {
            throw HrdValidationException::forField('actor', 'Pelaku perubahan wajib diketahui.', 'learning.actor_required');
        }

        $request = $this->requestFor($employee, $payload, $actor);

        return ['request_id' => $request->getKey(), 'code' => $request->code, 'status' => $request->status];
    }

    /** @param array<string, mixed> $attributes */
    public function requestFor(HrEmployee $employee, array $attributes, User $actor): HrTrainingRequest
    {
        $training = isset($attributes['training_id'])
            ? HrTrainingCatalog::query()->findOrFail($attributes['training_id'])
            : HrTrainingCatalog::query()->where('code', strtoupper((string) ($attributes['training_code'] ?? '')))->first();

        if ($training === null) {
            throw HrdValidationException::forField('training_id', 'Pelatihan tidak ditemukan pada katalog.', 'learning.training_missing');
        }

        return HrTrainingRequest::query()->create([
            'code' => sprintf('TRQ-%s-%04d', now()->format('Y'), HrTrainingRequest::query()->whereYear('created_at', now()->year)->count() + 1),
            'employee_id' => $employee->getKey(),
            'training_id' => $training->getKey(),
            'requested_by_employee_id' => $attributes['requested_by_employee_id'] ?? $employee->getKey(),
            'justification' => $attributes['justification'] ?? null,
            'estimated_cost' => $attributes['estimated_cost'] ?? $training->cost,
            'currency' => $training->currency,
            'budget_code' => $attributes['budget_code'] ?? null,
            'status' => 'SUBMITTED',
            'funding_source' => $attributes['funding_source'] ?? null,
        ]);
    }

    /** Manager → HR/L&D → anggaran (§61). */
    public function decideRequest(HrTrainingRequest $request, User $actor, string $stage, bool $approved, ?string $notes = null): HrTrainingRequest
    {
        $stage = strtoupper($stage);

        if (! in_array($stage, ['MANAGER', 'HR'], true)) {
            throw HrdValidationException::forField('stage', 'Tahap persetujuan harus MANAGER atau HR.', 'learning.stage_invalid');
        }

        if ($approved && $stage === 'HR' && (bool) config('hrd_phase2.learning.budget_check_enabled', true) && $request->estimated_cost !== null) {
            $this->events->emit('LEARNING', 'training.budget_check_requested', [
                'actor' => $actor,
                'employee_id' => $request->employee_id,
                'subject_type' => HrTrainingRequest::class,
                'subject_id' => $request->getKey(),
                'title' => 'Pemeriksaan anggaran pelatihan',
                'payload' => ['estimated_cost' => (float) $request->estimated_cost, 'budget_code' => $request->budget_code],
            ]);
        }

        $request->forceFill($stage === 'MANAGER' ? [
            'status' => $approved ? 'MANAGER_APPROVED' : 'REJECTED',
            'manager_decision_notes' => $notes,
        ] : [
            'status' => $approved ? 'APPROVED' : 'REJECTED',
            'hr_decision_notes' => $notes,
            'approved_by' => $approved ? $actor->getKey() : null,
            'approved_at' => $approved ? now() : null,
        ])->save();

        if ($approved && $stage === 'HR') {
            $this->assign($request->refresh(), $actor);
        }

        $this->events->emit('LEARNING', 'training.'.$stage === 'HR' ? 'training.decided' : 'training.manager_approved', [
            'actor' => $actor,
            'employee_id' => $request->employee_id,
            'subject_type' => HrTrainingRequest::class,
            'subject_id' => $request->getKey(),
            'title' => 'Permintaan pelatihan '.($approved ? 'disetujui' : 'ditolak').' ('.$stage.')',
            'reason' => $notes,
        ]);

        return $request->refresh();
    }

    /** Pendaftaran ke pelatihan + LMS, idempoten. */
    public function assign(HrTrainingRequest|HrTrainingRequirement $source, User $actor, ?HrEmployee $employee = null): HrTrainingAssignment
    {
        $trainingId = (int) $source->training_id;
        $employeeId = $employee?->getKey() ?? ($source instanceof HrTrainingRequest ? (int) $source->employee_id : null);

        if ($employeeId === null) {
            throw HrdValidationException::forField('employee', 'Pegawai penerima pelatihan tidak diketahui.', 'learning.employee_required');
        }

        $employee = HrEmployee::query()->findOrFail($employeeId);
        $key = 'training-assignment:'.$trainingId.':'.$employeeId.':'.($source instanceof HrTrainingRequirement ? $source->getKey() : 'request');

        $existing = HrTrainingAssignment::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $training = HrTrainingCatalog::query()->findOrFail($trainingId);
        $dueDays = $source instanceof HrTrainingRequirement ? $source->due_days : null;

        $assignment = HrTrainingAssignment::query()->create([
            'code' => sprintf('TA-%s-%05d', now()->format('Y'), HrTrainingAssignment::query()->whereYear('created_at', now()->year)->count() + 1),
            'training_id' => $training->getKey(),
            'employee_id' => $employee->getKey(),
            'request_id' => $source instanceof HrTrainingRequest ? $source->getKey() : null,
            'requirement_id' => $source instanceof HrTrainingRequirement ? $source->getKey() : null,
            'status' => 'ASSIGNED',
            'is_mandatory' => $source instanceof HrTrainingRequirement ? (bool) $source->is_mandatory : false,
            'assigned_by' => $actor->getKey(),
            'due_date' => $dueDays !== null ? now()->addDays((int) $dueDays)->toDateString() : null,
            'effort_hours' => (float) $training->duration_hours,
            'idempotency_key' => $key,
        ]);

        // LMS tetap sumber kebenaran konten: HRD hanya mendaftarkan peserta.
        if (config('hrd_phase2.learning.provider') === 'lms' && $training->lms_course_ref !== null) {
            try {
                $result = $this->lms->enroll((int) $employee->getKey(), ['reference' => $training->lms_course_ref, 'title' => $training->title], [
                    'assignment_id' => $assignment->getKey(),
                    'due_date' => $assignment->due_date?->toDateString(),
                ]);

                if (($result['enrollment_ref'] ?? null) !== null) {
                    $assignment->forceFill(['lms_enrollment_ref' => $result['enrollment_ref'], 'status' => 'ENROLLED'])->save();
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->events->emit('LEARNING', 'training.assigned', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => HrTrainingAssignment::class,
            'subject_id' => $assignment->getKey(),
            'title' => 'Didaftarkan pada pelatihan '.$training->title,
            'payload' => ['mandatory' => $assignment->is_mandatory, 'due_date' => $assignment->due_date?->toDateString()],
        ]);

        return $assignment->refresh();
    }

    /**
     * Menutup lingkaran: penyelesaian → asesmen → keterampilan Phase 1 →
     * sertifikat → profil pembelajaran.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function complete(int $assignmentId, array $payload, int $actorUserId): array
    {
        $assignment = HrTrainingAssignment::query()->with('training')->findOrFail($assignmentId);
        $actor = User::query()->findOrFail($actorUserId);
        $employee = HrEmployee::query()->findOrFail($assignment->employee_id);

        $completion = DB::transaction(function () use ($assignment, $payload, $actor, $employee) {
            $training = $assignment->training;

            $score = $payload['score'] ?? null;
            $passed = $score === null ? true : (float) $score >= (float) ($payload['passing_score'] ?? 70);

            $expiresAt = null;

            if ($training?->validity_months !== null && $training->certification_code !== null) {
                $expiresAt = now()->addMonths((int) $training->validity_months)->toDateString();
            }

            $completion = HrTrainingCompletion::query()->updateOrCreate(
                ['assignment_id' => $assignment->getKey()],
                [
                    'code' => sprintf('TC-%s-%05d', now()->format('Y'), HrTrainingCompletion::query()->whereYear('created_at', now()->year)->count() + 1),
                    'employee_id' => $employee->getKey(),
                    'training_id' => $assignment->training_id,
                    'completed_at' => $payload['completed_at'] ?? now(),
                    'score' => $score,
                    'result' => $passed ? 'PASS' : 'FAIL',
                    'assessment_notes' => $payload['notes'] ?? null,
                    'certificate_path' => $payload['certificate_path'] ?? null,
                    'certificate_number' => $payload['certificate_number'] ?? null,
                    'issued_at' => $passed ? now()->toDateString() : null,
                    'expires_at' => $passed ? $expiresAt : null,
                    'skill_updates' => $payload['skills'] ?? $training?->skills,
                    'updated_skills' => false,
                ],
            );

            if (isset($payload['assessment_type'])) {
                HrTrainingAssessment::query()->create([
                    'completion_id' => $completion->getKey(),
                    'assessment_type' => strtoupper((string) $payload['assessment_type']),
                    'score' => (float) ($score ?? 0),
                    'max_score' => (float) ($payload['max_score'] ?? 100),
                    'result' => $passed ? 'PASS' : 'FAIL',
                    'assessor_employee_id' => $payload['assessor_employee_id'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'assessed_at' => now(),
                ]);
            }

            $assignment->forceFill([
                'status' => $passed ? 'COMPLETED' : 'FAILED',
                'completion_id' => $completion->getKey(),
            ])->save();

            $skillUpdates = [];

            if ($passed && (bool) config('hrd_phase2.learning.skill_update_on_completion', true)) {
                $skillUpdates = $this->applySkillUpdates($employee, $completion, $actor);
            }

            if ($passed && $training?->certification_code !== null) {
                $this->issueCertification($employee, $training->certification_code, $completion, $payload, $actor);
            }

            $this->refreshLearningProfile($employee, $actor);

            $this->events->emit('LEARNING', 'training.completed', [
                'actor' => $actor,
                'employee_id' => $employee->getKey(),
                'subject_type' => HrTrainingCompletion::class,
                'subject_id' => $completion->getKey(),
                'title' => 'Pelatihan selesai: '.($training?->title ?? $assignment->training_id),
                'payload' => ['result' => $completion->result, 'skills_updated' => count($skillUpdates), 'hours' => (float) ($training?->duration_hours ?? 0)],
            ]);

            return $completion->refresh();
        });

        return [
            'completion_id' => $completion->getKey(),
            'result' => $completion->result,
            'expires_at' => $completion->expires_at?->toDateString(),
            'skills_updated' => (bool) $completion->updated_skills,
        ];
    }

    public function trainingHours(int $employeeId, string $from, string $to): float
    {
        return round((float) HrTrainingCompletion::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('completed_at', [$from, $to])
            ->where('result', 'PASS')
            ->with('training')
            ->get()
            ->sum(fn (HrTrainingCompletion $completion) => (float) ($completion->training?->duration_hours ?? 0)), 2);
    }

    /**
     * Matriks pelatihan per pegawai (§63).
     *
     * @return list<array<string, mixed>>
     */
    public function trainingMatrix(?int $organizationId = null, ?int $departmentId = null): array
    {
        $employees = HrEmployee::query()
            ->where('lifecycle_state', 'active')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->limit(500)
            ->get();

        return $employees->map(function (HrEmployee $employee) {
            $compliance = $this->trainingCompliance((int) $employee->getKey());

            return [
                'employee_id' => $employee->getKey(),
                'employee_name' => $employee->displayName(),
                'required' => $compliance['required'],
                'completed' => $compliance['completed'],
                'pending' => $compliance['pending'],
                'overdue' => $compliance['overdue'],
                'expired' => $compliance['expired'],
                'compliant' => $compliance['compliant'],
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    public function analytics(?int $organizationId = null): array
    {
        $assignments = HrTrainingAssignment::query()
            ->when($organizationId !== null, fn ($query) => $query->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->get();

        $completions = HrTrainingCompletion::query()
            ->when($organizationId !== null, fn ($query) => $query->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->get();

        $costs = HrTrainingCost::query()
            ->when($organizationId !== null, fn ($query) => $query->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->get();

        return [
            'assignments' => $assignments->count(),
            'completed' => $assignments->where('status', 'COMPLETED')->count(),
            'completion_rate' => $assignments->isEmpty() ? 0.0 : round($assignments->where('status', 'COMPLETED')->count() / $assignments->count() * 100, 2),
            'mandatory_overdue' => $assignments->where('is_mandatory', true)->where('status', 'OVERDUE')->count(),
            'training_hours' => round((float) $completions->sum(fn (HrTrainingCompletion $completion) => (float) ($completion->training?->duration_hours ?? 0)), 2),
            'training_cost' => round((float) $costs->sum('amount'), 2),
            'certifications' => $completions->whereNotNull('certificate_number')->count(),
            'certifications_expiring' => $completions->filter(fn (HrTrainingCompletion $completion) => $completion->expires_at !== null && $completion->expires_at->between(now(), now()->addDays((int) config('hrd_phase2.alerts.certification_expiring_days', 60))))->count(),
            'certifications_expired' => $completions->filter(fn (HrTrainingCompletion $completion) => $completion->expires_at !== null && $completion->expires_at->isPast())->count(),
            'expired' => $completions->filter(fn (HrTrainingCompletion $completion) => $completion->expires_at !== null && $completion->expires_at->isPast())->count(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function recordCost(array $attributes, User $actor): HrTrainingCost
    {
        return HrTrainingCost::query()->create([
            'training_id' => $attributes['training_id'] ?? null,
            'request_id' => $attributes['request_id'] ?? null,
            'assignment_id' => $attributes['assignment_id'] ?? null,
            'employee_id' => $attributes['employee_id'] ?? null,
            'cost_type' => strtoupper((string) ($attributes['cost_type'] ?? 'TRAINING_FEE')),
            'amount' => (float) ($attributes['amount'] ?? 0),
            'currency' => $attributes['currency'] ?? 'IDR',
            'incurred_at' => $attributes['incurred_at'] ?? now()->toDateString(),
            'vendor' => $attributes['vendor'] ?? null,
            'receipt_path' => $attributes['receipt_path'] ?? null,
            'status' => 'APPROVED',
            'department_id' => $attributes['department_id'] ?? null,
            'cost_center' => $attributes['cost_center'] ?? null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function applySkillUpdates(HrEmployee $employee, HrTrainingCompletion $completion, User $actor): array
    {
        $skills = is_array($completion->skill_updates) ? $completion->skill_updates : [];
        $applied = [];

        foreach ($skills as $skill) {
            $code = is_array($skill) ? ($skill['code'] ?? null) : (is_string($skill) ? $skill : null);
            $level = is_array($skill) ? strtoupper((string) ($skill['level'] ?? 'INTERMEDIATE')) : 'INTERMEDIATE';

            if ($code === null) {
                continue;
            }

            $skillModel = HrSkill::query()->where('code', strtoupper((string) $code))->first();

            if ($skillModel === null) {
                continue;
            }

            try {
                $this->skills->setSkill($employee, [
                    'skill_id' => $skillModel->getKey(),
                    'proficiency_level_code' => $level,
                    'source' => 'training',
                    'last_assessed_at' => now(),
                    'notes' => 'Diperbarui dari pelatihan '.$completion->code,
                ], $actor);

                $applied[] = ['skill' => $code, 'level' => $level];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($applied !== []) {
            $completion->forceFill(['updated_skills' => true, 'skill_updates' => $applied])->save();
        }

        return $applied;
    }

    /** @param array<string, mixed> $payload */
    private function issueCertification(HrEmployee $employee, string $certificationCode, HrTrainingCompletion $completion, array $payload, User $actor): void
    {
        try {
            $this->skills->addCertification($employee, [
                'certification_code' => strtoupper($certificationCode),
                'number' => $completion->certificate_number ?? $payload['certificate_number'] ?? null,
                'issued_at' => $completion->issued_at?->toDateString() ?? now()->toDateString(),
                'expires_at' => $completion->expires_at?->toDateString(),
                'document_path' => $completion->certificate_path,
                'source' => 'training',
            ], $actor);
        } catch (Throwable $exception) {
            // Sertifikasi Phase 1 memakai master sertifikasi; bila kode belum
            // terdaftar, pelatihan tetap selesai dan kekurangannya dicatat.
            report($exception);
        }
    }

    private function refreshLearningProfile(HrEmployee $employee, User $actor): HrLearningProfile
    {
        $completions = HrTrainingCompletion::query()->where('employee_id', $employee->getKey())->get();
        $compliance = $this->trainingCompliance((int) $employee->getKey());

        return HrLearningProfile::query()->updateOrCreate(
            ['employee_id' => $employee->getKey()],
            [
                'training_hours_total' => round((float) $completions->sum(fn (HrTrainingCompletion $completion) => (float) ($completion->training?->duration_hours ?? 0)), 2),
                'last_training_at' => $completions->max('completed_at'),
                'mandatory_completed' => $compliance['completed'],
                'mandatory_pending' => $compliance['pending'] + $compliance['overdue'],
                'certifications_active' => $completions->filter(fn (HrTrainingCompletion $completion) => $completion->expires_at === null || $completion->expires_at->isFuture())->count(),
                'certifications_expiring' => $compliance['expired'],
            ],
        );
    }
}
