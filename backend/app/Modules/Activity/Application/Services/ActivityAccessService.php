<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Activity\Domain\Contracts\ParentProviderInterface;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Enums\RegistrationStatus;
use App\Modules\Activity\Models\ActivityCompetition;
use App\Modules\Activity\Models\ActivityCompetitionJudge;
use App\Modules\Activity\Models\ActivityCompetitionMatch;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistration;
use App\Modules\Activity\Models\ActivitySession;

/**
 * Cakupan baris — siapa boleh melihat dan bertindak atas apa (§88).
 *
 * Middleware izin hanya menentukan *layar*. Berkas ini menentukan *barisnya*:
 * pelatih Futsal tidak otomatis melihat anggota Robotics; orang tua A tidak
 * melihat anak B; juri hanya melihat penampilan yang ditugaskan kepadanya.
 *
 * Prinsipnya: **menutup lebih baik daripada membuka**. Bila tautan antara akun
 * dan data tidak dapat dibuktikan, jawabannya tidak.
 */
class ActivityAccessService
{
    public function __construct(private readonly ParentProviderInterface $parents) {}

    // ------------------------------------------------------------- identitas

    /** Id siswa bila akun ini adalah siswa. */
    public function studentIdForUser(?User $user): ?int
    {
        if ($user === null || $user->userable_type !== (new Student)->getMorphClass()) {
            return null;
        }

        return $user->userable_id === null ? null : (int) $user->userable_id;
    }

    /** Id pegawai bila akun ini adalah pegawai/guru. */
    public function employeeIdForUser(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        if ($user->userable_type === (new Employee)->getMorphClass() && $user->userable_id !== null) {
            return (int) $user->userable_id;
        }

        $employeeId = Employee::query()->where('email', $user->email)->value('id');

        return $employeeId === null ? null : (int) $employeeId;
    }

    /** @return list<int> id siswa yang boleh dilihat pemilik akun (anak sendiri untuk orang tua). */
    public function childStudentIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $guardianId = $user->userable_type === (new Guardian)->getMorphClass() && $user->userable_id !== null
            ? (int) $user->userable_id
            : null;

        if ($guardianId !== null) {
            return \App\Models\StudentGuardian::query()
                ->where('guardian_id', $guardianId)
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $this->parents->childrenOfUser((int) $user->id);
    }

    // ------------------------------------------------------------- program

    /** @return list<int> id program yang berada dalam tanggung jawab pegawai ini */
    public function coachedProgramIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $employeeId = $this->employeeIdForUser($user);

        return ActivityProgram::query()
            ->where(function ($query) use ($user, $employeeId) {
                $query->where('coordinator_user_id', $user->id);

                if ($employeeId !== null) {
                    $query->orWhere('advisor_employee_id', $employeeId)
                        ->orWhere('coach_employee_id', $employeeId)
                        ->orWhereHas('coaches', fn ($coach) => $coach->where('employee_id', $employeeId)->where('is_active', true));
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function managesProgram(?User $user, ActivityProgram $program): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->can('activity.program.manage')) {
            return true;
        }

        return in_array((int) $program->id, $this->coachedProgramIds($user), true);
    }

    public function canViewProgram(?User $user, ActivityProgram $program): bool
    {
        if ($user === null) {
            return false;
        }

        if ($program->status?->value === 'PUBLISHED' || $program->status?->value === 'ACTIVE') {
            // Katalog memang untuk dibaca — bila sekolah belum menerbitkannya,
            // hanya pengelola yang boleh melihat.
            return true;
        }

        return $user->can('activity.program.manage') || $this->managesProgram($user, $program);
    }

    // ------------------------------------------------------------- siswa

    public function canViewStudent(?User $user, int $studentId): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->studentIdForUser($user) === $studentId) {
            return true;
        }

        if (in_array($studentId, $this->childStudentIds($user), true)) {
            return true;
        }

        if ($user->can('activity.membership.view') || $user->can('activity.report.view')) {
            return true;
        }

        $programIds = $this->coachedProgramIds($user);

        if ($programIds === []) {
            return false;
        }

        return ActivityMembership::query()
            ->where('student_id', $studentId)
            ->whereIn('program_id', $programIds)
            ->exists();
    }

    /** @return list<int> id siswa dalam cakupan pemilik akun (untuk kotak masuk & dasbor). */
    public function visibleStudentIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $own = [];
        $studentId = $this->studentIdForUser($user);

        if ($studentId !== null) {
            $own[] = $studentId;
        }

        $own = array_merge($own, $this->childStudentIds($user));

        if ($own !== []) {
            return array_values(array_unique($own));
        }

        if ($user->can('activity.membership.view')) {
            return [];
        }

        $programIds = $this->coachedProgramIds($user);

        return ActivityMembership::query()
            ->whereIn('program_id', $programIds)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    // ------------------------------------------------------- keputusan & tugas

    public function canDecideRegistration(?User $user, ActivityRegistration $registration): bool
    {
        if ($user === null || ! $user->can('activity.registration.decide')) {
            return false;
        }

        if ($user->can('activity.program.manage')) {
            return true;
        }

        $program = $registration->program;

        return $program !== null && $this->managesProgram($user, $program);
    }

    public function canRecordAttendance(?User $user, ActivitySession $session): bool
    {
        if ($user === null || ! $user->can('activity.attendance.record')) {
            return false;
        }

        if ($user->can('activity.program.manage')) {
            return true;
        }

        $program = $session->program;

        return $program !== null && $this->managesProgram($user, $program);
    }

    public function canAssessProgram(?User $user, ActivityProgram $program): bool
    {
        if ($user === null || ! $user->can('activity.assessment.manage')) {
            return false;
        }

        return $user->can('activity.program.manage') || $this->managesProgram($user, $program);
    }

    /** Pencatatan juri untuk pengguna ini, bila ada. */
    public function judgeFor(?User $user, ActivityCompetition $competition): ?ActivityCompetitionJudge
    {
        if ($user === null) {
            return null;
        }

        return ActivityCompetitionJudge::query()
            ->where('competition_id', $competition->id)
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->first();
    }

    /** Apakah juri ini boleh menilai pertandingan tersebut. */
    public function canScoreMatch(?User $user, ActivityCompetitionMatch $match): bool
    {
        $judge = $this->judgeFor($user, $match->competition);

        if ($judge === null) {
            return false;
        }

        if ($judge->round_id !== null && (int) $judge->round_id !== (int) $match->round_id) {
            return false;
        }

        $round = $match->round;

        if ($judge->category_id !== null && $round !== null && (int) $judge->category_id !== (int) $round->category_id) {
            return false;
        }

        return true;
    }

    /** Registrasi yang masih menunggu keputusan pemilik akun. */
    public function pendingDecisionCount(?User $user): int
    {
        if ($user === null || ! $user->can('activity.registration.decide')) {
            return 0;
        }

        $programIds = $user->can('activity.program.manage')
            ? ActivityProgram::query()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->coachedProgramIds($user);

        if ($programIds === []) {
            return 0;
        }

        return ActivityRegistration::query()
            ->whereIn('program_id', $programIds)
            ->whereIn('status', [
                RegistrationStatus::SUBMITTED->value,
                RegistrationStatus::UNDER_REVIEW->value,
                RegistrationStatus::SELECTED->value,
                RegistrationStatus::WAITLISTED->value,
            ])
            ->count();
    }

    /** Keanggotaan siswa pada program (dipakai banyak layanan). */
    public function membershipOf(int $studentId, int $programId, ?int $periodId = null): ?ActivityMembership
    {
        return ActivityMembership::query()
            ->where('student_id', $studentId)
            ->where('program_id', $programId)
            ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->latest('id')
            ->first();
    }
}
