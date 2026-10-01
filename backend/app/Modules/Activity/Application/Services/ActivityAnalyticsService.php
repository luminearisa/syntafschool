<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\CompletionStatus;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Enums\ProgramStatus;
use App\Modules\Activity\Domain\Enums\RegistrationStatus;
use App\Modules\Activity\Domain\Enums\SessionStatus;
use App\Modules\Activity\Models\ActivityAchievement;
use App\Modules\Activity\Models\ActivityAssessment;
use App\Modules\Activity\Models\ActivityAttendance;
use App\Modules\Activity\Models\ActivityCertificate;
use App\Modules\Activity\Models\ActivityCompetition;
use App\Modules\Activity\Models\ActivityCompletion;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistration;
use App\Modules\Activity\Models\ActivitySession;
use Illuminate\Support\Facades\DB;

/**
 * Dasbor & laporan aktivitas (§76–80).
 *
 * Angka-angka ini dipakai untuk **mengelola kegiatan**, bukan menilai anak:
 * popularitas, kapasitas, daftar tunggu, angka berhenti, dan tingkat
 * penyelesaian. Laporan per siswa tetap tersedia, tetapi dibatasi izin dan
 * cakupan baris — dan tidak pernah memuat catatan konseling.
 */
class ActivityAnalyticsService
{
    public function __construct(private readonly ActivityAccessService $access) {}

    /** Dasbor pengelola aktivitas. */
    public function adminDashboard(array $filters = []): array
    {
        $periodId = $filters['period_id'] ?? null;

        $programs = ActivityProgram::query()
            ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
            ->get();

        $memberships = ActivityMembership::query()
            ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
            ->get();

        $activeMemberships = $memberships->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value]);

        return [
            'programs' => [
                'total' => $programs->count(),
                'active' => $programs->whereIn('status', [ProgramStatus::ACTIVE->value, ProgramStatus::PUBLISHED->value])->count(),
                'draft' => $programs->where('status', ProgramStatus::DRAFT->value)->count(),
            ],
            'students' => [
                'active_members' => $activeMemberships->pluck('student_id')->unique()->count(),
                'memberships' => $memberships->count(),
                'average_activities_per_student' => $activeMemberships->pluck('student_id')->unique()->count() === 0
                    ? 0
                    : round($activeMemberships->count() / $activeMemberships->pluck('student_id')->unique()->count(), 2),
            ],
            'registrations' => [
                'total' => ActivityRegistration::query()->when($periodId !== null, fn ($q) => $q->where('period_id', $periodId))->count(),
                'pending' => ActivityRegistration::query()->when($periodId !== null, fn ($q) => $q->where('period_id', $periodId))
                    ->whereIn('status', [RegistrationStatus::SUBMITTED->value, RegistrationStatus::UNDER_REVIEW->value])->count(),
                'waitlisted' => ActivityRegistration::query()->when($periodId !== null, fn ($q) => $q->where('period_id', $periodId))
                    ->where('status', RegistrationStatus::WAITLISTED->value)->count(),
            ],
            'attendance' => $this->attendanceTotals($periodId),
            'today_sessions' => $this->sessionsOn(now()->toDateString())->count(),
            'upcoming_sessions' => $this->sessionsOn(now()->addDay()->toDateString())->count(),
            'competitions' => [
                'ongoing' => ActivityCompetition::query()->whereIn('status', ['REGISTRATION_OPEN', 'ONGOING'])->count(),
                'upcoming' => ActivityCompetition::query()->where('starts_on', '>=', now()->toDateString())->count(),
            ],
            'achievements' => [
                'total' => ActivityAchievement::query()->count(),
                'verified' => ActivityAchievement::query()->where('verification_status', 'VERIFIED')->count(),
                'this_year' => ActivityAchievement::query()->whereYear('awarded_date', now()->year)->count(),
            ],
            'certificates' => [
                'issued' => ActivityCertificate::query()->where('status', 'ISSUED')->count(),
                'revoked' => ActivityCertificate::query()->where('status', 'REVOKED')->count(),
            ],
            'popular_activities' => ActivityMembership::query()
                ->selectRaw('program_id, count(*) as members')
                ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                ->when($periodId !== null, fn ($q) => $q->where('period_id', $periodId))
                ->groupBy('program_id')
                ->orderByDesc('members')
                ->limit(8)
                ->with('program:id,name')
                ->get()
                ->map(fn ($row) => [
                    'program_id' => (int) $row->program_id,
                    'program' => $row->program?->name,
                    'members' => (int) $row->members,
                ])
                ->all(),
            'capacity_alerts' => $this->capacityAlerts($periodId),
        ];
    }

    /** Dasbor pembina/pelatih (§78). */
    public function coachDashboard(User $actor): array
    {
        $programIds = $this->access->coachedProgramIds($actor);

        if ($programIds === []) {
            return [
                'programs' => [],
                'today_sessions' => [],
                'pending_assessments' => 0,
                'attendance_completion' => null,
                'message' => 'Belum ada program aktivitas yang ditugaskan kepada Anda.',
            ];
        }

        $programs = ActivityProgram::query()->whereIn('id', $programIds)->get();

        $todaySessions = ActivitySession::query()
            ->with('program:id,name')
            ->whereIn('program_id', $programIds)
            ->where('date', now()->toDateString())
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->orderBy('start_time')
            ->get()
            ->map(fn (ActivitySession $session) => [
                'id' => (int) $session->id,
                'program' => $session->program?->name,
                'start_time' => substr((string) $session->start_time, 0, 5),
                'end_time' => substr((string) $session->end_time, 0, 5),
                'topic' => $session->topic,
                'status' => $session->status?->value,
                'attendance_filled' => ActivityAttendance::query()->where('session_id', $session->id)->count(),
            ])
            ->all();

        $upcoming = ActivitySession::query()
            ->with('program:id,name')
            ->whereIn('program_id', $programIds)
            ->where('date', '>', now()->toDateString())
            ->where('date', '<=', now()->addDays(14)->toDateString())
            ->orderBy('date')
            ->limit(10)
            ->get()
            ->map(fn (ActivitySession $session) => [
                'id' => (int) $session->id,
                'program' => $session->program?->name,
                'date' => $session->date?->toDateString(),
                'start_time' => substr((string) $session->start_time, 0, 5),
            ])
            ->all();

        return [
            'programs' => $programs->map(fn (ActivityProgram $program) => [
                'id' => (int) $program->id,
                'name' => $program->name,
                'members' => ActivityMembership::query()
                    ->where('program_id', $program->id)
                    ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                    ->count(),
                'sessions' => ActivitySession::query()->where('program_id', $program->id)->count(),
                'assessments' => ActivityAssessment::query()->where('program_id', $program->id)->count(),
            ])->all(),
            'today_sessions' => $todaySessions,
            'upcoming_sessions' => $upcoming,
            'pending_assessments' => ActivityAssessment::query()
                ->whereIn('program_id', $programIds)
                ->where('status', 'PUBLISHED')
                ->whereDoesntHave('results')
                ->count(),
            'pending_registrations' => $this->access->pendingDecisionCount($actor),
            'attendance_completion' => $this->attendanceCompletion($programIds),
        ];
    }

    /** Dasbor siswa (§79). */
    public function studentDashboard(int $studentId): array
    {
        $memberships = ActivityMembership::query()
            ->with(['program:id,name', 'progress'])
            ->where('student_id', $studentId)
            ->get();

        return [
            'my_activities' => $memberships
                ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                ->map(fn (ActivityMembership $membership) => [
                    'id' => (int) $membership->id,
                    'program' => $membership->program?->name,
                    'role' => $membership->role?->label(),
                    'attendance_percentage' => $membership->progress?->attendance_percentage === null ? null : (float) $membership->progress->attendance_percentage,
                    'average_score' => $membership->progress?->average_score === null ? null : (float) $membership->progress->average_score,
                ])->values()->all(),
            'today_schedule' => $this->sessionsForStudent($studentId, now()->toDateString()),
            'upcoming_sessions' => ActivitySession::query()
                ->with('program:id,name')
                ->whereIn('program_id', $memberships->pluck('program_id'))
                ->where('date', '>', now()->toDateString())
                ->orderBy('date')
                ->limit(5)
                ->get()
                ->map(fn (ActivitySession $session) => [
                    'program' => $session->program?->name,
                    'date' => $session->date?->toDateString(),
                    'start_time' => substr((string) $session->start_time, 0, 5),
                ])->all(),
            'achievements' => ActivityAchievement::query()
                ->where(fn ($query) => $query->where('student_id', $studentId)
                    ->orWhereHas('members', fn ($member) => $member->where('student_id', $studentId)->where('receives_credit', true)))
                ->count(),
            'certificates' => ActivityCertificate::query()->where('student_id', $studentId)->where('status', 'ISSUED')->count(),
            'completed_activities' => $memberships->where('status', MembershipStatus::COMPLETED)->count(),
        ];
    }

    /** Dasbor orang tua (§80). */
    public function parentDashboard(User $actor): array
    {
        $children = [];

        foreach ($this->access->childStudentIds($actor) as $studentId) {
            $children[] = array_merge(
                ['student_id' => $studentId],
                $this->studentDashboard($studentId),
                [
                    'pending_consents' => DB::table('activity_consents')
                        ->where('student_id', $studentId)
                        ->where('decision', 'PENDING')
                        ->count(),
                ],
            );
        }

        return ['children' => $children];
    }

    /**
     * Laporan berkategori (§76).
     *
     * @return array{type: string, columns: list<string>, rows: list<array>, totals: array}
     */
    public function report(string $type, array $filters = []): array
    {
        $periodId = $filters['period_id'] ?? null;
        $programId = $filters['program_id'] ?? null;

        return match ($type) {
            'participation' => [
                'type' => $type,
                'columns' => ['activity', 'category', 'members', 'capacity', 'fill_rate'],
                'rows' => ActivityProgram::query()
                    ->with('category:id,name')
                    ->when($programId !== null, fn ($query) => $query->whereKey($programId))
                    ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
                    ->get()
                    ->map(function (ActivityProgram $program) {
                        $members = ActivityMembership::query()
                            ->where('program_id', $program->id)
                            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                            ->count();

                        return [
                            'program_id' => (int) $program->id,
                            'activity' => $program->name,
                            'category' => $program->category?->name,
                            'members' => $members,
                            'capacity' => $program->maximum_capacity,
                            'fill_rate' => $program->maximum_capacity === null || (int) $program->maximum_capacity === 0
                                ? null
                                : round($members / (int) $program->maximum_capacity * 100, 1),
                        ];
                    })->all(),
                'totals' => ['programs' => ActivityProgram::query()->count()],
            ],
            'attendance' => [
                'type' => $type,
                'columns' => ['activity', 'sessions', 'records', 'present', 'percentage'],
                'rows' => ActivityProgram::query()
                    ->when($programId !== null, fn ($query) => $query->whereKey($programId))
                    ->get()
                    ->map(function (ActivityProgram $program) {
                        $records = ActivityAttendance::query()
                            ->whereHas('session', fn ($query) => $query->where('program_id', $program->id))
                            ->count();

                        $present = ActivityAttendance::query()
                            ->whereHas('session', fn ($query) => $query->where('program_id', $program->id))
                            ->whereIn('status', ['PRESENT', 'LATE'])
                            ->count();

                        return [
                            'program_id' => (int) $program->id,
                            'activity' => $program->name,
                            'sessions' => ActivitySession::query()->where('program_id', $program->id)->count(),
                            'records' => $records,
                            'present' => $present,
                            'percentage' => $records === 0 ? null : round($present / $records * 100, 1),
                        ];
                    })->all(),
                'totals' => $this->attendanceTotals($periodId),
            ],
            'achievements' => [
                'type' => $type,
                'columns' => ['level', 'total', 'verified'],
                'rows' => ActivityAchievement::query()
                    ->selectRaw('level, count(*) as total, sum(case when verification_status = ? then 1 else 0 end) as verified', ['VERIFIED'])
                    ->groupBy('level')
                    ->orderByDesc('total')
                    ->get()
                    ->map(fn ($row) => [
                        'level' => $row->level,
                        'total' => (int) $row->total,
                        'verified' => (int) $row->verified,
                    ])->all(),
                'totals' => ['achievements' => ActivityAchievement::query()->count()],
            ],
            'certificates' => [
                'type' => $type,
                'columns' => ['type', 'issued', 'revoked'],
                'rows' => ActivityCertificate::query()
                    ->selectRaw('type, count(*) as issued, sum(case when status = ? then 1 else 0 end) as revoked', ['REVOKED'])
                    ->where('status', '!=', 'DRAFT')
                    ->groupBy('type')
                    ->get()
                    ->map(fn ($row) => [
                        'type' => $row->type,
                        'issued' => (int) $row->issued,
                        'revoked' => (int) $row->revoked,
                    ])->all(),
                'totals' => ['certificates' => ActivityCertificate::query()->where('status', 'ISSUED')->count()],
            ],
            'capacity' => [
                'type' => $type,
                'columns' => ['activity', 'capacity', 'active', 'waitlist', 'seats_left'],
                'rows' => $this->capacityAlerts($periodId),
                'totals' => ['waitlisted' => DB::table('activity_waitlist_entries')->where('status', 'WAITING')->count()],
            ],
            'dropout' => [
                'type' => $type,
                'columns' => ['activity', 'withdrawn', 'suspended', 'active'],
                'rows' => ActivityProgram::query()
                    ->when($programId !== null, fn ($query) => $query->whereKey($programId))
                    ->get()
                    ->map(fn (ActivityProgram $program) => [
                        'program_id' => (int) $program->id,
                        'activity' => $program->name,
                        'withdrawn' => ActivityMembership::query()->where('program_id', $program->id)->where('status', 'WITHDRAWN')->count(),
                        'suspended' => ActivityMembership::query()->where('program_id', $program->id)->where('status', 'SUSPENDED')->count(),
                        'active' => ActivityMembership::query()->where('program_id', $program->id)->where('status', 'ACTIVE')->count(),
                    ])->all(),
                'totals' => ['withdrawals' => ActivityMembership::query()->where('status', 'WITHDRAWN')->count()],
            ],
            'completion' => [
                'type' => $type,
                'columns' => ['activity', 'completed', 'eligible', 'certificate_ready'],
                'rows' => ActivityProgram::query()
                    ->when($programId !== null, fn ($query) => $query->whereKey($programId))
                    ->get()
                    ->map(fn (ActivityProgram $program) => [
                        'program_id' => (int) $program->id,
                        'activity' => $program->name,
                        'completed' => ActivityCompletion::query()->where('program_id', $program->id)->where('status', CompletionStatus::COMPLETED->value)->count(),
                        'eligible' => ActivityCompletion::query()->where('program_id', $program->id)->where('status', CompletionStatus::ELIGIBLE->value)->count(),
                        'certificate_ready' => ActivityCompletion::query()->where('program_id', $program->id)->where('certificate_eligible', true)->count(),
                    ])->all(),
                'totals' => ['completions' => ActivityCompletion::query()->where('status', CompletionStatus::COMPLETED->value)->count()],
            ],
            'competition' => [
                'type' => $type,
                'columns' => ['competition', 'level', 'participants', 'status', 'published'],
                'rows' => ActivityCompetition::query()
                    ->withCount('participants')
                    ->when(isset($filters['level']), fn ($query) => $query->where('level', $filters['level']))
                    ->orderByDesc('starts_on')
                    ->get()
                    ->map(fn (ActivityCompetition $competition) => [
                        'competition_id' => (int) $competition->id,
                        'competition' => $competition->name,
                        'level' => $competition->level?->label(),
                        'participants' => (int) $competition->participants_count,
                        'status' => $competition->status?->label(),
                        'published' => (bool) $competition->results_published,
                    ])->all(),
                'totals' => ['competitions' => ActivityCompetition::query()->count()],
            ],
            'waitlist' => [
                'type' => $type,
                'columns' => ['activity', 'waiting', 'promoted'],
                'rows' => ActivityProgram::query()
                    ->get()
                    ->map(fn (ActivityProgram $program) => [
                        'program_id' => (int) $program->id,
                        'activity' => $program->name,
                        'waiting' => DB::table('activity_waitlist_entries')->where('program_id', $program->id)->where('status', 'WAITING')->count(),
                        'promoted' => DB::table('activity_waitlist_entries')->where('program_id', $program->id)->where('status', 'PROMOTED')->count(),
                    ])->filter(fn (array $row) => $row['waiting'] + $row['promoted'] > 0)->values()->all(),
                'totals' => ['waiting' => DB::table('activity_waitlist_entries')->where('status', 'WAITING')->count()],
            ],
            'coach_performance' => [
                'type' => $type,
                'columns' => ['activity', 'coach', 'sessions', 'attendance_fill_rate', 'members'],
                'rows' => ActivityProgram::query()
                    ->with('coach:id,full_name')
                    ->get()
                    ->map(function (ActivityProgram $program) {
                        $sessions = ActivitySession::query()->where('program_id', $program->id)->where('status', '!=', 'CANCELLED')->count();
                        $records = ActivityAttendance::query()->whereHas('session', fn ($query) => $query->where('program_id', $program->id))->count();
                        $expected = $sessions * max(1, ActivityMembership::query()->where('program_id', $program->id)->whereIn('status', ['ACTIVE', 'SUSPENDED'])->count());

                        return [
                            'program_id' => (int) $program->id,
                            'activity' => $program->name,
                            'coach' => $program->coach?->full_name,
                            'sessions' => $sessions,
                            'attendance_fill_rate' => $expected === 0 ? null : round($records / $expected * 100, 1),
                            'members' => ActivityMembership::query()->where('program_id', $program->id)->whereIn('status', ['ACTIVE', 'SUSPENDED'])->count(),
                        ];
                    })->all(),
                'totals' => ['sessions' => ActivitySession::query()->count()],
            ],
            'membership' => [
                'type' => $type,
                'columns' => ['activity', 'active', 'suspended', 'inactive', 'completed', 'withdrawn'],
                'rows' => ActivityProgram::query()
                    ->when($programId !== null, fn ($query) => $query->whereKey($programId))
                    ->get()
                    ->map(function (ActivityProgram $program) {
                        // Status tersimpan sebagai UPPER_SNAKE di basis data, sedangkan
                        // tabel laporan memakai kolom tetap. Tanpa pemetaan ini, kolom
                        // akan kosong dan angka keanggotaan tidak terlihat — kesalahan
                        // yang sulit terlihat karena tabelnya tetap "rapi".
                        $counts = ActivityMembership::query()
                            ->selectRaw('status, count(*) as total')
                            ->where('program_id', $program->id)
                            ->groupBy('status')
                            ->pluck('total', 'status')
                            ->all();

                        $row = [
                            'program_id' => (int) $program->id,
                            'activity' => $program->name,
                        ];

                        foreach ([
                            'active' => MembershipStatus::ACTIVE,
                            'suspended' => MembershipStatus::SUSPENDED,
                            'inactive' => MembershipStatus::INACTIVE,
                            'completed' => MembershipStatus::COMPLETED,
                            'withdrawn' => MembershipStatus::WITHDRAWN,
                        ] as $column => $status) {
                            $row[$column] = (int) ($counts[$status->value] ?? 0);
                        }

                        return $row;
                    })
                    ->all(),
                'totals' => ['memberships' => ActivityMembership::query()->count()],
            ],
            default => [
                'type' => 'unknown',
                'columns' => [],
                'rows' => [],
                'totals' => [],
                'available' => [
                    'participation', 'membership', 'attendance', 'achievements', 'competition', 'certificates',
                    'coach_performance', 'capacity', 'waitlist', 'dropout', 'completion',
                ],
            ],
        };
    }

    /** Ringkasan kesehatan modul untuk layar pengaturan. */
    public function health(): array
    {
        return [
            'programs' => ActivityProgram::query()->count(),
            'published_programs' => ActivityProgram::query()->whereIn('status', [ProgramStatus::PUBLISHED->value, ProgramStatus::ACTIVE->value])->count(),
            'memberships' => ActivityMembership::query()->count(),
            'sessions' => ActivitySession::query()->count(),
            'attendance_records' => ActivityAttendance::query()->count(),
            'assessments' => ActivityAssessment::query()->count(),
            'competitions' => ActivityCompetition::query()->count(),
            'achievements' => ActivityAchievement::query()->count(),
            'certificates' => ActivityCertificate::query()->where('status', 'ISSUED')->count(),
            'sessions_without_attendance' => ActivitySession::query()
                ->where('date', '<', now()->toDateString())
                ->where('status', '!=', SessionStatus::CANCELLED->value)
                ->whereDoesntHave('attendance')
                ->count(),
        ];
    }

    protected function attendanceTotals(?int $periodId): array
    {
        $records = ActivityAttendance::query()
            ->when($periodId !== null, fn ($query) => $query->whereHas('session', fn ($session) => $session->where('period_id', $periodId)))
            ->get(['status']);

        $total = $records->count();
        $present = $records->whereIn('status', ['PRESENT', 'LATE'])->count();

        return [
            'records' => $total,
            'present' => $present,
            'percentage' => $total === 0 ? null : round($present / $total * 100, 1),
        ];
    }

    protected function sessionsOn(string $date)
    {
        return ActivitySession::query()
            ->where('date', $date)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->get();
    }

    protected function sessionsForStudent(int $studentId, string $date): array
    {
        return ActivitySession::query()
            ->with('program:id,name')
            ->where('date', $date)
            ->whereIn('program_id', ActivityMembership::query()
                ->where('student_id', $studentId)
                ->whereIn('status', ['ACTIVE', 'SUSPENDED'])
                ->pluck('program_id'))
            ->orderBy('start_time')
            ->get()
            ->map(fn (ActivitySession $session) => [
                'id' => (int) $session->id,
                'program' => $session->program?->name,
                'start_time' => substr((string) $session->start_time, 0, 5),
                'end_time' => substr((string) $session->end_time, 0, 5),
                'topic' => $session->topic,
            ])
            ->all();
    }

    /** @return list<array> program yang mendekati/melewati kapasitas dan yang punya daftar tunggu */
    protected function capacityAlerts(?int $periodId): array
    {
        return ActivityProgram::query()
            ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
            ->whereNotNull('maximum_capacity')
            ->get()
            ->map(function (ActivityProgram $program) {
                $active = ActivityMembership::query()
                    ->where('program_id', $program->id)
                    ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                    ->count();

                $waiting = DB::table('activity_waitlist_entries')
                    ->where('program_id', $program->id)
                    ->where('status', 'WAITING')
                    ->count();

                return [
                    'program_id' => (int) $program->id,
                    'activity' => $program->name,
                    'capacity' => (int) $program->maximum_capacity,
                    'active' => $active,
                    'waitlist' => $waiting,
                    'seats_left' => max(0, (int) $program->maximum_capacity - $active),
                    'fill_rate' => round($active / max(1, (int) $program->maximum_capacity) * 100, 1),
                ];
            })
            ->filter(fn (array $row) => $row['fill_rate'] >= 80 || $row['waitlist'] > 0)
            ->sortByDesc('fill_rate')
            ->values()
            ->all();
    }

    /** @param list<int> $programIds */
    protected function attendanceCompletion(array $programIds): ?float
    {
        $sessions = ActivitySession::query()
            ->whereIn('program_id', $programIds)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->count();

        $members = ActivityMembership::query()
            ->whereIn('program_id', $programIds)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->count();

        if ($sessions === 0 || $members === 0) {
            return null;
        }

        $records = ActivityAttendance::query()
            ->whereHas('session', fn ($query) => $query->whereIn('program_id', $programIds))
            ->count();

        return round($records / ($sessions * $members) * 100, 1);
    }
}
