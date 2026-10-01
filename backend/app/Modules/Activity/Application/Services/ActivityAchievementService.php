<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;
use App\Modules\Activity\Domain\Enums\AchievementOwnership;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\SkillEvidenceSource;
use App\Modules\Activity\Domain\Enums\VerificationStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityAchievement;
use App\Modules\Activity\Models\ActivityAchievementMember;
use App\Modules\Activity\Models\ActivityCompletion;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivitySkillEvidence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Prestasi siswa — entitas tersendiri (§42–45, §102).
 *
 * Prestasi **tidak** ditulis sebagai catatan tempelan pada program, karena satu
 * prestasi dapat: dimiliki individu atau tim, berasal dari lomba atau penugasan
 * sekolah, dipakai sebagai bukti keterampilan, menjadi dasar sertifikat, dan
 * muncul di portofolio bertahun-tahun kemudian.
 *
 * Untuk prestasi tim, **seluruh anggota** mendapat kredit — dengan pengecualian
 * eksplisit bagi pemain cadangan yang tidak diikutkan.
 */
class ActivityAchievementService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityAccessService $access,
        private readonly ActivityNotificationService $notifications,
        private readonly StudentProviderInterface $students,
    ) {}

    /** @return LengthAwarePaginator */
    public function paginate(array $filters = [], ?User $viewer = null): LengthAwarePaginator
    {
        $query = ActivityAchievement::query()
            ->with(['student:id,full_name,nis', 'program:id,name', 'competition:id,name', 'members.student:id,full_name'])
            ->when(isset($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
            ->when(isset($filters['program_id']), fn ($q) => $q->where('program_id', $filters['program_id']))
            ->when(isset($filters['level']), fn ($q) => $q->where('level', $filters['level']))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['verification_status']), fn ($q) => $q->where('verification_status', $filters['verification_status']))
            ->orderByDesc('awarded_date');

        if ($viewer !== null && ! $viewer->can('activity.achievement.view')) {
            $studentIds = $this->access->visibleStudentIds($viewer);
            $query->where(fn ($inner) => $inner
                ->whereIn('student_id', $studentIds === [] ? [-1] : $studentIds)
                ->orWhereHas('members', fn ($member) => $member->whereIn('student_id', $studentIds === [] ? [-1] : $studentIds)));
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * Catat prestasi baru.
     *
     * @param  array<string, mixed>  $attributes  title, description, type, ownership, level, rank, awarded_date,
     *                                           organizer, location, program_id, competition_id, result_id,
     *                                           student_id, team_id, members[]
     */
    public function create(array $attributes, User $actor): ActivityAchievement
    {
        $ownership = $attributes['ownership'] ?? (isset($attributes['team_id']) ? AchievementOwnership::TEAM->value : AchievementOwnership::INDIVIDUAL->value);

        if ($ownership === AchievementOwnership::INDIVIDUAL->value && empty($attributes['student_id'])) {
            throw ActivityValidationException::withErrors(['Prestasi individu memerlukan siswa penerima.']);
        }

        if ($ownership === AchievementOwnership::TEAM->value && empty($attributes['team_id']) && empty($attributes['members'])) {
            throw ActivityValidationException::withErrors(['Prestasi tim memerlukan tim atau daftar anggotanya.']);
        }

        return DB::transaction(function () use ($attributes, $actor, $ownership) {
            $achievement = ActivityAchievement::query()->create([
                'code' => $this->nextCode(),
                'student_id' => $attributes['student_id'] ?? null,
                'team_id' => $attributes['team_id'] ?? null,
                'program_id' => $attributes['program_id'] ?? null,
                'competition_id' => $attributes['competition_id'] ?? null,
                'result_id' => $attributes['result_id'] ?? null,
                'achievement_type_id' => $attributes['achievement_type_id'] ?? null,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'type' => $attributes['type'] ?? 'OTHER',
                'ownership' => $ownership,
                'level' => $attributes['level'] ?? 'SCHOOL',
                'rank' => $attributes['rank'] ?? null,
                'awarded_date' => $attributes['awarded_date'] ?? now()->toDateString(),
                'organizer' => $attributes['organizer'] ?? null,
                'location' => $attributes['location'] ?? null,
                'evidence_path' => $attributes['evidence_path'] ?? null,
                'evidence_files' => $attributes['evidence_files'] ?? null,
                'verification_status' => $attributes['verification_status'] ?? VerificationStatus::PENDING->value,
                'counts_for_portfolio' => (bool) ($attributes['counts_for_portfolio'] ?? true),
                'created_by' => $actor->id,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            $this->syncMembers($achievement, $attributes);

            $this->audit->record(AuditAction::ACHIEVEMENT_CREATED, [
                'subject' => $achievement,
                'program_id' => $achievement->program_id === null ? null : (int) $achievement->program_id,
                'student_id' => $achievement->student_id === null ? null : (int) $achievement->student_id,
                'after' => $achievement,
                'actor' => $actor->id,
            ]);

            return $achievement->load('members');
        });
    }

    /** Verifikasi prestasi — hanya yang terverifikasi masuk portofolio resmi. */
    public function verify(ActivityAchievement $achievement, User $actor, string $decision, ?string $notes = null): ActivityAchievement
    {
        $decision = strtoupper($decision);

        if (! in_array($decision, [VerificationStatus::VERIFIED->value, VerificationStatus::REJECTED->value, VerificationStatus::REVOKED->value], true)) {
            throw ActivityValidationException::withErrors(['Keputusan verifikasi harus VERIFIED, REJECTED, atau REVOKED.']);
        }

        if (! $actor->can('activity.achievement.verify')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang memverifikasi prestasi.');
        }

        $before = $achievement->attributesToArray();

        $achievement->forceFill([
            'verification_status' => $decision,
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'verification_notes' => $notes,
        ])->save();

        $this->audit->record(AuditAction::ACHIEVEMENT_VERIFIED, [
            'subject' => $achievement,
            'program_id' => $achievement->program_id === null ? null : (int) $achievement->program_id,
            'student_id' => $achievement->student_id === null ? null : (int) $achievement->student_id,
            'before' => $before,
            'after' => $achievement,
            'metadata' => ['notes' => $notes],
            'actor' => $actor->id,
        ]);

        if ($decision === VerificationStatus::VERIFIED->value) {
            $this->recordAchievementSkillEvidence($achievement, $actor);

            $recipients = $this->recipientStudentIds($achievement);

            foreach ($recipients as $studentId) {
                $this->notifications->notify([
                    'event' => 'ACHIEVEMENT_AWARDED',
                    'program_id' => $achievement->program_id === null ? null : (int) $achievement->program_id,
                    'student_id' => $studentId,
                    'body' => 'Prestasi "'.$achievement->title.'" ('.$achievement->level->label().') telah diverifikasi.',
                    'marker' => 'achievement|'.$achievement->id.'|'.$studentId,
                ]);
            }
        }

        return $achievement->refresh();
    }

    /** Bukti keterampilan dari penyelesaian aktivitas (kepemimpinan, peran, partisipasi). */
    public function recordCompletionEvidence(ActivityMembership $membership, ActivityCompletion $completion, User $actor): void
    {
        $program = $membership->program;

        if ($program === null) {
            return;
        }

        $source = in_array($membership->role?->value, ['CAPTAIN', 'VICE_CAPTAIN', 'SECRETARY', 'TREASURER', 'COMMITTEE', 'TEAM_LEADER', 'MENTOR', 'PEER_MENTOR'], true)
            ? SkillEvidenceSource::LEADERSHIP
            : SkillEvidenceSource::ATTENDANCE;

        foreach ($program->skills as $skill) {
            ActivitySkillEvidence::query()->create([
                'student_id' => (int) $membership->student_id,
                'skill_id' => $skill->id,
                'program_id' => (int) $program->id,
                'membership_id' => (int) $membership->id,
                'source' => $source->value,
                'level' => $completion->status->value === 'COMPLETED' ? 'COMPETENT' : null,
                'score' => $completion->final_score === null ? null : (float) $completion->final_score,
                'evidence' => 'Penyelesaian aktivitas periode '.($membership->period?->name ?? '-').' sebagai '.($membership->role?->label() ?? 'anggota').'.',
                'recorded_at' => now(),
                'created_by' => $actor->id,
            ]);
        }
    }

    /** Semua prestasi (individu & tim) milik seorang siswa — bahan portofolio. */
    public function forStudent(int $studentId): array
    {
        return ActivityAchievement::query()
            ->with(['program:id,name', 'competition:id,name'])
            ->where(function ($query) use ($studentId) {
                $query->where('student_id', $studentId)
                    ->orWhereHas('members', fn ($member) => $member->where('student_id', $studentId)->where('receives_credit', true));
            })
            ->orderByDesc('awarded_date')
            ->get()
            ->map(fn (ActivityAchievement $achievement) => [
                'id' => (int) $achievement->id,
                'title' => $achievement->title,
                'type' => $achievement->type?->value,
                'type_label' => $achievement->type?->label(),
                'level' => $achievement->level?->value,
                'level_label' => $achievement->level?->label(),
                'rank' => $achievement->rank?->value,
                'rank_label' => $achievement->rank?->label(),
                'ownership' => $achievement->ownership?->value,
                'awarded_date' => $achievement->awarded_date?->toDateString(),
                'organizer' => $achievement->organizer,
                'program' => $achievement->program?->name,
                'competition' => $achievement->competition?->name,
                'verification_status' => $achievement->verification_status?->value,
                'verification_label' => $achievement->verification_status?->label(),
                'team_members' => $achievement->members()->count(),
            ])
            ->all();
    }

    /** @param array<string, mixed> $attributes */
    protected function syncMembers(ActivityAchievement $achievement, array $attributes): void
    {
        $members = $attributes['members'] ?? null;

        if ($members === null && isset($attributes['team_id'])) {
            $members = DB::table('activity_competition_members')
                ->where('team_id', $attributes['team_id'])
                ->get()
                ->map(fn ($row) => [
                    'student_id' => (int) $row->student_id,
                    'role' => $row->role,
                    'receives_credit' => (bool) $row->receives_achievement,
                ])
                ->all();
        }

        if ($members === null) {
            if ($achievement->student_id !== null) {
                ActivityAchievementMember::query()->updateOrCreate(
                    ['achievement_id' => $achievement->id, 'student_id' => $achievement->student_id],
                    ['role' => 'MEMBER', 'receives_credit' => true, 'certificate_eligible' => true],
                );
            }

            return;
        }

        foreach ($members as $member) {
            ActivityAchievementMember::query()->updateOrCreate(
                ['achievement_id' => $achievement->id, 'student_id' => $member['student_id']],
                [
                    'role' => $member['role'] ?? 'MEMBER',
                    'receives_credit' => (bool) ($member['receives_credit'] ?? true),
                    'certificate_eligible' => (bool) ($member['certificate_eligible'] ?? ($member['receives_credit'] ?? true)),
                ],
            );
        }
    }

    protected function recordAchievementSkillEvidence(ActivityAchievement $achievement, User $actor): void
    {
        if ($achievement->program_id === null) {
            return;
        }

        $program = \App\Modules\Activity\Models\ActivityProgram::query()->with('skills')->find($achievement->program_id);

        if ($program === null) {
            return;
        }

        foreach ($this->recipientStudentIds($achievement) as $studentId) {
            foreach ($program->skills as $skill) {
                ActivitySkillEvidence::query()->create([
                    'student_id' => $studentId,
                    'skill_id' => $skill->id,
                    'program_id' => (int) $program->id,
                    'membership_id' => $this->access->membershipOf($studentId, (int) $program->id)?->id,
                    'source' => SkillEvidenceSource::ACHIEVEMENT->value,
                    'level' => in_array($achievement->level?->value, ['NATIONAL', 'INTERNATIONAL', 'PROVINCE'], true) ? 'ADVANCED' : 'COMPETENT',
                    'score' => null,
                    'evidence' => 'Prestasi '.$achievement->title.' ('.$achievement->level->label().').',
                    'recorded_at' => now(),
                    'created_by' => $actor->id,
                ]);
            }
        }
    }

    /** @return list<int> */
    protected function recipientStudentIds(ActivityAchievement $achievement): array
    {
        $ids = $achievement->members()
            ->where('receives_credit', true)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === [] && $achievement->student_id !== null) {
            $ids[] = (int) $achievement->student_id;
        }

        return array_values(array_unique($ids));
    }

    protected function nextCode(): string
    {
        $prefix = 'ACH-ACT-'.now()->format('Y').'-';

        $last = ActivityAchievement::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('code');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
