<?php

namespace App\Modules\Hrd\Recruitment\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Recruitment\Models\HrJobOpening;
use App\Modules\Hrd\Recruitment\Models\HrRecruitmentRequisition;
use Illuminate\Support\Facades\DB;

/**
 * Permintaan tenaga kerja → persetujuan → lowongan kerja.
 *
 * Permintaan boleh datang dari kepala unit (replacement, tambahan formasi,
 * pegawai kontrak, magang) dan disetujui lewat Workflow Engine yang sudah ada.
 * Lowongan tidak pernah dibuat sebelum permintaan disetujui, sehingga jumlah
 * formasi yang dibuka selalu dapat ditelusuri ke satu keputusan tertulis.
 */
class RequisitionService
{
    public function __construct(private readonly HrdPhase2Events $events) {}

    /** @param array<string, mixed> $attributes */
    public function createFromRequest(array $attributes, User $actor): HrRecruitmentRequisition
    {
        $headcount = (int) ($attributes['headcount'] ?? 1);

        if ($headcount < 1) {
            throw HrdValidationException::forField('headcount', 'Jumlah formasi minimal 1.', 'recruitment.headcount_invalid');
        }

        if (empty($attributes['organization_id'])) {
            throw HrdValidationException::forField('organization_id', 'Unit organisasi wajib dipilih.', 'recruitment.organization_required');
        }

        return DB::transaction(function () use ($attributes, $actor, $headcount) {
            $requisition = HrRecruitmentRequisition::query()->create([
                'code' => $this->nextCode(),
                'organization_id' => $attributes['organization_id'],
                'department_id' => $attributes['department_id'] ?? null,
                'position_id' => $attributes['position_id'] ?? null,
                'job_id' => $attributes['job_id'] ?? null,
                'requested_by' => $attributes['requested_by'] ?? $actor->getKey(),
                'replacement_for_employee_id' => $attributes['replacement_for_employee_id'] ?? null,
                'headcount' => $headcount,
                'employment_type' => $attributes['employment_type'] ?? null,
                'worker_type' => $attributes['worker_type'] ?? null,
                'priority' => strtoupper((string) ($attributes['priority'] ?? 'NORMAL')),
                'reason' => $attributes['reason'] ?? null,
                'budget' => $attributes['budget'] ?? null,
                'target_hire_date' => $attributes['target_hire_date'] ?? null,
                'required_education' => $attributes['required_education'] ?? null,
                'required_skills' => $attributes['required_skills'] ?? null,
                'required_certifications' => $attributes['required_certifications'] ?? null,
                'status' => 'DRAFT',
                'notes' => $attributes['notes'] ?? null,
                'meta' => $attributes['meta'] ?? null,
            ]);

            $this->events->emit('RECRUITMENT', 'requisition.created', [
                'actor' => $actor,
                'subject_type' => HrRecruitmentRequisition::class,
                'subject_id' => $requisition->getKey(),
                'title' => 'Permintaan tenaga kerja '.$requisition->code,
                'payload' => ['headcount' => $headcount, 'organization_id' => $requisition->organization_id],
            ]);

            return $requisition;
        });
    }

    public function submit(HrRecruitmentRequisition $requisition, User $actor): HrRecruitmentRequisition
    {
        if (! in_array($requisition->status, ['DRAFT', 'REJECTED', 'ON_HOLD'], true)) {
            throw HrdValidationException::forField('status', 'Permintaan berstatus '.$requisition->status.' tidak dapat diajukan.', 'recruitment.status_invalid');
        }

        $requisition->forceFill([
            'status' => 'SUBMITTED',
            'workflow_instance_id' => $requisition->workflow_instance_id ?? 'req-'.$requisition->getKey(),
        ])->save();

        $this->events->emit('RECRUITMENT', 'requisition.submitted', [
            'actor' => $actor,
            'subject_type' => HrRecruitmentRequisition::class,
            'subject_id' => $requisition->getKey(),
            'title' => 'Permintaan '.$requisition->code.' diajukan',
        ]);

        return $requisition->refresh();
    }

    public function decide(HrRecruitmentRequisition $requisition, User $actor, bool $approved, ?string $notes = null): HrRecruitmentRequisition
    {
        if ($requisition->status !== 'SUBMITTED') {
            throw HrdValidationException::forField('status', 'Hanya permintaan yang diajukan dapat diputuskan.', 'recruitment.status_invalid');
        }

        $requisition->forceFill([
            'status' => $approved ? 'APPROVED' : 'REJECTED',
            'approved_by' => $approved ? $actor->getKey() : null,
            'approved_at' => $approved ? now() : null,
            'notes' => $notes ?? $requisition->notes,
        ])->save();

        // Permintaan yang disetujui membuka lowongan secara otomatis: satu
        // keputusan, satu pekerjaan berikutnya — bukan dua langkah manual.
        if ($approved) {
            $this->publishOpening($requisition->refresh(), [
                'visibility' => 'INTERNAL_AND_EXTERNAL',
                'title' => null,
            ], $actor);
        }

        $this->events->emit('RECRUITMENT', $approved ? 'requisition.approved' : 'requisition.rejected', [
            'actor' => $actor,
            'subject_type' => HrRecruitmentRequisition::class,
            'subject_id' => $requisition->getKey(),
            'title' => 'Permintaan '.$requisition->code.($approved ? ' disetujui' : ' ditolak'),
            'reason' => $notes,
        ]);

        return $requisition->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function publishOpening(HrRecruitmentRequisition $requisition, array $attributes, User $actor): HrJobOpening
    {
        if (! in_array($requisition->status, ['APPROVED', 'OPEN'], true)) {
            throw HrdValidationException::forField('status', 'Lowongan hanya dapat dibuka dari permintaan yang telah disetujui.', 'recruitment.requisition_not_approved');
        }

        $visibility = strtoupper((string) ($attributes['visibility'] ?? 'INTERNAL_AND_EXTERNAL'));

        if (! in_array($visibility, ['INTERNAL', 'EXTERNAL', 'INTERNAL_AND_EXTERNAL'], true)) {
            throw HrdValidationException::forField('visibility', 'Jangkauan lowongan tidak dikenal.', 'recruitment.visibility_invalid');
        }

        return DB::transaction(function () use ($requisition, $attributes, $actor, $visibility) {
            $opening = HrJobOpening::query()->create([
                'code' => $requisition->code.'-O'.$requisition->openings()->count() + 1,
                'requisition_id' => $requisition->getKey(),
                'organization_id' => $requisition->organization_id,
                'position_id' => $requisition->position_id,
                'job_id' => $requisition->job_id,
                'title' => $attributes['title'] ?? ('Lowongan '.$requisition->code),
                'description' => $attributes['description'] ?? null,
                'requirements' => $attributes['requirements'] ?? null,
                'visibility' => $visibility,
                'channels' => $attributes['channels'] ?? ['CAREER_PAGE', 'INTERNAL_JOB_BOARD'],
                'openings_count' => $attributes['openings_count'] ?? $requisition->headcount,
                'status' => 'OPEN',
                'published_at' => now(),
                'closing_at' => $attributes['closing_at'] ?? null,
                'show_salary' => (bool) ($attributes['show_salary'] ?? false),
                'salary_min' => $attributes['salary_min'] ?? null,
                'salary_max' => $attributes['salary_max'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            $requisition->forceFill(['status' => 'OPEN'])->save();

            $this->events->emit('RECRUITMENT', 'opening.published', [
                'actor' => $actor,
                'subject_type' => HrJobOpening::class,
                'subject_id' => $opening->getKey(),
                'title' => 'Lowongan '.$opening->title.' dibuka',
                'payload' => ['visibility' => $visibility, 'positions' => $opening->openings_count],
            ]);

            return $opening;
        });
    }

    public function close(HrJobOpening $opening, User $actor, string $reason = 'CLOSED'): HrJobOpening
    {
        $opening->forceFill([
            'status' => strtoupper($reason) === 'CANCELLED' ? 'CANCELLED' : 'CLOSED',
            'closing_at' => now(),
        ])->save();

        $this->events->emit('RECRUITMENT', 'opening.closed', [
            'actor' => $actor,
            'subject_type' => HrJobOpening::class,
            'subject_id' => $opening->getKey(),
            'title' => 'Lowongan '.$opening->code.' ditutup',
        ]);

        return $opening->refresh();
    }

    private function nextCode(): string
    {
        $sequence = HrRecruitmentRequisition::query()->whereYear('created_at', now()->year)->count() + 1;

        return sprintf('REQ-%s-%04d', now()->format('Y'), $sequence);
    }
}
