<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Enums\BadgeAwardSource;
use App\Modules\LMS\Domain\Enums\BadgeRuleType;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Domain\Enums\GamificationEventType;
use App\Modules\LMS\Domain\Enums\LearningEventType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsBadge;
use App\Modules\LMS\Models\LmsBadgeAward;
use App\Modules\LMS\Models\LmsBadgeRule;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsForumPost;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsStudentCompetency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Badge sebagai capaian belajar (§26–§27).
 *
 * Dua jalur pemberian yang tidak saling meniadakan:
 *  - otomatis  — `evaluateEnrollment()` mencocokkan aturan badge dengan metrik
 *                enrollment; dipanggil setelah aktivitas bernilai selesai;
 *  - manual    — guru memberi langsung lewat `awardManual()` (aturan MANUAL
 *                tidak pernah dipicu sistem).
 *
 * Riwayat pemberian bersifat tetap: satu badge hanya sekali per siswa per
 * course. Pencabutan (`revoke()`) menghapus barisnya dan mencatat alasan pada
 * jejak audit, bukan mengubah baris lama.
 */
class BadgeService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly GamificationService $gamification,
        private readonly LearningEventService $events,
        private readonly GradeCalculationService $grades,
        private readonly LmsNotifier $notifier,
    ) {}

    /** @return list<array<string, mixed>> */
    public function ruleTypes(): array
    {
        return array_map(fn (BadgeRuleType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'automatic' => $type->isAutomatic(),
        ], BadgeRuleType::cases());
    }

    /** Katalog badge beserta jumlah pemberiannya (§26). */
    public function catalog(bool $onlyActive = false): array
    {
        $awardCounts = LmsBadgeAward::query()
            ->selectRaw('badge_id, COUNT(*) as total')
            ->groupBy('badge_id')
            ->pluck('total', 'badge_id');

        return LmsBadge::query()
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (LmsBadge $badge) => $this->row($badge, (int) ($awardCounts[$badge->id] ?? 0)))
            ->all();
    }

    public function find(int $id): LmsBadge
    {
        $badge = LmsBadge::query()->with('rules')->find($id);

        if ($badge === null) {
            LmsFault::notFound('Badge tidak ditemukan.');
        }

        return $badge;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, LmsActor $actor): LmsBadge
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            LmsFault::invalid('Nama badge wajib diisi.', 'name');
        }

        $code = $this->normalizeCode((string) ($data['code'] ?? $data['name'] ?? ''));

        if ($code === '') {
            LmsFault::invalid('Kode badge wajib diisi.', 'code');
        }

        if (LmsBadge::query()->where('code', $code)->exists()) {
            LmsFault::invalid('Kode badge sudah dipakai.', 'code');
        }

        $badge = LmsBadge::query()->create([
            'code' => $code,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'image_path' => $data['image_path'] ?? null,
            'category' => $data['category'] ?? null,
            'criteria_label' => $data['criteria_label'] ?? null,
            'rule_type' => BadgeRuleType::from((string) ($data['rule_type'] ?? BadgeRuleType::MANUAL->value)),
            'rule_config' => $data['rule_config'] ?? null,
            'points' => (int) ($data['points'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_system' => false,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'issuer' => $data['issuer'] ?? null,
            'created_by' => $actor->id,
        ]);

        if (! empty($data['rules'])) {
            $this->syncRules($badge, (array) $data['rules'], $actor);
        }

        $this->audit('LMS_BADGE_CREATED', $badge, ['code' => $badge->code]);

        return $badge->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(LmsBadge $badge, array $data, LmsActor $actor): LmsBadge
    {
        if (array_key_exists('code', $data)) {
            $code = $this->normalizeCode((string) $data['code']);

            $taken = LmsBadge::query()
                ->where('code', $code)
                ->whereKeyNot($badge->getKey())
                ->exists();

            if ($taken) {
                LmsFault::invalid('Kode badge sudah dipakai.', 'code');
            }

            $badge->code = $code;
        }

        $badge->fill(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'image_path' => $data['image_path'] ?? null,
            'category' => $data['category'] ?? null,
            'criteria_label' => $data['criteria_label'] ?? null,
            'issuer' => $data['issuer'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('rule_type', $data)) {
            $badge->rule_type = BadgeRuleType::from((string) $data['rule_type']);
        }

        if (array_key_exists('rule_config', $data)) {
            $badge->rule_config = $data['rule_config'];
        }

        if (array_key_exists('points', $data)) {
            $badge->points = (int) $data['points'];
        }

        if (array_key_exists('is_active', $data)) {
            $badge->is_active = (bool) $data['is_active'];
        }

        if (array_key_exists('sort_order', $data)) {
            $badge->sort_order = (int) $data['sort_order'];
        }

        $badge->save();

        if (array_key_exists('rules', $data)) {
            $this->syncRules($badge, (array) $data['rules'], $actor);
        }

        $this->audit('LMS_BADGE_UPDATED', $badge, ['code' => $badge->code]);

        return $badge->refresh();
    }

    /** Badge di-soft-delete; riwayat pemberian tetap tersimpan untuk audit (§27). */
    public function delete(LmsBadge $badge, LmsActor $actor): void
    {
        $this->audit('LMS_BADGE_DELETED', $badge, ['code' => $badge->code]);

        $badge->delete();
    }

    /**
     * Ganti seluruh aturan badge.
     *
     * Beberapa aturan pada satu badge bersifat alternatif: badge diberikan bila
     * SALAH SATU aturan aktif terpenuhi.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    public function syncRules(LmsBadge $badge, array $rules, LmsActor $actor): LmsBadge
    {
        DB::transaction(function () use ($badge, $rules): void {
            LmsBadgeRule::query()->where('badge_id', $badge->id)->delete();

            foreach ($rules as $rule) {
                $type = BadgeRuleType::from((string) ($rule['type'] ?? $badge->rule_type->value));

                LmsBadgeRule::query()->create([
                    'badge_id' => $badge->id,
                    'course_id' => $rule['course_id'] ?? null,
                    'type' => $type,
                    'threshold' => $rule['threshold'] ?? null,
                    'count' => $rule['count'] ?? null,
                    'config' => $rule['config'] ?? null,
                    'is_active' => (bool) ($rule['is_active'] ?? true),
                ]);
            }
        });

        $this->audit('LMS_BADGE_RULES_SYNCED', $badge, ['rules' => count($rules)]);

        return $badge->refresh()->load('rules');
    }

    // ------------------------------------------------------------------ pemberian badge

    /**
     * Beri badge ke satu enrollment (idempoten).
     *
     * @param  array<string, mixed>  $evidence
     */
    public function award(
        LmsBadge $badge,
        LmsEnrollment $enrollment,
        LmsActor $actor,
        BadgeAwardSource $source = BadgeAwardSource::AUTOMATIC,
        ?string $reason = null,
        array $evidence = [],
    ): ?LmsBadgeAward {
        if (! $badge->is_active) {
            return null;
        }

        $course = $enrollment->course;
        $userId = (int) $enrollment->user_id;

        if ($this->hasAward($badge, $userId, $course?->id)) {
            return null;
        }

        try {
            $award = LmsBadgeAward::query()->create([
                'badge_id' => $badge->id,
                'course_id' => $course?->id,
                'enrollment_id' => $enrollment->id,
                'user_id' => $userId,
                'student_id' => $enrollment->student_id ?? null,
                'source' => $source,
                'reason' => $reason,
                'evidence' => $evidence === [] ? null : $evidence,
                'awarded_at' => now(),
                'awarded_by' => $source === BadgeAwardSource::AUTOMATIC ? null : $actor->id,
            ]);
        } catch (QueryException $e) {
            // Balapan dua permintaan paralel: tetap dianggap sudah diberikan.
            report($e);

            return null;
        }

        if ($badge->points > 0) {
            $this->gamification->award(
                GamificationEventType::BADGE_AWARDED,
                $userId,
                $course,
                'badge:'.$badge->id.':'.$userId.':'.($course?->id ?? 'global'),
                $badge->points,
                ['badge' => $badge->code, 'note' => $badge->name],
                $badge,
            );
        }

        $this->events->record(
            LearningEventType::BADGE_AWARDED,
            $actor,
            $course,
            ['badge_code' => $badge->code, 'source' => $source->value],
        );

        $this->notifier->badgeAwarded([$userId], [
            'title' => 'Badge diperoleh: '.$badge->name,
            'message' => 'Badge "'.$badge->name.'" berhasil diperoleh'
                .($course === null ? '.' : ' pada course '.$course->name.'.'),
            'url' => $course === null ? '/lms/badges' : '/lms/courses/'.$course->id,
            'course_id' => $course?->id,
            'course_name' => $course?->name,
            'badge' => [
                'id' => (int) $badge->id,
                'code' => $badge->code,
                'name' => $badge->name,
                'icon' => $badge->icon,
                'points' => (int) $badge->points,
            ],
            'reason' => $reason,
            'awarded_at' => $award->awarded_at?->toIso8601String(),
        ]);

        $this->audit('LMS_BADGE_AWARDED', $badge, [
            'user_id' => $userId,
            'course_id' => $course?->id,
            'source' => $source->value,
        ]);

        return $award;
    }

    /**
     * Pemberian manual oleh guru (§27).
     *
     * @param  list<int>  $userIds
     * @return array<string, mixed>
     */
    public function awardManual(
        LmsBadge $badge,
        array $userIds,
        ?LmsCourse $course,
        LmsActor $actor,
        ?string $reason = null,
    ): array {
        $awarded = [];
        $skipped = [];

        foreach (array_values(array_unique($userIds)) as $userId) {
            $enrollment = LmsEnrollment::query()
                ->where('user_id', $userId)
                ->when($course !== null, fn ($query) => $query->where('course_id', $course->id))
                ->orderByDesc('id')
                ->first();

            if ($enrollment === null) {
                $skipped[] = ['user_id' => (int) $userId, 'reason' => 'Siswa tidak terdaftar pada course ini.'];

                continue;
            }

            $award = $this->award($badge, $enrollment, $actor, BadgeAwardSource::MANUAL, $reason);

            if ($award === null) {
                $skipped[] = ['user_id' => (int) $userId, 'reason' => 'Badge sudah dimiliki.'];

                continue;
            }

            $awarded[] = ['user_id' => (int) $userId, 'award_id' => (int) $award->id];
        }

        return ['awarded' => $awarded, 'skipped' => $skipped];
    }

    /**
     * Cabut badge: baris riwayat dihapus dan alasannya dicatat pada audit trail.
     *
     * Bukan penghapusan data diam-diam — poin yang menyertainya juga ditarik
     * supaya ledger XP tetap konsisten.
     */
    public function revoke(LmsBadgeAward $award, LmsActor $actor, string $reason): void
    {
        $badge = $award->badge;
        $userId = (int) $award->user_id;
        $courseId = $award->course_id === null ? null : (int) $award->course_id;

        $course = $courseId === null ? null : LmsCourse::query()->find($courseId);

        DB::transaction(function () use ($award): void {
            $award->delete();
        });

        if ($badge !== null) {
            $this->gamification->revoke(
                GamificationEventType::BADGE_AWARDED,
                $userId,
                $course,
                'badge:'.$badge->id.':'.$userId.':'.($courseId ?? 'global'),
                $badge,
            );

            $this->audit('LMS_BADGE_REVOKED', $badge, [
                'user_id' => $userId,
                'course_id' => $courseId,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Evaluasi seluruh aturan otomatis untuk satu enrollment.
     *
     * @return list<array<string, mixed>> badge yang baru diberikan
     */
    public function evaluateEnrollment(LmsEnrollment $enrollment, ?LmsActor $actor = null): array
    {
        $course = $enrollment->course ?? LmsCourse::query()->find($enrollment->course_id);

        if ($course === null || ! $course->gamificationEnabled()) {
            return [];
        }

        $badges = LmsBadge::query()
            ->with(['rules' => fn ($query) => $query
                ->where('is_active', true)
                ->where(fn ($inner) => $inner->whereNull('course_id')->orWhere('course_id', $course->id))])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (LmsBadge $badge) => $badge->rules->isNotEmpty());

        if ($badges->isEmpty()) {
            return [];
        }

        $metrics = $this->metricsFor($enrollment);
        $actor ??= $this->systemActor($enrollment);
        $awarded = [];

        foreach ($badges as $badge) {
            foreach ($badge->rules as $rule) {
                if (! $this->ruleSatisfied($rule, $metrics)) {
                    continue;
                }

                $award = $this->award(
                    $badge,
                    $enrollment,
                    $actor,
                    BadgeAwardSource::AUTOMATIC,
                    $rule->type->label(),
                    $this->evidenceFor($rule, $metrics),
                );

                if ($award !== null) {
                    $awarded[] = [
                        'badge_id' => (int) $badge->id,
                        'code' => $badge->code,
                        'name' => $badge->name,
                        'award_id' => (int) $award->id,
                        'rule' => $rule->type->value,
                    ];
                }

                break; // satu badge cukup sekali walau beberapa aturan terpenuhi.
            }
        }

        return $awarded;
    }

    /** Evaluasi badge untuk seluruh siswa aktif pada satu course. */
    public function evaluateCourse(LmsCourse $course, ?LmsActor $actor = null): array
    {
        $result = ['evaluated' => 0, 'awarded' => []];

        $course->enrollments()->where('role', 'STUDENT')->chunkById(100, function ($enrollments) use (&$result, $actor): void {
            foreach ($enrollments as $enrollment) {
                $result['evaluated']++;
                $result['awarded'] = array_merge($result['awarded'], $this->evaluateEnrollment($enrollment, $actor));
            }
        });

        return $result;
    }

    /**
     * Badge yang tersedia untuk sebuah course beserta aturan & capaian siswa.
     *
     * @return list<array<string, mixed>>
     */
    public function forCourse(LmsCourse $course): array
    {
        $awards = LmsBadgeAward::query()
            ->where('course_id', $course->id)
            ->get()
            ->groupBy('badge_id');

        return LmsBadge::query()
            ->with(['rules' => fn ($query) => $query->orderBy('id')])
            ->where('is_active', true)
            ->where(function ($query) use ($course): void {
                $query
                    ->whereHas('rules', fn ($inner) => $inner->where('is_active', true)->whereNull('course_id'))
                    ->orWhereHas('rules', fn ($inner) => $inner->where('is_active', true)->where('course_id', $course->id));
            })
            ->orderBy('sort_order')
            ->get()
            ->map(function (LmsBadge $badge) use ($awards): array {
                $rows = $awards->get($badge->id, collect());

                return $this->row($badge, $rows->count()) + [
                    'awarded_users' => $rows->take(20)->map(fn (LmsBadgeAward $award) => [
                        'user_id' => (int) $award->user_id,
                        'source' => $award->source->value,
                        'awarded_at' => $award->awarded_at?->toIso8601String(),
                    ])->values()->all(),
                ];
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function forEnrollment(LmsEnrollment $enrollment): array
    {
        return LmsBadgeAward::query()
            ->with('badge')
            ->where('enrollment_id', $enrollment->id)
            ->orderByDesc('awarded_at')
            ->get()
            ->filter(fn (LmsBadgeAward $award) => $award->badge !== null)
            ->map(fn (LmsBadgeAward $award) => [
                'id' => (int) $award->id,
                'code' => $award->badge->code,
                'name' => $award->badge->name,
                'icon' => $award->badge->icon,
                'source' => $award->source->value,
                'reason' => $award->reason,
                'awarded_at' => $award->awarded_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function hasAward(LmsBadge $badge, int $userId, ?int $courseId): bool
    {
        return LmsBadgeAward::query()
            ->where('badge_id', $badge->id)
            ->where('user_id', $userId)
            ->when($courseId === null, fn ($query) => $query->whereNull('course_id'))
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->exists();
    }

    // ------------------------------------------------------------------ evaluasi aturan

    /**
     * Metrik yang dipakai aturan badge — hanya angka yang memang sudah dihitung
     * sistem (progress, penyelesaian aktivitas, forum, nilai rilis, kompetensi).
     *
     * @return array<string, mixed>
     */
    public function metricsFor(LmsEnrollment $enrollment): array
    {
        $courseId = (int) $enrollment->course_id;
        $userId = (int) $enrollment->user_id;

        return [
            'completion_percent' => (float) ($enrollment->progress_percent ?? 0),
            'course_completed' => $enrollment->status === EnrollmentStatus::COMPLETED
                || $enrollment->completed_at !== null,
            'completed_activities' => LmsActivityCompletion::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', 'COMPLETE')
                ->count(),
            'forum_posts' => LmsForumPost::query()
                ->where('user_id', $userId)
                ->whereHas('discussion.forum.activity', fn ($query) => $query->where('course_id', $courseId))
                ->count(),
            'final_grade' => $this->finalGradeFor($courseId, $userId),
            'achieved_competency_ids' => LmsStudentCompetency::query()
                ->where('course_id', $courseId)
                ->where('user_id', $userId)
                ->get()
                ->filter(fn (LmsStudentCompetency $competency) => $competency->isAchieved())
                ->pluck('competency_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $metrics */
    public function ruleSatisfied(LmsBadgeRule $rule, array $metrics): bool
    {
        return match ($rule->type) {
            BadgeRuleType::COMPLETION => (bool) $metrics['course_completed']
                || (float) $metrics['completion_percent'] >= (float) ($rule->threshold ?? 100),
            BadgeRuleType::GRADE => $metrics['final_grade'] !== null
                && (float) $metrics['final_grade'] >= (float) ($rule->threshold ?? config('lms.certificate.passing_grade', 70)),
            BadgeRuleType::ACTIVITY_COUNT => (int) $metrics['completed_activities'] >= max(1, (int) ($rule->count ?? 1)),
            BadgeRuleType::FORUM_PARTICIPATION => (int) $metrics['forum_posts'] >= max(1, (int) ($rule->count ?? 1)),
            BadgeRuleType::COMPETENCY => $this->competencyRuleSatisfied($rule, $metrics),
            // MANUAL hanya lewat awardManual() — sistem tidak pernah memicunya.
            BadgeRuleType::MANUAL => false,
        };
    }

    // ------------------------------------------------------------------ internal

    /** @param array<string, mixed> $metrics */
    private function competencyRuleSatisfied(LmsBadgeRule $rule, array $metrics): bool
    {
        $achieved = (array) $metrics['achieved_competency_ids'];
        $required = $rule->config['competency_id'] ?? null;

        if ($required === null) {
            return $achieved !== [];
        }

        return in_array((int) $required, array_map('intval', $achieved), true);
    }

    /** @param array<string, mixed> $metrics @return array<string, mixed> */
    private function evidenceFor(LmsBadgeRule $rule, array $metrics): array
    {
        return [
            'rule' => $rule->type->value,
            'threshold' => $rule->threshold === null ? null : (float) $rule->threshold,
            'count' => $rule->count === null ? null : (int) $rule->count,
            'metrics' => [
                'completion_percent' => (float) $metrics['completion_percent'],
                'completed_activities' => (int) $metrics['completed_activities'],
                'forum_posts' => (int) $metrics['forum_posts'],
                'final_grade' => $metrics['final_grade'] === null ? null : (float) $metrics['final_grade'],
            ],
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    private function finalGradeFor(int $courseId, int $userId): ?float
    {
        $gradebook = LmsGradebook::query()->where('course_id', $courseId)->first();

        if ($gradebook === null) {
            return null;
        }

        $calculation = $this->grades->calculate($gradebook, $userId);

        return $calculation['normalized'] === null ? null : (float) $calculation['normalized'];
    }

    /** Aktor sistem untuk pemberian otomatis (tanpa guru yang memicu). */
    private function systemActor(LmsEnrollment $enrollment): LmsActor
    {
        return new LmsActor(
            id: 0,
            name: 'Sistem LMS',
            email: null,
            avatarPath: null,
            primaryRole: ActorRole::STUDENT,
            roles: [],
            permissions: [],
            superAdmin: false,
            standalone: (bool) config('lms.standalone', false),
            studentId: $enrollment->student_id === null ? null : (int) $enrollment->student_id,
        );
    }

    /** @return array<string, mixed> */
    private function row(LmsBadge $badge, int $awardCount): array
    {
        return [
            'id' => (int) $badge->id,
            'code' => $badge->code,
            'name' => $badge->name,
            'description' => $badge->description,
            'icon' => $badge->icon,
            'image_path' => $badge->image_path,
            'category' => $badge->category,
            'criteria_label' => $badge->criteria_label,
            'rule_type' => $badge->rule_type->value,
            'rule_type_label' => $badge->rule_type->label(),
            'rule_config' => $badge->rule_config,
            'points' => (int) $badge->points,
            'is_active' => (bool) $badge->is_active,
            'is_system' => (bool) $badge->is_system,
            'sort_order' => (int) $badge->sort_order,
            'issuer' => $badge->issuer,
            'award_count' => $awardCount,
            'rules' => $badge->relationLoaded('rules')
                ? $badge->rules->map(fn (LmsBadgeRule $rule) => [
                    'id' => (int) $rule->id,
                    'course_id' => $rule->course_id === null ? null : (int) $rule->course_id,
                    'type' => $rule->type->value,
                    'type_label' => $rule->type->label(),
                    'threshold' => $rule->threshold === null ? null : (float) $rule->threshold,
                    'count' => $rule->count === null ? null : (int) $rule->count,
                    'config' => $rule->config,
                    'is_active' => (bool) $rule->is_active,
                ])->values()->all()
                : [],
        ];
    }

    private function normalizeCode(string $code): string
    {
        $normalized = Str::of($code)->trim()->upper()->replaceMatches('/[^A-Z0-9_\-]/', '-')->trim('-')->value();

        return Str::limit($normalized, 60, '');
    }
}
