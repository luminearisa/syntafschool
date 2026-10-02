<?php

namespace App\Modules\Hrd\Recruitment\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Services\EmployeeService;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use App\Modules\Hrd\Recruitment\Models\HrJobOffer;
use App\Modules\Hrd\Recruitment\Models\HrOfferApproval;
use Illuminate\Support\Facades\DB;

/**
 * Tawaran kerja dan konversi menjadi pegawai.
 *
 * Ini titik temu antara rekrutmen dan Core HR: kandidat yang menerima tawaran
 * menjadi **pegawai sungguhan** lewat EmployeeService Phase 1 — nomor pegawai,
 * penugasan posisi, linimasa, dan jembatan ke tabel `employees` lama semuanya
 * memakai jalur yang sama dengan pegawai lain. Tidak ada pembuatan pegawai
 * kedua di modul rekrutmen.
 */
class OfferService
{
    public function __construct(
        private readonly HrdPhase2Events $events,
        private readonly EmployeeService $employees,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function draft(HrCandidateApplication $application, array $attributes, User $actor): HrJobOffer
    {
        $applications = HrCandidateApplication::query()->find($application->getKey());

        if ($applications === null) {
            throw HrdValidationException::forField('application_id', 'Lamaran tidak ditemukan.', 'recruitment.application_missing');
        }

        $expiryDays = (int) config('hrd_phase2.recruitment.offer_expiry_days', 14);

        return DB::transaction(function () use ($application, $attributes, $actor, $expiryDays) {
            $offer = HrJobOffer::query()->create([
                'code' => sprintf('OFR-%s-%04d', now()->format('Y'), HrJobOffer::query()->whereYear('created_at', now()->year)->count() + 1),
                'application_id' => $application->getKey(),
                'candidate_id' => $application->candidate_id,
                'requisition_id' => $application->requisition_id,
                'position_id' => $attributes['position_id'] ?? $application->opening?->position_id,
                'job_id' => $attributes['job_id'] ?? $application->opening?->job_id,
                'organization_id' => $attributes['organization_id'] ?? $application->opening?->organization_id,
                'employment_type' => $attributes['employment_type'] ?? null,
                'worker_type' => $attributes['worker_type'] ?? null,
                'offered_salary' => $attributes['offered_salary'] ?? null,
                'salary_breakdown' => $attributes['salary_breakdown'] ?? null,
                'start_date' => $attributes['start_date'] ?? now()->addMonth()->startOfMonth()->toDateString(),
                'contract_type_code' => $attributes['contract_type_code'] ?? null,
                'expires_at' => $attributes['expires_at'] ?? now()->addDays($expiryDays),
                'status' => 'DRAFT',
                'created_by' => $actor->getKey(),
            ]);

            $application->forceFill(['offer_status' => 'DRAFT'])->save();

            $this->events->emit('RECRUITMENT', 'offer.drafted', [
                'actor' => $actor,
                'subject_type' => HrJobOffer::class,
                'subject_id' => $offer->getKey(),
                'title' => 'Draf tawaran '.$offer->code,
                'payload' => ['offered_salary' => $offer->offered_salary],
            ]);

            return $offer;
        });
    }

    public function submitForApproval(HrJobOffer $offer, User $actor): HrJobOffer
    {
        $offer->forceFill(['status' => 'PENDING_APPROVAL'])->save();

        HrOfferApproval::query()->create([
            'offer_id' => $offer->getKey(),
            'level' => 1,
            'decision' => 'PENDING',
        ]);

        $this->events->emit('RECRUITMENT', 'offer.submitted', [
            'actor' => $actor,
            'subject_type' => HrJobOffer::class,
            'subject_id' => $offer->getKey(),
            'title' => 'Tawaran '.$offer->code.' menunggu persetujuan',
        ]);

        return $offer->refresh();
    }

    public function approve(HrJobOffer $offer, User $actor, ?string $notes = null): HrJobOffer
    {
        if ($offer->status !== 'PENDING_APPROVAL') {
            throw HrdValidationException::forField('status', 'Hanya tawaran yang diajukan dapat disetujui.', 'recruitment.offer_status_invalid');
        }

        return DB::transaction(function () use ($offer, $actor, $notes) {
            $approval = HrOfferApproval::query()->where('offer_id', $offer->getKey())->where('decision', 'PENDING')->orderBy('level')->first();

            $approval?->forceFill([
                'approver_user_id' => $actor->getKey(),
                'decision' => 'APPROVED',
                'decided_at' => now(),
                'notes' => $notes,
            ])->save();

            $offer->forceFill([
                'status' => 'APPROVED',
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->events->emit('RECRUITMENT', 'offer.approved', [
                'actor' => $actor,
                'subject_type' => HrJobOffer::class,
                'subject_id' => $offer->getKey(),
                'title' => 'Tawaran '.$offer->code.' disetujui',
            ]);

            return $offer->refresh();
        });
    }

    public function send(HrJobOffer $offer, User $actor): HrJobOffer
    {
        if ($offer->status !== 'APPROVED') {
            throw HrdValidationException::forField('status', 'Tawaran harus disetujui sebelum dikirim.', 'recruitment.offer_not_approved');
        }

        $offer->forceFill(['status' => 'SENT', 'sent_at' => now()])->save();

        $offer->application?->forceFill(['offer_status' => 'SENT'])->save();

        $this->events->emit('RECRUITMENT', 'offer.sent', [
            'actor' => $actor,
            'subject_type' => HrJobOffer::class,
            'subject_id' => $offer->getKey(),
            'title' => 'Tawaran '.$offer->code.' dikirim ke kandidat',
        ]);

        return $offer->refresh();
    }

    public function respond(HrJobOffer $offer, User $actor, bool $accepted, ?string $reason = null): HrJobOffer
    {
        if (! in_array($offer->status, ['SENT', 'APPROVED'], true)) {
            throw HrdValidationException::forField('status', 'Tawaran belum dikirim.', 'recruitment.offer_not_sent');
        }

        return DB::transaction(function () use ($offer, $actor, $accepted, $reason) {
            $offer->forceFill([
                'status' => $accepted ? 'ACCEPTED' : 'DECLINED',
                'responded_at' => now(),
                'decline_reason' => $accepted ? null : $reason,
            ])->save();

            $application = $offer->application;
            $application?->forceFill(['offer_status' => $offer->status])->save();

            $this->events->emit('RECRUITMENT', $accepted ? 'offer.accepted' : 'offer.declined', [
                'actor' => $actor,
                'subject_type' => HrJobOffer::class,
                'subject_id' => $offer->getKey(),
                'title' => 'Tawaran '.$offer->code.($accepted ? ' diterima' : ' ditolak'),
                'reason' => $reason,
            ]);

            if ($accepted) {
                $this->convertToEmployee($offer->refresh(), $actor);
            }

            return $offer->refresh();
        });
    }

    /**
     * Kandidat → Person → Employee (Cross-module flow §101).
     *
     * Konversi ini idempoten: tawaran yang sudah pernah dikonversi memakai
     * pegawai yang sama, sehingga klik ganda tidak mencetak dua nomor pegawai.
     */
    public function convertToEmployee(HrJobOffer $offer, User $actor): array
    {
        $application = $offer->application;

        if ($application === null) {
            throw HrdValidationException::forField('application_id', 'Lamaran untuk tawaran ini tidak ditemukan.', 'recruitment.application_missing');
        }

        if ($application->hired_employee_id !== null) {
            return ['employee_id' => $application->hired_employee_id, 'already_hired' => true];
        }

        $candidate = $offer->candidate;

        if ($candidate === null) {
            throw HrdValidationException::forField('candidate_id', 'Profil kandidat tidak ditemukan.', 'recruitment.candidate_missing');
        }

        $employee = $this->employees->create([
            'organization_id' => $offer->organization_id ?? $application->opening?->organization_id,
            'department_id' => $application->opening?->position?->department_id,
            'position_id' => $offer->position_id,
            'worker_type' => $offer->worker_type ?? 'Teacher',
            'employment_type' => $offer->employment_type ?? 'Permanent',
            'employment_status' => 'ACTIVE',
            'lifecycle_state' => 'active',
            'hire_date' => $offer->start_date?->toDateString() ?? now()->toDateString(),
            'person' => [
                'full_name' => $candidate->full_name,
                'gender' => $candidate->gender,
                'birth_date' => $candidate->birth_date?->toDateString(),
                'mobile' => $candidate->phone,
                'personal_email' => $candidate->email,
                'work_email' => $candidate->email,
                'address' => $candidate->address,
                'city' => $candidate->city,
            ],
            'assignment' => [
                'position_id' => $offer->position_id,
                'organization_id' => $offer->organization_id,
                'start_date' => $offer->start_date?->toDateString() ?? now()->toDateString(),
                'source' => 'recruitment',
            ],
            'meta' => [
                'recruitment_application_id' => $application->getKey(),
                'offer_code' => $offer->code,
                'candidate_code' => $candidate->code,
            ],
        ], $actor);

        $application->forceFill([
            'status' => 'HIRED',
            'stage_code' => 'HIRED',
            'stage_entered_at' => now(),
            'hired_employee_id' => $employee->getKey(),
            'converted_at' => now(),
            'time_to_hire_days' => $application->applied_at !== null ? (int) $application->applied_at->diffInDays(now()) : null,
        ])->save();

        $opening = $application->opening;

        if ($opening !== null) {
            $opening->forceFill(['filled_count' => min((int) $opening->openings_count, (int) $opening->filled_count + 1)])->save();

            if ((int) $opening->filled_count >= (int) $opening->openings_count) {
                $opening->forceFill(['status' => 'CLOSED', 'closing_at' => now()])->save();
            }
        }

        if ($candidate->person_id === null && $employee->person_id !== null) {
            $candidate->forceFill(['person_id' => $employee->person_id])->save();
        }

        $this->events->emit('RECRUITMENT', 'candidate.hired', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => HrJobOffer::class,
            'subject_id' => $offer->getKey(),
            'title' => 'Kandidat '.$candidate->full_name.' menjadi pegawai '.$employee->employee_number,
            'payload' => [
                'employee_number' => $employee->employee_number,
                'position_id' => $offer->position_id,
                'onboarding_required' => true,
            ],
        ]);

        return ['employee_id' => $employee->getKey(), 'employee_number' => $employee->employee_number, 'already_hired' => false];
    }
}
