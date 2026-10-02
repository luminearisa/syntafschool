<?php

namespace App\Modules\Hrd\Recruitment\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrPerson;
use App\Modules\Hrd\Recruitment\Models\HrApplicationStageHistory;
use App\Modules\Hrd\Recruitment\Models\HrAssessmentDefinition;
use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use App\Modules\Hrd\Recruitment\Models\HrCandidateAssessment;
use App\Modules\Hrd\Recruitment\Models\HrCandidateProfile;
use App\Modules\Hrd\Recruitment\Models\HrCandidateScreening;
use App\Modules\Hrd\Recruitment\Models\HrInterviewInterviewer;
use App\Modules\Hrd\Recruitment\Models\HrInterviewSchedule;
use App\Modules\Hrd\Recruitment\Models\HrInterviewScorecard;
use App\Modules\Hrd\Recruitment\Models\HrPipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * Kandidat dan corong rekrutmen.
 *
 * Kandidat **bukan** pegawai: profilnya terpisah dari `hr_employees` dan hanya
 * menjadi pegawai lewat konversi resmi di OfferService. Walaupun begitu ia
 * ditautkan ke `hr_people` bila memungkinkan, sehingga seseorang yang pernah
 * melamar dan kemudian diangkat tidak pernah tercatat dua kali.
 */
class CandidatePipelineService
{
    /** @param array<string, mixed> $attributes */
    public function register(array $attributes, User $actor): HrCandidateProfile
    {
        $email = strtolower(trim((string) ($attributes['email'] ?? '')));

        if ($email === '') {
            throw HrdValidationException::forField('email', 'Surel kandidat wajib diisi.', 'recruitment.email_required');
        }

        // Deduplikasi: kandidat yang pernah melamar tidak digandakan.
        $existing = HrCandidateProfile::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing !== null) {
            $existing->forceFill(array_filter([
                'full_name' => $attributes['full_name'] ?? $existing->full_name,
                'phone' => $attributes['phone'] ?? $existing->phone,
                'skills' => $attributes['skills'] ?? $existing->skills,
                'resume_path' => $attributes['resume_path'] ?? $existing->resume_path,
            ], fn ($value) => $value !== null))->save();

            return $existing->refresh();
        }

        $person = HrPerson::query()->whereRaw('lower(work_email) = ?', [$email])->orWhereRaw('lower(personal_email) = ?', [$email])->first();

        return HrCandidateProfile::query()->create([
            'code' => $this->nextCandidateCode(),
            'person_id' => $person?->getKey(),
            'full_name' => $attributes['full_name'] ?? 'Tanpa Nama',
            'email' => $email,
            'phone' => $attributes['phone'] ?? null,
            'gender' => $attributes['gender'] ?? null,
            'birth_date' => $attributes['birth_date'] ?? null,
            'address' => $attributes['address'] ?? null,
            'city' => $attributes['city'] ?? null,
            'country' => $attributes['country'] ?? 'Indonesia',
            'education_level' => $attributes['education_level'] ?? null,
            'total_experience_years' => $attributes['total_experience_years'] ?? null,
            'current_employer' => $attributes['current_employer'] ?? null,
            'current_position' => $attributes['current_position'] ?? null,
            'expected_salary' => $attributes['expected_salary'] ?? null,
            'skills' => $attributes['skills'] ?? null,
            'certifications' => $attributes['certifications'] ?? null,
            'languages' => $attributes['languages'] ?? null,
            'resume_path' => $attributes['resume_path'] ?? null,
            'portfolio_url' => $attributes['portfolio_url'] ?? null,
            'linkedin_url' => $attributes['linkedin_url'] ?? null,
            'source_code' => $attributes['source_code'] ?? 'OTHER',
            'source_detail' => $attributes['source_detail'] ?? null,
            'status' => 'ACTIVE',
            'created_by' => $actor->getKey(),
        ]);
    }

    /**
     * Melamar lowongan. Pegawai internal memakai baris yang sama dengan
     * `internal_employee_id` terisi — tidak ada profil kandidat kedua.
     *
     * @param array<string, mixed> $attributes
     */
    public function apply(HrCandidateProfile $candidate, int $openingId, User $actor, array $attributes = []): HrCandidateApplication
    {
        $opening = HrJobOpeningFinder::find($openingId);

        if ($opening === null) {
            throw HrdValidationException::forField('opening_id', 'Lowongan tidak ditemukan.', 'recruitment.opening_missing');
        }

        if (strtoupper((string) $opening->status) !== 'OPEN') {
            throw HrdValidationException::forField('opening_id', 'Lowongan sudah ditutup.', 'recruitment.opening_closed');
        }

        $duplicate = HrCandidateApplication::query()
            ->where('candidate_id', $candidate->getKey())
            ->where('opening_id', $opening->getKey())
            ->first();

        if ($duplicate !== null) {
            return $duplicate;
        }

        if ((int) $opening->filled_count >= (int) $opening->openings_count) {
            throw HrdValidationException::forField('opening_id', 'Formasi lowongan ini sudah terisi penuh.', 'recruitment.opening_full');
        }

        return DB::transaction(function () use ($candidate, $opening, $attributes, $actor) {
            $stage = $this->firstStage();

            $application = HrCandidateApplication::query()->create([
                'code' => $this->nextApplicationCode(),
                'candidate_id' => $candidate->getKey(),
                'opening_id' => $opening->getKey(),
                'requisition_id' => $opening->requisition_id,
                'source_code' => $attributes['source_code'] ?? $candidate->source_code,
                'stage_code' => $stage,
                'status' => 'ACTIVE',
                'applied_at' => now(),
                'stage_entered_at' => now(),
                'internal_employee_id' => $attributes['internal_employee_id'] ?? null,
                'created_by' => $actor->getKey(),
                'meta' => $attributes['meta'] ?? null,
            ]);

            $this->recordStage($application, null, $stage, $actor, 'Lamaran diterima');

            return $application;
        });
    }

    public function moveStage(HrCandidateApplication $application, string $stageCode, User $actor, ?string $notes = null): HrCandidateApplication
    {
        $stageCode = strtoupper($stageCode);
        $stage = HrPipelineStage::query()->where('code', $stageCode)->first();

        if ($stage === null) {
            throw HrdValidationException::forField('stage_code', "Tahap \"{$stageCode}\" tidak ada pada konfigurasi corong.", 'recruitment.stage_unknown');
        }

        if (in_array($application->status, ['HIRED', 'REJECTED', 'WITHDRAWN'], true) && strtoupper((string) $stageCode) !== 'HIRED') {
            throw HrdValidationException::forField('status', 'Lamaran sudah final dan tidak dapat dipindah tahap.', 'recruitment.application_final');
        }

        return DB::transaction(function () use ($application, $stage, $stageCode, $actor, $notes) {
            $from = $application->stage_code;

            $application->forceFill([
                'stage_code' => $stageCode,
                'stage_entered_at' => now(),
                'status' => match ($stageCode) {
                    'HIRED' => 'HIRED',
                    'REJECTED' => 'REJECTED',
                    'WITHDRAWN' => 'WITHDRAWN',
                    default => 'ACTIVE',
                },
            ])->save();

            if (in_array($stageCode, ['REJECTED', 'WITHDRAWN'], true) && $notes !== null) {
                $application->forceFill([
                    $stageCode === 'REJECTED' ? 'rejection_reason' : 'withdrawal_reason' => $notes,
                ])->save();
            }

            $this->recordStage($application->refresh(), $from, $stageCode, $actor, $notes);

            return $application->refresh();
        });
    }

    /**
     * Screening terstruktur dengan kriteria dan alasan tersimpan (§12).
     *
     * @param array<string, mixed> $criteria
     */
    public function screen(HrCandidateApplication $application, array $criteria, string $result, User $actor, ?string $notes = null): HrCandidateScreening
    {
        $result = strtoupper($result);

        if (! in_array($result, ['PASS', 'REVIEW', 'REJECT'], true)) {
            throw HrdValidationException::forField('result', 'Hasil screening harus PASS, REVIEW, atau REJECT.', 'recruitment.screening_result_invalid');
        }

        $score = $this->scoreCriteria($criteria);

        $screening = HrCandidateScreening::query()->create([
            'application_id' => $application->getKey(),
            'criteria' => $criteria,
            'result' => $result,
            'score' => $score,
            'reasons' => $criteria['reasons'] ?? null,
            'screened_by' => $actor->getKey(),
            'screened_at' => now(),
            'notes' => $notes,
        ]);

        $application->forceFill(['screening_score' => $score])->save();

        if ($result === 'PASS') {
            $this->moveStage($application->refresh(), 'SHORTLISTED', $actor, 'Lolos screening');
        } elseif ($result === 'REJECT') {
            $this->moveStage($application->refresh(), 'REJECTED', $actor, $notes ?? 'Tidak lolos screening');
        }

        return $screening;
    }

    /**
     * @param list<int> $interviewerUserIds
     * @param array<string, mixed> $attributes
     */
    public function scheduleInterview(HrCandidateApplication $application, array $interviewerUserIds, array $attributes, User $actor): HrInterviewSchedule
    {
        if ($interviewerUserIds === []) {
            throw HrdValidationException::forField('interviewers', 'Minimal satu pewawancara harus ditugaskan.', 'recruitment.interviewer_required');
        }

        return DB::transaction(function () use ($application, $interviewerUserIds, $attributes, $actor) {
            $schedule = HrInterviewSchedule::query()->create([
                'application_id' => $application->getKey(),
                'round_id' => $attributes['round_id'] ?? null,
                'stage_code' => $attributes['stage_code'] ?? 'INTERVIEW',
                'scheduled_at' => $attributes['scheduled_at'] ?? now()->addDay(),
                'duration_minutes' => (int) ($attributes['duration_minutes'] ?? 60),
                'interview_type' => strtoupper((string) ($attributes['interview_type'] ?? 'HR')),
                'mode' => strtoupper((string) ($attributes['mode'] ?? 'ONSITE')),
                'location' => $attributes['location'] ?? null,
                'meeting_link' => $attributes['meeting_link'] ?? null,
                'timezone' => $attributes['timezone'] ?? config('app.timezone'),
                'status' => 'SCHEDULED',
                'created_by' => $actor->getKey(),
            ]);

            foreach (array_values(array_unique($interviewerUserIds)) as $index => $userId) {
                HrInterviewInterviewer::query()->create([
                    'schedule_id' => $schedule->getKey(),
                    'interviewer_user_id' => $userId,
                    'role' => $index === 0 ? 'LEAD' : 'PANEL',
                    'is_lead' => $index === 0,
                    'status' => 'INVITED',
                ]);
            }

            return $schedule;
        });
    }

    /**
     * Scorecard pewawancara. Bila kebijakan menutup penilaian, seorang
     * pewawancara tidak melihat skor pewawancara lain sebelum ia sendiri
     * mengirimkan penilaiannya (§14).
     *
     * @param array<string, mixed> $attributes
     */
    public function submitScorecard(HrInterviewSchedule $schedule, User $interviewer, array $attributes): HrInterviewScorecard
    {
        $assigned = HrInterviewInterviewer::query()
            ->where('schedule_id', $schedule->getKey())
            ->where('interviewer_user_id', $interviewer->getKey())
            ->first();

        if ($assigned === null) {
            throw HrdValidationException::forField('interviewer', 'Anda tidak ditugaskan pada wawancara ini.', 'recruitment.interviewer_not_assigned');
        }

        return DB::transaction(function () use ($schedule, $interviewer, $assigned, $attributes) {
            $scores = $attributes['scores'] ?? [];
            $weighted = 0.0;
            $weightTotal = 0.0;

            foreach ($scores as $score) {
                $weighted += (float) ($score['score'] ?? 0) * (float) ($score['weight'] ?? 1);
                $weightTotal += (float) ($score['weight'] ?? 1);
            }

            $overall = $weightTotal > 0 ? round($weighted / $weightTotal, 2) : ($attributes['overall_score'] ?? null);

            $scorecard = HrInterviewScorecard::query()->updateOrCreate(
                ['schedule_id' => $schedule->getKey(), 'interviewer_user_id' => $interviewer->getKey()],
                [
                    'application_id' => $schedule->application_id,
                    'template_id' => $attributes['template_id'] ?? null,
                    'overall_score' => $overall,
                    'recommendation' => $attributes['recommendation'] ?? null,
                    'strengths' => $attributes['strengths'] ?? null,
                    'concerns' => $attributes['concerns'] ?? null,
                    'notes' => $attributes['notes'] ?? null,
                    'status' => 'SUBMITTED',
                    'submitted_at' => now(),
                ],
            );

            $scorecard->scores()->delete();

            foreach ($scores as $score) {
                $scorecard->scores()->create([
                    'item_label' => $score['item_label'] ?? 'Umum',
                    'weight' => $score['weight'] ?? 1,
                    'score' => $score['score'] ?? null,
                    'comment' => $score['comment'] ?? null,
                ]);
            }

            $assigned->forceFill(['status' => 'COMPLETED', 'submitted_at' => now()])->save();

            $this->recalculateInterviewScore($schedule->application_id);

            return $scorecard->refresh();
        });
    }

    /** @return list<array<string, mixed>> */
    public function scorecardsFor(HrInterviewSchedule $schedule, User $viewer): array
    {
        $blind = (bool) config('hrd_phase2.recruitment.blind_scorecards', true);
        $mine = $schedule->scorecards()->where('interviewer_user_id', $viewer->getKey())->exists();

        $query = $schedule->scorecards()->with('scores');

        if ($blind && ! $mine && ! $viewer->can('recruitment.scorecard.view_all')) {
            return [];
        }

        return $query->get()->map(fn (HrInterviewScorecard $card) => $card->toArray())->all();
    }

    /** @param array<string, mixed> $attributes */
    public function recordAssessment(HrCandidateApplication $application, string $definitionCode, array $attributes, User $actor): HrCandidateAssessment
    {
        $definition = HrAssessmentDefinition::query()->where('code', strtoupper($definitionCode))->first();

        if ($definition === null) {
            throw HrdValidationException::forField('assessment', "Jenis asesmen \"{$definitionCode}\" tidak dikenal.", 'recruitment.assessment_unknown');
        }

        $score = $attributes['score'] ?? null;
        $result = $attributes['result'] ?? ($score === null ? 'PENDING' : ((float) $score >= (float) $definition->passing_score ? 'PASS' : 'FAIL'));

        $assessment = HrCandidateAssessment::query()->create([
            'assessment_id' => $definition->getKey(),
            'application_id' => $application->getKey(),
            'scheduled_at' => $attributes['scheduled_at'] ?? null,
            'completed_at' => $attributes['completed_at'] ?? ($score !== null ? now() : null),
            'score' => $score,
            'result' => $result,
            'reviewer_user_id' => $attributes['reviewer_user_id'] ?? $actor->getKey(),
            'criteria_scores' => $attributes['criteria_scores'] ?? null,
            'evidence_path' => $attributes['evidence_path'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'status' => $score !== null ? 'COMPLETED' : 'PENDING',
        ]);

        $application->forceFill(['assessment_score' => $score ?? $application->assessment_score])->save();

        return $assessment;
    }

    /** @return array<string, mixed> */
    public function profile(HrCandidateApplication $application): array
    {
        $application->loadMissing(['candidate', 'opening', 'histories', 'screenings', 'interviews.interviewers', 'assessments', 'offer']);

        return [
            'application' => $application->only(['code', 'stage_code', 'status', 'applied_at', 'internal_employee_id']),
            'candidate' => $application->candidate?->toArray(),
            'opening' => $application->opening?->only(['code', 'title', 'visibility', 'status']),
            'timeline' => $application->histories->map(fn ($history) => [
                'from' => $history->from_stage,
                'to' => $history->to_stage,
                'at' => $history->moved_at?->toDateTimeString(),
                'notes' => $history->notes,
            ])->all(),
            'screenings' => $application->screenings->map->only(['result', 'score', 'screened_at'])->all(),
            'interviews' => $application->interviews->map(fn ($interview) => [
                'scheduled_at' => $interview->scheduled_at?->toDateTimeString(),
                'mode' => $interview->mode,
                'status' => $interview->status,
                'interviewers' => $interview->interviewers->pluck('interviewer_user_id')->all(),
                'scorecards_submitted' => $interview->scorecards()->where('status', 'SUBMITTED')->count(),
            ])->all(),
            'assessments' => $application->assessments->map->only(['score', 'result', 'status'])->all(),
            'offer' => $application->offer?->only(['code', 'status', 'offered_salary', 'start_date']),
        ];
    }

    private function recordStage(HrCandidateApplication $application, ?string $from, string $to, User $actor, ?string $notes): void
    {
        HrApplicationStageHistory::query()->create([
            'application_id' => $application->getKey(),
            'from_stage' => $from,
            'to_stage' => $to,
            'actor_id' => $actor->getKey(),
            'notes' => $notes,
            'moved_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $criteria */
    private function scoreCriteria(array $criteria): ?float
    {
        $required = $criteria['required'] ?? [];
        $met = $criteria['met'] ?? [];

        if ($required === [] || $met === []) {
            return isset($criteria['score']) ? (float) $criteria['score'] : null;
        }

        $matched = count(array_intersect(array_map('strtoupper', $required), array_map('strtoupper', $met)));

        return round($matched / max(count($required), 1) * 100, 2);
    }

    private function recalculateInterviewScore(int $applicationId): void
    {
        $application = HrCandidateApplication::query()->find($applicationId);

        if ($application === null) {
            return;
        }

        $average = HrInterviewScorecard::query()
            ->where('application_id', $applicationId)
            ->where('status', 'SUBMITTED')
            ->whereNotNull('overall_score')
            ->avg('overall_score');

        if ($average !== null) {
            $application->forceFill(['interview_score' => round((float) $average, 2)])->save();
        }
    }

    private function firstStage(): string
    {
        $stage = HrPipelineStage::query()->where('is_active', true)->orderBy('sequence')->first();

        return $stage?->code ?? (string) (config('hrd_phase2.recruitment.pipeline')[0] ?? 'APPLIED');
    }

    private function nextCandidateCode(): string
    {
        return sprintf('CAND-%s-%04d', now()->format('Y'), HrCandidateProfile::query()->whereYear('created_at', now()->year)->count() + 1);
    }

    private function nextApplicationCode(): string
    {
        return sprintf('APP-%s-%04d', now()->format('Y'), HrCandidateApplication::query()->whereYear('created_at', now()->year)->count() + 1);
    }
}
