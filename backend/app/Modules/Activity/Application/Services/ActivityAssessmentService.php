<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\AssessmentStatus;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\CompletionStatus;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Enums\ProgressStatus;
use App\Modules\Activity\Domain\Enums\RubricLevel;
use App\Modules\Activity\Domain\Enums\SkillEvidenceSource;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityAssessment;
use App\Modules\Activity\Models\ActivityAssessmentResult;
use App\Modules\Activity\Models\ActivityCompletion;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgress;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRubric;
use App\Modules\Activity\Models\ActivityRubricItem;
use App\Modules\Activity\Models\ActivitySkillEvidence;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian aktivitas, progres, dan penyelesaian (§36‗41, §105).
 *
 * Penilaian di sini menilai **perkembangan**: keterampilan, performa,
 * partisipasi, proyek, perilaku — dengan rubrik yang dapat disusun sekolah.
 * Nilai akademik resmi tetap milik SIAKAD; yang dikirim ke sana hanyalah usulan
 * pengakuan (lihat `LocalAcademicProvider`).
 *
 * Penyelesaian dinilai dari **bukti**: kehadiran, jumlah penilaian, nilai
 * akhir, proyek, dan persetujuan pembina — semuanya berasal dari aturan yang
 * tersimpan di program, dan setiap keputusan menyimpan salinan aturan itu.
 */
class ActivityAssessmentService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityAccessService $access,
        private readonly ActivityNotificationService $notifications,
        private readonly ActivityAchievementService $achievements,
        private readonly ActivityAttendanceService $attendance,
        private readonly ActivityIntegrationService $integrations,
    ) {}

    // -------------------------------------------------------------- rubrik

    public function createRubric(ActivityProgram $program, array $attributes, User $actor): ActivityRubric
    {
        $this->assertManage($program, $actor);

        return DB::transaction(function () use ($program, $attributes, $actor) {
            $rubric = ActivityRubric::query()->create([
                'program_id' => $program->id,
                'title' => $attributes['title'],
                'type' => $attributes['type'] ?? 'SKILL',
                'scale' => $attributes['scale'] ?? array_map(fn (RubricLevel $level) => [
                    'value' => $level->value,
                    'label' => $level->label(),
                ], RubricLevel::cases()),
                'description' => $attributes['description'] ?? null,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);

            foreach ($attributes['items'] ?? [] as $index => $item) {
                ActivityRubricItem::query()->create([
                    'rubric_id' => $rubric->id,
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'weight' => $item['weight'] ?? 1,
                    'max_score' => $item['max_score'] ?? 4,
                    'sort_order' => $item['sort_order'] ?? $index,
                ]);
            }

            return $rubric->load('items');
        });
    }

    // ----------------------------------------------------------- penilaian

    public function createAssessment(ActivityProgram $program, array $attributes, User $actor): ActivityAssessment
    {
        $this->assertManage($program, $actor);

        return ActivityAssessment::query()->create([
            'program_id' => $program->id,
            'period_id' => $attributes['period_id'] ?? $program->period_id,
            'session_id' => $attributes['session_id'] ?? null,
            'rubric_id' => $attributes['rubric_id'] ?? null,
            'title' => $attributes['title'],
            'type' => $attributes['type'] ?? 'SKILL',
            'description' => $attributes['description'] ?? null,
            'max_score' => $attributes['max_score'] ?? 100,
            'weight' => $attributes['weight'] ?? 1,
            'is_final' => (bool) ($attributes['is_final'] ?? false),
            'counts_toward_completion' => (bool) ($attributes['counts_toward_completion'] ?? true),
            'scheduled_date' => $attributes['scheduled_date'] ?? null,
            'due_date' => $attributes['due_date'] ?? null,
            'status' => AssessmentStatus::DRAFT->value,
            'created_by' => $actor->id,
        ]);
    }

    public function publishAssessment(ActivityAssessment $assessment, User $actor): ActivityAssessment
    {
        $program = $assessment->program;

        if ($program !== null) {
            $this->assertManage($program, $actor);
        }

        $assessment->forceFill([
            'status' => AssessmentStatus::PUBLISHED->value,
            'published_at' => now(),
        ])->save();

        $this->audit->record(AuditAction::ASSESSMENT_PUBLISHED, [
            'subject' => $assessment,
            'program_id' => $assessment->program_id,
            'actor' => $actor->id,
        ]);

        return $assessment->refresh();
    }

    /**
     * Kirim nilai satu siswa (idempoten, boleh dikoreksi sebelum dipublikasikan).
     *
     * @param  array{score?: float|null, level?: string|null, criteria_scores?: array|null, feedback?: string|null, idempotency_key?: string|null}  $payload
     */
    public function submitResult(ActivityAssessment $assessment, int $studentId, array $payload, User $actor): ActivityAssessmentResult
    {
        $program = $assessment->program;

        if ($program === null) {
            throw ActivityValidationException::withErrors(['Penilaian tidak memiliki program.']);
        }

        if (! $this->access->canAssessProgram($actor, $program)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang menilai program ini.');
        }

        $membership = $this->access->membershipOf($studentId, (int) $program->id);

        if ($membership === null) {
            throw ActivityValidationException::withErrors(['Siswa ini bukan anggota aktif program tersebut.']);
        }

        return DB::transaction(function () use ($assessment, $studentId, $payload, $actor, $membership, $program) {
            $result = ActivityAssessmentResult::query()->updateOrCreate(
                ['assessment_id' => $assessment->id, 'student_id' => $studentId],
                [
                    'membership_id' => $membership->id,
                    'score' => $payload['score'] ?? null,
                    'level' => $payload['level'] ?? $this->levelFor($payload['score'] ?? null, (float) $assessment->max_score),
                    'criteria_scores' => $payload['criteria_scores'] ?? null,
                    'feedback' => $payload['feedback'] ?? null,
                    'assessed_by' => $actor->id,
                    'assessed_at' => now(),
                    'idempotency_key' => $payload['idempotency_key'] ?? null,
                ],
            );

            $this->audit->record(AuditAction::ASSESSMENT_SUBMITTED, [
                'subject' => $result,
                'program_id' => (int) $program->id,
                'student_id' => $studentId,
                'after' => $result,
                'actor' => $actor->id,
            ]);

            // Bukti keterampilan: nilai rubrik menjadi bukti capaian keterampilan program.
            $this->recordSkillEvidence($program, $membership, $result, $actor);

            $this->recalculateProgress((int) $membership->id);

            return $result->refresh();
        });
    }

    /** @param list<array{student_id: int, score?: float|null, level?: string|null, feedback?: string|null, criteria_scores?: array|null}> $rows */
    public function submitResults(ActivityAssessment $assessment, array $rows, User $actor, ?string $idempotencyKey = null): array
    {
        $submitted = 0;
        $failed = [];

        foreach ($rows as $row) {
            try {
                $this->submitResult($assessment, (int) $row['student_id'], array_merge($row, [
                    'idempotency_key' => $idempotencyKey === null ? null : $idempotencyKey.'|'.$row['student_id'],
                ]), $actor);
                $submitted++;
            } catch (ActivityValidationException|ActivityConflictException $exception) {
                $failed[] = ['student_id' => $row['student_id'] ?? null, 'message' => $exception->getMessage()];
            }
        }

        return ['submitted' => $submitted, 'failed' => $failed];
    }

    /** @return list<array> hasil satu penilaian */
    public function results(ActivityAssessment $assessment): array
    {
        return $assessment->results()
            ->with(['student:id,full_name,nis', 'assessor:id,name'])
            ->get()
            ->map(fn (ActivityAssessmentResult $result) => [
                'student_id' => (int) $result->student_id,
                'student' => $result->student?->full_name,
                'score' => $result->score === null ? null : (float) $result->score,
                'level' => $result->level?->value,
                'level_label' => $result->level?->label(),
                'feedback' => $result->feedback,
                'assessed_by' => $result->assessor?->name,
                'assessed_at' => $result->assessed_at?->toIso8601String(),
            ])
            ->all();
    }

    // -------------------------------------------------- progres & penyelesaian

    /** Hitung ulang progres satu keanggotaan (kehadiran + penilaian + keterampilan). */
    public function recalculateProgress(int $membershipId): ActivityProgress
    {
        $membership = ActivityMembership::query()->with('program')->findOrFail($membershipId);
        $program = $membership->program;

        $this->attendance->recalculateStudentProgress((int) $membership->student_id, (int) $membership->program_id);

        $results = ActivityAssessmentResult::query()
            ->where('membership_id', $membership->id)
            ->whereHas('assessment', fn ($query) => $query->where('counts_toward_completion', true))
            ->get();

        $average = $results->whereNotNull('score')->avg('score');

        $progress = ActivityProgress::query()->firstOrCreate(
            ['membership_id' => $membership->id],
            [
                'student_id' => (int) $membership->student_id,
                'program_id' => (int) $membership->program_id,
                'period_id' => $membership->period_id,
                'status' => ProgressStatus::ACTIVE->value,
            ],
        );

        $totalAssessments = $program === null
            ? 0
            : $program->assessments()->where('counts_toward_completion', true)->where('status', AssessmentStatus::PUBLISHED->value)->count();

        $skills = ActivitySkillEvidence::query()
            ->with('skill:id,name')
            ->where('membership_id', $membership->id)
            ->get()
            ->groupBy(fn (ActivitySkillEvidence $evidence) => $evidence->skill?->name ?? 'lainnya')
            ->map(fn ($group) => [
                'level' => $group->sortByDesc('score')->first()?->level?->value,
                'score' => $group->max('score'),
                'evidence_count' => $group->count(),
            ])
            ->all();

        $progress->forceFill([
            'assessments_total' => $totalAssessments,
            'assessments_completed' => $results->whereNotNull('score')->count(),
            'average_score' => $average === null ? null : round((float) $average, 2),
            'skills' => $skills,
            'status' => $this->progressStatus($progress, $results->whereNotNull('score')->count(), $totalAssessments),
            'calculated_at' => now(),
        ])->save();

        return $progress->refresh();
    }

    /**
     * Evaluasi penyelesaian aktivitas (§41, §75, §105).
     *
     * @return array{eligible: bool, blockers: list<string>, snapshot: array}
     */
    public function evaluateCompletion(ActivityMembership $membership): array
    {
        $program = $membership->program;
        $progress = $this->recalculateProgress((int) $membership->id);

        if ($program === null) {
            return ['eligible' => false, 'blockers' => ['Program keanggotaan tidak ditemukan.'], 'snapshot' => []];
        }

        $rules = array_merge([
            'minimum_attendance_percentage' => $program->minimum_attendance_percentage,
            'final_assessment_min_score' => null,
            'required_assessments' => null,
            'required_sessions' => null,
            'require_project_completed' => false,
            'require_coach_approval' => false,
        ], (array) ($program->completion_rules ?? []));

        $blockers = [];

        $minimumAttendance = $rules['minimum_attendance_percentage'];
        $attendance = (float) ($progress->attendance_percentage ?? 0);

        if ($minimumAttendance !== null && $attendance < (float) $minimumAttendance) {
            $blockers[] = 'Kehadiran '.$attendance.'% di bawah minimum '.$minimumAttendance.'%.';
        }

        if ($rules['required_sessions'] !== null && (int) $progress->sessions_attended < (int) $rules['required_sessions']) {
            $blockers[] = 'Jumlah pertemuan yang dihadiri belum mencapai '.$rules['required_sessions'].'.';
        }

        if ($rules['required_assessments'] !== null && (int) $progress->assessments_completed < (int) $rules['required_assessments']) {
            $blockers[] = 'Penilaian yang terselesaikan belum mencapai '.$rules['required_assessments'].'.';
        }

        $finalScore = $this->finalScore($membership, $program);

        if ($rules['final_assessment_min_score'] !== null && ($finalScore === null || $finalScore < (float) $rules['final_assessment_min_score'])) {
            $blockers[] = 'Nilai akhir belum mencapai '.$rules['final_assessment_min_score'].'.';
        }

        if ($rules['require_project_completed'] === true && ! $this->hasCompletedProject((int) $membership->student_id, (int) $program->id)) {
            $blockers[] = 'Proyek wajib belum dinyatakan selesai.';
        }

        if ($rules['require_coach_approval'] === true && $membership->metadata['coach_approved'] ?? false === false) {
            $blockers[] = 'Menunggu persetujuan pembina.';
        }

        return [
            'eligible' => $blockers === [],
            'blockers' => $blockers,
            'snapshot' => [
                'rules' => $rules,
                'attendance_percentage' => $attendance,
                'sessions_attended' => (int) $progress->sessions_attended,
                'assessments_completed' => (int) $progress->assessments_completed,
                'average_score' => $progress->average_score === null ? null : (float) $progress->average_score,
                'final_score' => $finalScore,
            ],
        ];
    }

    /** Tetapkan penyelesaian; bila memenuhi syarat sertifikat, tandai kelayakannya. */
    public function complete(ActivityMembership $membership, User $actor, array $payload = []): ActivityCompletion
    {
        if (! $actor->can('activity.assessment.manage') && ! $this->access->managesProgram($actor, $membership->program)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang menetapkan penyelesaian aktivitas ini.');
        }

        $evaluation = $this->evaluateCompletion($membership);
        $program = $membership->program;

        if (! $evaluation['eligible'] && ! ($payload['waive_rules'] ?? false)) {
            throw ActivityConflictException::make(
                'Aktivitas belum memenuhi syarat penyelesaian.',
                ['blockers' => $evaluation['blockers']],
            );
        }

        return DB::transaction(function () use ($membership, $actor, $payload, $evaluation, $program) {
            $completion = ActivityCompletion::query()->updateOrCreate(
                ['membership_id' => $membership->id, 'period_id' => $membership->period_id],
                [
                    'student_id' => (int) $membership->student_id,
                    'program_id' => (int) $membership->program_id,
                    'status' => ($payload['waive_rules'] ?? false) ? CompletionStatus::WAIVED->value : CompletionStatus::COMPLETED->value,
                    'rule_snapshot' => $evaluation['snapshot'],
                    'final_score' => $evaluation['snapshot']['final_score'],
                    'attendance_percentage' => $evaluation['snapshot']['attendance_percentage'],
                    'certificate_eligible' => $program === null ? true : $this->certificateEligible($program, $evaluation['snapshot']),
                    'notes' => $payload['notes'] ?? null,
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                    'completed_at' => now(),
                    'idempotency_key' => $payload['idempotency_key'] ?? 'completion|'.$membership->id.'|'.($membership->period_id ?? '-'),
                ],
            );

            $membership->forceFill([
                'status' => MembershipStatus::COMPLETED->value,
                'ended_at' => now()->toDateString(),
            ])->save();

            $membership->histories()->create([
                'action' => 'COMPLETED',
                'to_status' => MembershipStatus::COMPLETED->value,
                'reason' => $payload['notes'] ?? 'Aktivitas dinyatakan selesai.',
                'actor_user_id' => $actor->id,
            ]);

            ActivityProgress::query()->where('membership_id', $membership->id)->update([
                'status' => ProgressStatus::COMPLETED->value,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(AuditAction::COMPLETION_VERIFIED, [
                'subject' => $completion,
                'program_id' => (int) $membership->program_id,
                'student_id' => (int) $membership->student_id,
                'after' => $completion,
                'actor' => $actor->id,
            ]);

            if ($program !== null) {
                $this->integrations->call('ACADEMIC', 'recognition.submit', [
                    'subject_type' => ActivityCompletion::class,
                    'subject_id' => $completion->id,
                    'student_id' => (int) $membership->student_id,
                ], fn () => array_merge(['status' => 'OK'], $this->integrations->lms()->isAvailable() ? [] : []));
            }

            $this->achievements->recordCompletionEvidence($membership, $completion, $actor);

            return $completion->refresh();
        });
    }

    /** Nilai akhir: penilaian bertanda final bila ada, jika tidak rata-rata berbobot. */
    public function finalScore(ActivityMembership $membership, ?ActivityProgram $program = null): ?float
    {
        $program ??= $membership->program;

        if ($program === null) {
            return null;
        }

        $final = ActivityAssessmentResult::query()
            ->where('membership_id', $membership->id)
            ->whereHas('assessment', fn ($query) => $query->where('is_final', true))
            ->whereNotNull('score')
            ->latest('id')
            ->first();

        if ($final !== null) {
            return $final->score === null ? null : round((float) $final->score, 2);
        }

        $results = ActivityAssessmentResult::query()
            ->with('assessment:id,weight,max_score')
            ->where('membership_id', $membership->id)
            ->whereNotNull('score')
            ->get();

        if ($results->isEmpty()) {
            return null;
        }

        $weighted = 0.0;
        $weights = 0.0;

        foreach ($results as $result) {
            $weight = (float) ($result->assessment?->weight ?? 1);
            $max = (float) ($result->assessment?->max_score ?? 100);
            $normalised = $max <= 0 ? 0.0 : ((float) $result->score / $max) * 100;

            $weighted += $normalised * $weight;
            $weights += $weight;
        }

        return $weights <= 0 ? null : round($weighted / $weights, 2);
    }

    /** Kelayakan sertifikat (§105). */
    public function certificateEligible(ActivityProgram $program, array $snapshot): bool
    {
        $rules = array_merge([
            'require_completion' => true,
            'minimum_attendance_percentage' => $program->minimum_attendance_percentage,
            'final_assessment_min_score' => null,
        ], (array) ($program->certificate_rules ?? []));

        if (($rules['minimum_attendance_percentage'] ?? null) !== null
            && (float) ($snapshot['attendance_percentage'] ?? 0) < (float) $rules['minimum_attendance_percentage']) {
            return false;
        }

        if (($rules['final_assessment_min_score'] ?? null) !== null
            && (($snapshot['final_score'] ?? null) === null || (float) $snapshot['final_score'] < (float) $rules['final_assessment_min_score'])) {
            return false;
        }

        return true;
    }

    protected function progressStatus(ActivityProgress $progress, int $completed, int $total): string
    {
        if ($progress->completed_at !== null) {
            return ProgressStatus::COMPLETED->value;
        }

        if ($completed === 0) {
            return ProgressStatus::ACTIVE->value;
        }

        return $total > 0 && $completed >= $total ? ProgressStatus::PROGRESSING->value : ProgressStatus::ACTIVE->value;
    }

    protected function levelFor(?float $score, float $maxScore): ?string
    {
        if ($score === null || $maxScore <= 0) {
            return null;
        }

        $percentage = $score / $maxScore * 100;

        return match (true) {
            $percentage >= 85 => RubricLevel::ADVANCED->value,
            $percentage >= 70 => RubricLevel::COMPETENT->value,
            $percentage >= 50 => RubricLevel::DEVELOPING->value,
            default => RubricLevel::BEGINNER->value,
        };
    }

    protected function recordSkillEvidence(ActivityProgram $program, ActivityMembership $membership, ActivityAssessmentResult $result, User $actor): void
    {
        if ($result->score === null) {
            return;
        }

        foreach ($program->skills as $skill) {
            ActivitySkillEvidence::query()->create([
                'student_id' => (int) $membership->student_id,
                'skill_id' => $skill->id,
                'program_id' => (int) $program->id,
                'membership_id' => (int) $membership->id,
                'source' => SkillEvidenceSource::ASSESSMENT->value,
                'level' => $result->level?->value,
                'score' => $result->score,
                'evidence' => 'Penilaian: '.($result->assessment?->title ?? 'aktivitas'),
                'recorded_at' => now(),
                'created_by' => $actor->id,
            ]);
        }
    }

    protected function hasCompletedProject(int $studentId, int $programId): bool
    {
        return \App\Modules\Activity\Models\ActivityProject::query()
            ->where('program_id', $programId)
            ->where('status', 'COMPLETED')
            ->whereHas('members', fn ($query) => $query->where('student_id', $studentId))
            ->exists();
    }

    protected function assertManage(ActivityProgram $program, User $actor): void
    {
        if (! $this->access->canAssessProgram($actor, $program)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang mengelola penilaian program ini.');
        }
    }
}
