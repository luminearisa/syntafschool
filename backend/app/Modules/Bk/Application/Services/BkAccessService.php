<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Contracts\ParentProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Models\BkAppointment;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseDocument;
use App\Modules\Bk\Models\BkCaseNote;
use App\Modules\Bk\Models\BkCaseParticipant;
use App\Modules\Bk\Models\BkCounselingSession;
use App\Modules\Bk\Models\BkCounselor;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkReferral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Cakupan data & kerahasiaan Modul BK — jantung privasi modul ini.
 *
 * Semua keputusan **server-side**; frontend hanya menyembunyikan apa yang sudah
 * ditolak backend. Aturan pokoknya:
 *
 * | Peran                            | Boleh melihat                                             |
 * |----------------------------------|-----------------------------------------------------------|
 * | `BK_ADMIN`                       | seluruh kasus, termasuk catatan rahasia (jejak audit dicatat) |
 * | `BK_COORDINATOR`                 | seluruh kasus, seluruh catatan konseling dalam lingkup BK |
 * | `COUNSELOR`                      | hanya kasus yang ditugaskan/ia terlibat di dalamnya       |
 * | wali kelas / guru / pengasuh     | hanya **bagian kasus** yang dibagikan (`bk_case_participants`) dan hanya sampai level `STAFF_ONLY` |
 * | siswa                            | dirinya sendiri, hanya yang dibagikan (tanpa catatan)     |
 * | orang tua/wali                   | anaknya saja, hanya yang dibagikan (tanpa catatan)        |
 *
 * Guru **tidak** memperoleh akses ke catatan konseling hanya karena ia wali
 * kelas: catatan konseling bukan informasi operasional pengajaran.
 */
class BkAccessService
{
    public function __construct(
        private readonly ParentProviderInterface $parents,
        private readonly StudentProviderInterface $students,
        private readonly BkAuditService $audit,
    ) {
    }

    // ------------------------------------------------------------- identitas peran

    /** Profil konselor aktif milik pengguna (bila ada). */
    public function counselorFor(User $user): ?BkCounselor
    {
        return BkCounselor::query()->where('user_id', $user->id)->where('status', 'ACTIVE')->first();
    }

    public function isCounselor(User $user): bool
    {
        return $this->counselorFor($user) !== null
            || $user->hasAnyRole(['BK_ADMIN', 'BK_COORDINATOR', 'COUNSELOR']);
    }

    public function isBkAdmin(User $user): bool
    {
        return $user->hasAnyRole(['BK_ADMIN', 'super-admin', 'admin-sekolah'])
            && $user->hasAnyPermission(['bk.case.view.all', 'bk.manage']);
    }

    public function isCoordinator(User $user): bool
    {
        return $user->hasRole('BK_COORDINATOR') || $user->hasPermissionTo('bk.case.assign');
    }

    public function isBkStaff(User $user): bool
    {
        return $this->isCoordinator($user)
            || $this->isBkAdmin($user)
            || $this->isCounselor($user)
            || $user->hasAnyPermission(['bk.case.view', 'bk.referral.receive']);
    }

    /** Guru/wali kelas/pengasuh — boleh merujuk, tidak otomatis boleh membaca. */
    public function isReferrerOnly(User $user): bool
    {
        return ! $this->isBkStaff($user)
            && $user->hasAnyPermission(['bk.referral.create', 'bk.concern.create']);
    }

    /** Akun siswa (memiliki data siswa tertaut). */
    public function studentIdForUser(User $user): ?int
    {
        $type = $user->userable_type;

        if ($type === null || ! str_contains((string) $type, 'Student')) {
            return null;
        }

        return $user->userable_id === null ? null : (int) $user->userable_id;
    }

    /** @return list<int> */
    public function guardianStudentIds(User $user): array
    {
        return $this->parents->studentIdsForGuardianUser($user->id);
    }

    public function isGuardianOf(User $user, int $studentId): bool
    {
        return $this->parents->isGuardianOf($user->id, $studentId);
    }

    // ------------------------------------------------------------- level kerahasiaan

    /**
     * Level kerahasiaan tertinggi yang boleh dibuka pengguna ini untuk kasus itu.
     *
     * Inilah satu-satunya tempat yang menentukan "boleh membaca catatan
     * konseling atau tidak"; seluruh layanan lain memanggilnya alih-alih
     * memeriksa peran sendiri-sendiri.
     */
    public function visibleConfidentiality(User $user, BkCase $case): ConfidentialityLevel
    {
        if ($this->isBkAdmin($user) || $user->hasPermissionTo('bk.confidential.view')) {
            return ConfidentialityLevel::HIGHLY_CONFIDENTIAL;
        }

        if ($this->isCoordinator($user)) {
            return ConfidentialityLevel::HIGHLY_CONFIDENTIAL;
        }

        if ($this->isAssignedCounselor($user, $case)) {
            return ConfidentialityLevel::HIGHLY_CONFIDENTIAL;
        }

        // Pihak yang diberi akses sebagian (wali kelas, pengasuh, siswa, wali).
        $participant = $this->participantRow($user, $case);

        if ($participant !== null) {
            return $participant->max_confidentiality
                ?? ConfidentialityLevel::STAFF_ONLY;
        }

        return ConfidentialityLevel::PUBLIC_INTERNAL;
    }

    /** Konselor penanggung jawab (atau pendamping) pada kasus ini. */
    public function isAssignedCounselor(User $user, BkCase $case): bool
    {
        if ((int) $case->assigned_counselor_id === (int) $user->id) {
            return true;
        }

        return $case->assignments()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    /** @return list<int> */
    public function counselorCaseIds(User $user): array
    {
        return BkCase::query()
            ->where(function (Builder $query) use ($user): void {
                $query->where('assigned_counselor_id', $user->id)
                    ->orWhereHas('assignments', fn (Builder $q) => $q->where('user_id', $user->id)->where('is_active', true));
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    // ------------------------------------------------------------- keputusan akses

    /** Boleh membuka kasus (header-nya) sama sekali? */
    public function canViewCase(User $user, BkCase $case): bool
    {
        if ($this->isBkAdmin($user) || $this->isCoordinator($user) || $this->isAssignedCounselor($user, $case)) {
            return true;
        }

        $participant = $this->participantRow($user, $case);

        if ($participant !== null && $participant->can_view_case) {
            return true;
        }

        // Guru pengajar/wali kelas tanpa penugasan eksplisit: tidak melihat kasus.
        return false;
    }

    /**
     * Boleh membaca catatan konseling (sesi, catatan sensitif, dokumen rahasia)?
     *
     * Guru, siswa, dan orang tua **selalu** mendapat `false` di sini — apa pun
     * level yang tertulis pada barisnya.
     */
    public function canViewCounselingContent(User $user, BkCase $case): bool
    {
        return $this->isAssignedCounselor($user, $case)
            || $this->isCoordinator($user)
            || $this->isBkAdmin($user)
            || $user->hasPermissionTo('bk.note.view.confidential');
    }

    public function canViewSession(User $user, BkCounselingSession $session): bool
    {
        if (! $this->canViewCase($user, $session->case)) {
            return false;
        }

        return $this->canViewCounselingContent($user, $session->case)
            || $session->confidentiality_level->rank() <= $this->visibleConfidentiality($user, $session->case)->rank();
    }

    public function canViewNote(User $user, BkCaseNote $note): bool
    {
        $case = $note->case;

        if ($case === null || ! $this->canViewCase($user, $case)) {
            return false;
        }

        if ($note->is_sensitive || $note->confidentiality_level->isCounselorOnly()) {
            return $this->canViewCounselingContent($user, $case);
        }

        return $note->confidentiality_level->rank() <= $this->visibleConfidentiality($user, $case)->rank();
    }

    public function canViewDocument(User $user, BkCaseDocument $document): bool
    {
        $case = $document->case;

        if ($document->is_sensitive || $document->visibility->isCounselorOnly()) {
            return $case !== null
                ? $this->canViewCounselingContent($user, $case)
                : $this->isBkAdmin($user) || $this->isCoordinator($user);
        }

        if ($case === null) {
            // Dokumen pada referral tanpa kasus: hanya staf BK.
            return $this->isBkStaff($user);
        }

        return $this->canViewCase($user, $case)
            && $document->visibility->rank() <= $this->visibleConfidentiality($user, $case)->rank();
    }

    /** Boleh melihat profil dukungan siswa (agregat lintas modul). */
    public function canViewSupportProfile(User $user, int $studentId): bool
    {
        if ($this->isBkStaff($user)) {
            return true;
        }

        if ($this->studentIdForUser($user) === $studentId) {
            return true;
        }

        if ($this->isGuardianOf($user, $studentId)) {
            return true;
        }

        // Wali kelas/pengasuh melihat profil **tanpa** bagian BK yang rahasia,
        // dan hanya bila memang mengampu siswa tersebut.
        return $this->isHomeroomOrBoardingOf($user, $studentId);
    }

    public function isHomeroomOrBoardingOf(User $user, int $studentId): bool
    {
        $snapshot = $this->students->snapshot($studentId);

        return $snapshot !== null
            && $user->hasAnyRole(['wali-kelas', 'guru', 'pengasuh-asrama', 'BOARDING_STAFF'])
            && (int) ($snapshot['homeroom_teacher_id'] ?? 0) === (int) $user->id;
    }

    // ------------------------------------------------------------- penegakan (throw)

    public function assertCanViewCase(User $user, BkCase $case, ?string $reason = null): void
    {
        if (! $this->canViewCase($user, $case)) {
            $this->audit->logAccess($user, 'VIEW', $case, false, $reason);

            throw new AccessDeniedHttpException('Anda tidak memiliki akses ke kasus ini.');
        }

        if ($case->confidentiality_level->isCounselorOnly() && ! $this->canViewCounselingContent($user, $case)) {
            $this->audit->logAccess($user, 'VIEW', $case, false, $reason);

            throw new AccessDeniedHttpException('Kasus ini bersifat konseling rahasia.');
        }

        $this->audit->logAccess($user, 'VIEW', $case, true, $reason);
    }

    public function assertCanViewReferral(User $user, BkReferral $referral): void
    {
        if ($this->canViewReferral($user, $referral)) {
            $this->audit->logAccess($user, 'VIEW', $referral, true);

            return;
        }

        $this->audit->logAccess($user, 'VIEW', $referral, false);

        throw new AccessDeniedHttpException('Anda tidak memiliki akses ke referral ini.');
    }

    public function canViewReferral(User $user, BkReferral $referral): bool
    {
        if ($this->isBkStaff($user)) {
            return true;
        }

        // Pelapor melihat status referralnya sendiri; wali melihat referral anaknya.
        if ((int) $referral->referred_by === (int) $user->id) {
            return true;
        }

        if ($this->studentIdForUser($user) === (int) $referral->student_id) {
            return true;
        }

        return $this->isGuardianOf($user, (int) $referral->student_id);
    }

    public function assertCanViewSession(User $user, BkCounselingSession $session): void
    {
        if (! $this->canViewSession($user, $session)) {
            $this->audit->logAccess($user, 'VIEW_CONFIDENTIAL', $session, false);

            throw new AccessDeniedHttpException('Catatan sesi konseling bersifat rahasia.');
        }

        // Membuka catatan konseling — walau diizinkan — selalu tercatat, sehingga
        // pertanyaan "siapa membuka catatan anak saya?" dapat dijawab.
        if ($session->confidentiality_level->isCounselorOnly() || $session->counselor_note !== null) {
            $this->audit->logAccess($user, 'VIEW_CONFIDENTIAL', $session, true);
        }
    }

    public function assertCanViewNote(User $user, BkCaseNote $note): void
    {
        if (! $this->canViewNote($user, $note)) {
            $this->audit->logAccess($user, 'VIEW_CONFIDENTIAL', $note, false);

            throw new AccessDeniedHttpException('Catatan ini bersifat rahasia.');
        }

        if ($note->confidentiality_level->isSecret()) {
            $this->audit->logAccess($user, 'VIEW_CONFIDENTIAL', $note, true);
        }
    }

    public function assertCanViewDocument(User $user, BkCaseDocument $document): void
    {
        if (! $this->canViewDocument($user, $document)) {
            $this->audit->logAccess($user, 'DOCUMENT_VIEW', $document, false);

            throw new AccessDeniedHttpException('Dokumen ini bersifat rahasia.');
        }

        $this->audit->logAccess($user, 'DOCUMENT_VIEW', $document, true);
    }

    // ------------------------------------------------------------- penyaringan kueri

    /** Batasi kueri kasus pada apa yang boleh dilihat pengguna ini. */
    public function scopeCases(Builder $query, User $user): Builder
    {
        if ($this->isBkAdmin($user) || $this->isCoordinator($user)) {
            return $query;
        }

        if ($this->isCounselor($user)) {
            return $query->where(function (Builder $inner) use ($user): void {
                $inner->where('assigned_counselor_id', $user->id)
                    ->orWhereHas('assignments', fn (Builder $q) => $q->where('user_id', $user->id)->where('is_active', true))
                    ->orWhereHas('participants', fn (Builder $q) => $q->where('user_id', $user->id)->where('can_view_case', true));
            });
        }

        $studentId = $this->studentIdForUser($user);
        $guardianIds = $this->guardianStudentIds($user);

        return $query->where(function (Builder $inner) use ($user, $studentId, $guardianIds): void {
            $inner->whereHas('participants', fn (Builder $q) => $q->where('user_id', $user->id)->where('can_view_case', true));

            if ($studentId !== null) {
                $inner->orWhere('student_id', $studentId);
            }

            if ($guardianIds !== []) {
                $inner->orWhereIn('student_id', $guardianIds);
            }
        })->where('confidentiality_level', '!=', ConfidentialityLevel::HIGHLY_CONFIDENTIAL->value);
    }

    /** Referral: pelapor melihat miliknya sendiri, wali melihat anaknya. */
    public function scopeReferrals(Builder $query, User $user): Builder
    {
        if ($this->isBkStaff($user)) {
            return $query;
        }

        $studentId = $this->studentIdForUser($user);
        $guardianIds = $this->guardianStudentIds($user);

        return $query->where(function (Builder $inner) use ($user, $studentId, $guardianIds): void {
            $inner->where('referred_by', $user->id);

            if ($studentId !== null) {
                $inner->orWhere('student_id', $studentId);
            }

            if ($guardianIds !== []) {
                $inner->orWhereIn('student_id', $guardianIds);
            }
        });
    }

    /** Janji temu: konselor melihat jadwalnya, siswa/wali melihat miliknya. */
    public function scopeAppointments(Builder $query, User $user): Builder
    {
        if ($this->isBkAdmin($user) || $this->isCoordinator($user)) {
            return $query;
        }

        $studentId = $this->studentIdForUser($user);
        $guardianIds = $this->guardianStudentIds($user);

        return $query->where(function (Builder $inner) use ($user, $studentId, $guardianIds): void {
            $inner->where('counselor_id', $user->id)->orWhere('created_by', $user->id);

            if ($studentId !== null) {
                $inner->orWhere('student_id', $studentId);
            }

            if ($guardianIds !== []) {
                $inner->orWhereIn('student_id', $guardianIds);
            }
        });
    }

    /** Tindak lanjut: hanya milik sendiri (atau seluruhnya bagi koordinator). */
    public function scopeFollowUps(Builder $query, User $user): Builder
    {
        if ($this->isBkAdmin($user) || $this->isCoordinator($user)) {
            return $query;
        }

        if ($this->isCounselor($user)) {
            return $query->where(function (Builder $inner) use ($user): void {
                $inner->where('assigned_to', $user->id)
                    ->orWhereHas('case', fn (Builder $q) => $q->where('assigned_counselor_id', $user->id));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    // ------------------------------------------------------------- bantu

    private function participantRow(User $user, BkCase $case): ?BkCaseParticipant
    {
        $studentId = $this->studentIdForUser($user);
        $guardianIds = $this->guardianStudentIds($user);

        return $case->participants()
            ->where(function (Builder $query) use ($user, $studentId, $guardianIds): void {
                $query->where('user_id', $user->id);

                if ($studentId !== null) {
                    $query->orWhere('student_id', $studentId);
                }

                if ($guardianIds !== []) {
                    $query->orWhereIn('guardian_id', $guardianIds);
                }
            })
            ->orderByDesc('can_view_case')
            ->first();
    }

    /** @return Collection<int, int> */
    public function visibleStudentIds(User $user): Collection
    {
        if ($this->isBkStaff($user)) {
            return collect();
        }

        $ids = collect();

        if (($studentId = $this->studentIdForUser($user)) !== null) {
            $ids->push($studentId);
        }

        foreach ($this->guardianStudentIds($user) as $id) {
            $ids->push($id);
        }

        return $ids->unique()->values();
    }

    /** Boleh melihat jadwal konselor lain (monitoring beban kerja)? */
    public function canMonitorWorkload(User $user): bool
    {
        return $this->isCoordinator($user) || $this->isBkAdmin($user);
    }

    /** Boleh melakukan eskalasi kasus ke manajemen/pihak luar? */
    public function canEscalate(User $user, BkCase $case): bool
    {
        return $this->isCoordinator($user) || $this->isBkAdmin($user) || $this->isAssignedCounselor($user, $case);
    }

    public function canCloseCase(User $user, BkCase $case): bool
    {
        if (! $this->canViewCase($user, $case)) {
            return false;
        }

        return ($this->isAssignedCounselor($user, $case) && $user->hasPermissionTo('bk.case.close'))
            || $this->isCoordinator($user)
            || $this->isBkAdmin($user);
    }

    public function canReopenCase(User $user, BkCase $case): bool
    {
        return $this->isCoordinator($user) || $this->isBkAdmin($user);
    }

    public function canViewFollowUp(User $user, BkFollowUp $followUp): bool
    {
        if ($this->isBkAdmin($user) || $this->isCoordinator($user)) {
            return true;
        }

        if ((int) $followUp->assigned_to === (int) $user->id) {
            return true;
        }

        return $followUp->case !== null && $this->isAssignedCounselor($user, $followUp->case);
    }

    public function canViewAppointment(User $user, BkAppointment $appointment): bool
    {
        if ($this->isBkAdmin($user) || $this->isCoordinator($user) || (int) $appointment->counselor_id === (int) $user->id) {
            return true;
        }

        if ($this->studentIdForUser($user) === (int) $appointment->student_id) {
            return true;
        }

        return $this->isGuardianOf($user, (int) $appointment->student_id);
    }

    public function canManageAppointment(User $user, BkAppointment $appointment): bool
    {
        return $this->isBkAdmin($user)
            || $this->isCoordinator($user)
            || ((int) $appointment->counselor_id === (int) $user->id && $user->hasPermissionTo('bk.appointment.manage'));
    }
}
