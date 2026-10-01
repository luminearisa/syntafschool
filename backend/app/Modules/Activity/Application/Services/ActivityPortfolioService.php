<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityAchievement;
use App\Modules\Activity\Models\ActivityCertificate;
use App\Modules\Activity\Models\ActivityCompletion;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityPortfolio;
use App\Modules\Activity\Models\ActivityProgress;
use App\Modules\Activity\Models\ActivityProject;
use App\Modules\Activity\Models\ActivitySkillEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portofolio & transkrip aktivitas (§portofolio, §103, §104).
 *
 * Portofolio adalah **potret**, bukan sumber data: ia dirakit dari keanggotaan,
 * kehadiran, penilaian, keterampilan, prestasi, dan sertifikat yang sudah ada.
 * Karena itu menyegarkan portofolio tidak pernah mengubah data aslinya — dan
 * bila data aslinya berubah, portofolio lama tetap dapat dibaca sebagai potret
 * pada saat itu.
 */
class ActivityPortfolioService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityAccessService $access,
        private readonly StudentProviderInterface $students,
        private readonly ActivityAssessmentService $assessments,
    ) {}

    /** Rakit isi portofolio dari seluruh sumber. */
    public function build(int $studentId, ?int $periodId = null): array
    {
        $memberships = ActivityMembership::query()
            ->with(['program:id,name,code,category_id', 'period:id,name', 'progress'])
            ->where('student_id', $studentId)
            ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
            ->orderByDesc('joined_at')
            ->get();

        $activities = $memberships->map(fn (ActivityMembership $membership) => [
            'membership_id' => (int) $membership->id,
            'program_id' => (int) $membership->program_id,
            'program' => $membership->program?->name,
            'period' => $membership->period?->name,
            'role' => $membership->role?->value,
            'role_label' => $membership->role?->label(),
            'status' => $membership->status?->value,
            'status_label' => $membership->status?->label(),
            'joined_at' => $membership->joined_at?->toDateString(),
            'ended_at' => $membership->ended_at?->toDateString(),
            'attendance_percentage' => $membership->progress?->attendance_percentage === null ? null : (float) $membership->progress->attendance_percentage,
            'average_score' => $membership->progress?->average_score === null ? null : (float) $membership->progress->average_score,
            'completed' => $membership->status === MembershipStatus::COMPLETED,
        ]);

        $skills = ActivitySkillEvidence::query()
            ->with('skill:id,name,code')
            ->where('student_id', $studentId)
            ->when($periodId !== null, fn ($query) => $query->whereHas('membership', fn ($membership) => $membership->where('period_id', $periodId)))
            ->get()
            ->groupBy(fn (ActivitySkillEvidence $evidence) => $evidence->skill?->name ?? 'Lainnya')
            ->map(fn ($group, $name) => [
                'skill' => $name,
                'level' => $group->sortByDesc('score')->first()?->level?->value,
                'score' => $group->max('score') === null ? null : (float) $group->max('score'),
                'evidence_count' => $group->count(),
                'sources' => $group->pluck('source.value')->unique()->values()->all(),
            ])
            ->values()
            ->all();

        $projects = ActivityProject::query()
            ->with('members')
            ->whereHas('members', fn ($query) => $query->where('student_id', $studentId))
            ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
            ->get()
            ->map(fn (ActivityProject $project) => [
                'id' => (int) $project->id,
                'title' => $project->title,
                'status' => $project->status?->value,
                'started_on' => $project->started_on?->toDateString(),
                'completed_on' => $project->completed_on?->toDateString(),
                'skills' => $project->skills,
            ])
            ->all();

        $achievements = ActivityAchievement::query()
            ->with(['program:id,name', 'competition:id,name'])
            ->where('counts_for_portfolio', true)
            ->where(function ($query) use ($studentId) {
                $query->where('student_id', $studentId)
                    ->orWhereHas('members', fn ($member) => $member->where('student_id', $studentId)->where('receives_credit', true));
            })
            ->when($periodId !== null, fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('program_id')
                ->orWhereHas('program', fn ($program) => $program->where('period_id', $periodId))))
            ->orderByDesc('awarded_date')
            ->get()
            ->map(fn (ActivityAchievement $achievement) => [
                'id' => (int) $achievement->id,
                'title' => $achievement->title,
                'type' => $achievement->type?->value,
                'level' => $achievement->level?->value,
                'level_label' => $achievement->level?->label(),
                'rank' => $achievement->rank?->value,
                'awarded_date' => $achievement->awarded_date?->toDateString(),
                'verification_status' => $achievement->verification_status?->value,
                'program' => $achievement->program?->name,
                'competition' => $achievement->competition?->name,
            ])
            ->all();

        $certificates = ActivityCertificate::query()
            ->with('program:id,name')
            ->where('student_id', $studentId)
            ->where('status', 'ISSUED')
            ->orderByDesc('issued_date')
            ->get()
            ->map(fn (ActivityCertificate $certificate) => [
                'id' => (int) $certificate->id,
                'number' => $certificate->certificate_number,
                'type' => $certificate->type?->value,
                'title' => $certificate->title,
                'issued_date' => $certificate->issued_date?->toDateString(),
                'program' => $certificate->program?->name,
            ])
            ->all();

        $leadership = $memberships
            ->filter(fn (ActivityMembership $membership) => in_array($membership->role?->value, [
                'CAPTAIN', 'VICE_CAPTAIN', 'SECRETARY', 'TREASURER', 'COMMITTEE', 'TEAM_LEADER', 'MENTOR', 'PEER_MENTOR',
            ], true))
            ->map(fn (ActivityMembership $membership) => [
                'program' => $membership->program?->name,
                'role' => $membership->role?->label(),
                'period' => $membership->period?->name,
            ])
            ->values()
            ->all();

        $service = ActivityMembership::query()
            ->with('program:id,name,category_id')
            ->where('student_id', $studentId)
            ->whereHas('program.category', fn ($category) => $category->whereIn('code', ['COMMUNITY', 'SOCIAL', 'VOLUNTEER']))
            ->get()
            ->map(fn (ActivityMembership $membership) => [
                'program' => $membership->program?->name,
                'joined_at' => $membership->joined_at?->toDateString(),
            ])
            ->all();

        $feedback = ActivityProgress::query()
            ->where('student_id', $studentId)
            ->whereNotNull('completion_snapshot')
            ->get()
            ->pluck('completion_snapshot')
            ->all();

        return [
            'student' => $this->students->snapshot($studentId),
            'period_id' => $periodId,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'activities' => $activities->count(),
                'active_activities' => $activities->where('status', MembershipStatus::ACTIVE->value)->count(),
                'skills' => count($skills),
                'achievements' => count($achievements),
                'verified_achievements' => count(array_filter($achievements, fn (array $row) => $row['verification_status'] === 'VERIFIED')),
                'certificates' => count($certificates),
                'leadership_roles' => count($leadership),
                'projects' => count($projects),
                'average_attendance' => $activities->whereNotNull('attendance_percentage')->avg('attendance_percentage'),
            ],
            'activities' => $activities->all(),
            'skills' => $skills,
            'projects' => $projects,
            'leadership' => $leadership,
            'community_service' => $service,
            'achievements' => $achievements,
            'certificates' => $certificates,
            'coach_feedback' => $feedback,
        ];
    }

    /** Simpan potret portofolio (dipakai saat berbagi atau cetak). */
    public function store(int $studentId, ?int $periodId, User $actor): ActivityPortfolio
    {
        $this->authorise($actor, $studentId);

        return DB::transaction(function () use ($studentId, $periodId, $actor) {
            $portfolio = ActivityPortfolio::query()->create([
                'student_id' => $studentId,
                'period_id' => $periodId,
                'scope' => $periodId === null ? 'ALL_TIME' : 'PERIOD',
                'snapshot' => $this->build($studentId, $periodId),
                'generated_at' => now(),
                'created_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::PORTFOLIO_GENERATED, [
                'subject' => $portfolio,
                'student_id' => $studentId,
                'after' => ['scope' => $portfolio->scope, 'period_id' => $periodId],
                'actor' => $actor->id,
            ]);

            return $portfolio;
        });
    }

    /** Nyalakan/matikan tautan publik (harus eksplisit, dengan jejak audit). */
    public function share(ActivityPortfolio $portfolio, User $actor, bool $enable): ActivityPortfolio
    {
        $this->authorise($actor, (int) $portfolio->student_id);

        if ($enable && ! $actor->can('activity.portfolio.share')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang membagikan portofolio siswa.');
        }

        $portfolio->forceFill([
            'public_share_enabled' => $enable,
            'public_token' => $enable ? ($portfolio->public_token ?? Str::random(48)) : $portfolio->public_token,
            'public_shared_at' => $enable ? now() : null,
        ])->save();

        $this->audit->record(AuditAction::PORTFOLIO_SHARED, [
            'subject' => $portfolio,
            'student_id' => (int) $portfolio->student_id,
            'after' => ['enabled' => $enable],
            'actor' => $actor->id,
        ]);

        return $portfolio->refresh();
    }

    public function publicView(string $token): ?array
    {
        $portfolio = ActivityPortfolio::query()
            ->where('public_token', $token)
            ->where('public_share_enabled', true)
            ->first();

        if ($portfolio === null) {
            return null;
        }

        $snapshot = $portfolio->snapshot ?? [];

        // Berbagi publik: identitas siswa disamarkan — yang dibagikan adalah capaiannya.
        unset($snapshot['student']['nis'], $snapshot['student']['nisn']);

        if (isset($snapshot['student']['name'])) {
            $snapshot['student']['name'] = Str::mask((string) $snapshot['student']['name'], '*', 2);
        }

        return $snapshot;
    }

    /** Transkrip aktivitas (§104) — berbeda dari transkrip akademik. */
    public function transcript(int $studentId, ?int $periodId = null): array
    {
        $memberships = ActivityMembership::query()
            ->with(['program:id,name,code', 'period:id,name'])
            ->where('student_id', $studentId)
            ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
            ->get();

        $rows = $memberships->map(function (ActivityMembership $membership) use ($studentId) {
            $progress = $this->assessments->recalculateProgress((int) $membership->id);

            return [
                'period' => $membership->period?->name,
                'activity' => $membership->program?->name,
                'code' => $membership->program?->code,
                'role' => $membership->role?->label(),
                'status' => $membership->status?->label(),
                'joined_at' => $membership->joined_at?->toDateString(),
                'ended_at' => $membership->ended_at?->toDateString(),
                'attendance_percentage' => $progress->attendance_percentage === null ? null : (float) $progress->attendance_percentage,
                'average_score' => $progress->average_score === null ? null : (float) $progress->average_score,
                'achievements' => ActivityAchievement::query()
                    ->where('program_id', $membership->program_id)
                    ->where(fn ($query) => $query->where('student_id', $studentId)
                        ->orWhereHas('members', fn ($member) => $member->where('student_id', $studentId)->where('receives_credit', true)))
                    ->count(),
                'certificate' => ActivityCertificate::query()
                    ->where('student_id', $studentId)
                    ->where('program_id', $membership->program_id)
                    ->where('status', 'ISSUED')
                    ->value('certificate_number'),
            ];
        });

        return [
            'student' => $this->students->snapshot($studentId),
            'period_id' => $periodId,
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows->all(),
            'totals' => [
                'activities' => $rows->count(),
                'completed' => $memberships->where('status', MembershipStatus::COMPLETED)->count(),
                'average_attendance' => $rows->whereNotNull('attendance_percentage')->avg('attendance_percentage'),
                'average_score' => $rows->whereNotNull('average_score')->avg('average_score'),
            ],
        ];
    }

    protected function authorise(User $actor, int $studentId): void
    {
        if (! $this->access->canViewStudent($actor, $studentId)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang melihat portofolio siswa ini.');
        }
    }
}
