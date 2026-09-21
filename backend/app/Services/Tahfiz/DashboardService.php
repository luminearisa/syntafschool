<?php

namespace App\Services\Tahfiz;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\TahfizEnrollment;
use App\Models\TahfizExam;
use App\Models\TahfizHalaqah;
use App\Models\TahfizIntervention;
use App\Models\TahfizMemorizationRecord;
use App\Models\TahfizNotification;
use App\Models\TahfizParticipant;
use App\Models\TahfizParticipantProgress;
use App\Models\TahfizSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lima dashboard modul Tahfiz: peserta, guru, musyrif, admin, wali.
 *
 * Cakupan data ditentukan oleh peran pengguna, bukan oleh parameter klien.
 * Satu-satunya pengecualian adalah dashboard wali, yang memilih eksplisit
 * antara dirinya sendiri sebagai peserta ("Saya sebagai Peserta") dan salah
 * satu anaknya ("Anak Saya") — keduanya tidak pernah dicampur dalam satu
 * tanggapan.
 */
class DashboardService
{
    public function __construct(protected ProgressService $progress)
    {
    }

    /**
     * Dashboard admin — gambaran seluruh modul.
     */
    public function admin(): array
    {
        $activeParticipants = TahfizParticipant::query()->where('status', TahfizParticipant::STATUS_ACTIVE)->count();

        $enrollmentBreakdown = TahfizEnrollment::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $monthStart = now()->startOfMonth()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();

        $totalAyahs = TahfizParticipantProgress::query()->sum('ayahs_memorized');
        $totalMutqin = TahfizParticipantProgress::query()->sum('ayahs_mutqin');
        $juzCompleted = TahfizParticipantProgress::query()->whereColumn('ayahs_memorized', '>=', 'total_ayahs')->count();

        return [
            'participants' => [
                'active' => $activeParticipants,
                'total' => TahfizParticipant::query()->count(),
            ],
            'enrollments' => [
                'breakdown' => $enrollmentBreakdown,
                'active' => (int) ($enrollmentBreakdown[TahfizEnrollment::STATUS_ACTIVE] ?? 0),
            ],
            'halaqahs' => [
                'active' => TahfizHalaqah::query()->active()->count(),
                'total' => TahfizHalaqah::query()->count(),
            ],
            'memorization' => [
                'ayahs_memorized' => (int) $totalAyahs,
                'ayahs_mutqin' => (int) $totalMutqin,
                'juz_completed' => $juzCompleted,
                'mutqin_percent' => $totalAyahs > 0 ? round(($totalMutqin / $totalAyahs) * 100, 1) : 0.0,
            ],
            'setoran' => [
                'this_week' => TahfizMemorizationRecord::query()
                    ->whereDate('date', '>=', $weekStart)->count(),
                'this_month' => TahfizMemorizationRecord::query()
                    ->whereDate('date', '>=', $monthStart)->count(),
                'pending_review' => TahfizMemorizationRecord::query()
                    ->pendingReview()->count(),
            ],
            'exams' => [
                'upcoming' => TahfizExam::query()
                    ->whereIn('status', [TahfizExam::STATUS_SCHEDULED, TahfizExam::STATUS_ONGOING])
                    ->count(),
                'done' => TahfizExam::query()->where('status', TahfizExam::STATUS_DONE)->count(),
            ],
            'interventions' => [
                'open' => TahfizIntervention::query()->open()->count(),
                'overdue' => TahfizIntervention::query()
                    ->whereNull('completed_at')
                    ->whereDate('due_date', '<', now()->toDateString())
                    ->count(),
            ],
            'notifications_unread' => TahfizNotification::query()->whereNull('read_at')->count(),
        ];
    }

    /**
     * Dashboard guru/instruktur — halaqah yang diajar pengguna ini.
     */
    public function instructor(?User $user): array
    {
        $employeeId = $this->employeeId($user);
        $halaqahs = $this->halaqahsFor($employeeId, 'instructor_id');

        return $this->teachingDashboard($halaqahs, $employeeId);
    }

    /**
     * Dashboard musyrif — halaqah yang dipantau pengguna ini.
     */
    public function musyrif(?User $user): array
    {
        $employeeId = $this->employeeId($user);
        $halaqahs = $this->halaqahsFor($employeeId, 'musyrif_id');

        return $this->teachingDashboard($halaqahs, $employeeId);
    }

    /**
     * Dashboard peserta — kemajuan pribadi pengguna sendiri.
     */
    public function participant(?User $user): array
    {
        $participant = $this->participantOf($user);
        if ($participant === null) {
            return ['is_participant' => false];
        }

        $today = now()->toDateString();

        return [
            'is_participant' => true,
            'participant' => [
                'id' => $participant->id,
                'name' => $participant->name,
                'participant_type' => $participant->participant_type,
            ],
            'progress' => $this->progressSummary($participant->id),
            'target' => $this->progress->targetProgress($participant->id, $this->activeEnrollmentId($participant->id)),
            'recent_setoran' => TahfizMemorizationRecord::query()
                ->where('participant_id', $participant->id)
                ->with('surah')
                ->orderByDesc('date')
                ->limit(5)
                ->get(),
            'pending_review' => TahfizMemorizationRecord::query()
                ->where('participant_id', $participant->id)
                ->pendingReview()
                ->count(),
            'today_sessions' => $this->todaySessions($participant->id, $today),
            'notifications_unread' => TahfizNotification::query()
                ->whereNull('read_at')
                ->where('user_id', $user?->id)
                ->count(),
        ];
    }

    /**
     * Dashboard wali — "Saya sebagai Peserta" atau "Anak Saya".
     *
     * Kedua tampilan dipisah lengkap: `view=self` hanya memuat data peserta
     * milik pengguna sendiri, `view=child` hanya memuat data satu anak yang
     * dipilih dan diverifikasi sebagai anak wali ini.
     */
    public function parent(?User $user, string $view, ?int $childId): array
    {
        $childIds = $this->childParticipantIds($user);

        if ($view === 'child') {
            if ($childId === null || ! $childIds->contains($childId)) {
                return [
                    'view' => 'child',
                    'available_children' => $this->childList($childIds),
                    'error' => 'Anak tidak ditemukan atau bukan anak Anda.',
                ];
            }

            return [
                'view' => 'child',
                'child' => $this->participantDashboard($childId, $user?->id),
                'available_children' => $this->childList($childIds),
            ];
        }

        $self = $this->participantOf($user);

        return [
            'view' => 'self',
            'self' => $self !== null ? $this->participantDashboard($self->id, $user?->id) : null,
            'available_children' => $this->childList($childIds),
        ];
    }

    // ------------------------------------------------------------------
    // Pembantu cakupan
    // ------------------------------------------------------------------

    protected function teachingDashboard(Collection $halaqahs, ?int $employeeId): array
    {
        $halaqahIds = $halaqahs->pluck('id')->all();
        $today = now()->toDateString();

        $participantIds = $halaqahs->isEmpty()
            ? []
            : TahfizEnrollment::query()
                ->whereIn('halaqah_id', $halaqahIds)
                ->whereIn('status', [TahfizEnrollment::STATUS_ACTIVE, TahfizEnrollment::STATUS_APPROVED])
                ->pluck('participant_id')
                ->unique()
                ->all();

        return [
            'halaqahs' => $halaqahs->map(fn (TahfizHalaqah $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'code' => $h->code,
                'active_members' => $h->activeMembersCount(),
                'capacity' => $h->capacity ?? null,
            ]),
            'participants_count' => count($participantIds),
            'today_sessions' => $halaqahs->isEmpty()
                ? []
                : TahfizSession::query()
                    ->whereIn('halaqah_id', $halaqahIds)
                    ->whereDate('date', $today)
                    ->with('halaqah')
                    ->orderBy('start_time')
                    ->get(),
            'pending_review' => $halaqahs->isEmpty()
                ? 0
                : TahfizMemorizationRecord::query()
                    ->whereIn('participant_id', $participantIds)
                    ->pendingReview()
                    ->count(),
            'recent_setoran' => $halaqahs->isEmpty()
                ? collect()
                : TahfizMemorizationRecord::query()
                    ->whereIn('participant_id', $participantIds)
                    ->with(['participant', 'surah'])
                    ->orderByDesc('date')
                    ->limit(10)
                    ->get(),
            'progress' => $halaqahs->isEmpty()
                ? ['avg_memorized_percent' => 0.0, 'avg_mutqin_percent' => 0.0]
                : $this->groupProgress($participantIds),
            'interventions_open' => $halaqahs->isEmpty()
                ? 0
                : TahfizIntervention::query()
                    ->whereIn('participant_id', $participantIds)
                    ->open()
                    ->count(),
        ];
    }

    /**
     * Dashboard peserta tunggal — dipakai oleh dashboard peserta maupun
     * tampilan "Anak Saya" pada dashboard wali.
     */
    protected function participantDashboard(int $participantId, ?int $userId): array
    {
        $participant = TahfizParticipant::query()->find($participantId);

        return [
            'participant' => $participant !== null ? [
                'id' => $participant->id,
                'name' => $participant->name,
                'participant_type' => $participant->participant_type,
            ] : null,
            'progress' => $this->progressSummary($participantId),
            'target' => $this->progress->targetProgress($participantId, $this->activeEnrollmentId($participantId)),
            'recent_setoran' => TahfizMemorizationRecord::query()
                ->where('participant_id', $participantId)
                ->with('surah')
                ->orderByDesc('date')
                ->limit(5)
                ->get(),
            'pending_review' => TahfizMemorizationRecord::query()
                ->where('participant_id', $participantId)
                ->pendingReview()
                ->count(),
            'today_sessions' => $this->todaySessions($participantId, now()->toDateString()),
            'notifications_unread' => TahfizNotification::query()
                ->whereNull('read_at')
                ->where('user_id', $userId)
                ->count(),
        ];
    }

    protected function progressSummary(int $participantId): array
    {
        $map = $this->progress->memorizationMap($participantId);

        return [
            'total_ayahs' => $map['total_ayahs'] ?? 0,
            'total_pages' => $map['total_pages'] ?? 0,
            'ayahs_memorized' => $map['ayahs_memorized'] ?? 0,
            'ayahs_mutqin' => $map['ayahs_mutqin'] ?? 0,
            'memorized_percent' => $map['memorized_percent'] ?? 0.0,
            'mutqin_percent' => $map['mutqin_percent'] ?? 0.0,
            // `juz_memorized` = juz yang BENAR-bENAR selesai (semua surah
            // awalnya lengkap); `juz` di map hanya rekap juz yang disentuh.
            'juz_completed' => $map['juz_memorized'] ?? 0,
            'surahs_touched' => $map['surahs_memorized'] ?? 0,
        ];
    }

    protected function groupProgress(array $participantIds): array
    {
        if ($participantIds === []) {
            return ['avg_memorized_percent' => 0.0, 'avg_mutqin_percent' => 0.0];
        }

        $rows = TahfizParticipantProgress::query()
            ->whereIn('participant_id', $participantIds)
            ->selectRaw('
                AVG(CASE WHEN total_ayahs > 0 THEN (ayahs_memorized * 100.0) / total_ayahs ELSE 0 END) as avg_memorized,
                AVG(CASE WHEN total_ayahs > 0 THEN (ayahs_mutqin * 100.0) / total_ayahs ELSE 0 END) as avg_mutqin
            ')
            ->first();

        return [
            'avg_memorized_percent' => round((float) ($rows->avg_memorized ?? 0), 1),
            'avg_mutqin_percent' => round((float) ($rows->avg_mutqin ?? 0), 1),
        ];
    }

    protected function todaySessions(int $participantId, string $date)
    {
        $enrollment = TahfizEnrollment::query()
            ->where('participant_id', $participantId)
            ->whereIn('status', [TahfizEnrollment::STATUS_ACTIVE, TahfizEnrollment::STATUS_APPROVED])
            ->latest('id')
            ->first();

        if ($enrollment === null || $enrollment->halaqah_id === null) {
            return collect();
        }

        return TahfizSession::query()
            ->where('halaqah_id', $enrollment->halaqah_id)
            ->whereDate('date', $date)
            ->with('halaqah')
            ->orderBy('start_time')
            ->get();
    }

    protected function activeEnrollmentId(int $participantId): ?int
    {
        return (int) (TahfizEnrollment::query()
            ->where('participant_id', $participantId)
            ->whereIn('status', [TahfizEnrollment::STATUS_ACTIVE, TahfizEnrollment::STATUS_APPROVED])
            ->latest('id')
            ->value('id') ?? 0) ?: null;
    }

    protected function halaqahsFor(?int $employeeId, string $column): Collection
    {
        if ($employeeId === null) {
            return TahfizHalaqah::query()->whereRaw('1 = 0')->get();
        }

        return TahfizHalaqah::query()
            ->where($column, $employeeId)
            ->active()
            ->with(['program', 'level'])
            ->orderBy('name')
            ->get();
    }

    protected function employeeId(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        if ($user->userable instanceof Employee) {
            return $user->userable->id;
        }

        // Cari lewat profil instruktur bila pengguna tidak terikat langsung.
        return (int) (Employee::query()->where('user_id', $user->id)->value('id') ?? 0) ?: null;
    }

    protected function participantOf(?User $user): ?TahfizParticipant
    {
        if ($user === null) {
            return null;
        }

        return TahfizParticipant::query()->where('user_id', $user->id)->first();
    }

    /**
     * Id peserta untuk anak-anak wali yang merupakan pengguna ini.
     */
    protected function childParticipantIds(?User $user)
    {
        if ($user === null) {
            return collect();
        }

        $guardian = Guardian::query()->where('user_id', $user->id)->first();
        if ($guardian === null) {
            return collect();
        }

        $studentIds = $guardian->students()->pluck('students.id');
        if ($studentIds->isEmpty()) {
            return collect();
        }

        return TahfizParticipant::query()
            ->where('participant_type', TahfizParticipant::TYPE_STUDENT)
            ->whereIn('student_id', $studentIds->all())
            ->pluck('id');
    }

    protected function childList($childIds)
    {
        if ($childIds->isEmpty()) {
            return [];
        }

        return TahfizParticipant::query()
            ->whereIn('id', $childIds->all())
            ->select(['id', 'name', 'participant_type'])
            ->orderBy('name')
            ->get();
    }
}
