<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use App\Modules\Supervisi\Models\SupSupervisor;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hak akses atas satu kegiatan supervisi.
 *
 * Aturan yang ditegakkan:
 *  - Yang **disupervisi** selalu boleh melihat hasilnya sendiri.
 *  - Yang **menyuervisi** boleh mengisi, mengubah, dan (bila berwenang)
 *    memfinalkan kegiatan miliknya.
 *  - Pemegang izin tata kelola (`supervisi.manage`, `supervisi.audit.view`,
 *    `supervisi.report.view`) dapat melihat seluruh kegiatan sekolah;
 *    finalisasi oleh mereka hanya lewat izin `supervisi.visit.finalize`.
 *  - **Tidak seorang pun** boleh memfinalkan hasil penilaian dirinya sendiri.
 *  - Hasil yang sudah difinalkan bersifat beku bagi pelaksana; koreksi hanya
 *    melalui pembukaan ulang (`supervisi.visit.reopen`) yang tercatat di audit.
 */
class VisitAccessService
{
    /** Izin yang membuat seseorang melihat seluruh kegiatan sekolah. */
    public const GOVERNANCE_PERMISSIONS = [
        'supervisi.visit.view.all',
        'supervisi.manage',
        'supervisi.audit.view',
    ];

    /** ID pegawai dari akun pengguna (dua pola penautan yang dipakai aplikasi). */
    public function employeeIdFor(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        if ($user->userable instanceof Employee) {
            return (int) $user->userable->getKey();
        }

        $id = Employee::query()->whereHas('user', fn (Builder $query) => $query->whereKey($user->getKey()))->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Pengguna yang boleh melihat seluruh kegiatan sekolah. */
    public function isGovernance(User $user): bool
    {
        foreach (self::GOVERNANCE_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** Batasi query daftar kegiatan sesuai hak pengguna. */
    public function visibleQuery(User $user): Builder
    {
        $query = SupVisit::query();

        if ($this->isGovernance($user)) {
            return $query;
        }

        $employeeId = $this->employeeIdFor($user);

        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inner) use ($employeeId): void {
            $inner->where('supervisor_employee_id', $employeeId)
                ->orWhere('supervisee_employee_id', $employeeId);
        });
    }

    public function canView(User $user, SupVisit $visit): bool
    {
        if ($this->isGovernance($user)) {
            return true;
        }

        $employeeId = $this->employeeIdFor($user);

        return $employeeId !== null
            && in_array($employeeId, [(int) $visit->supervisor_employee_id, (int) $visit->supervisee_employee_id], true);
    }

    public function assertCanView(User $user, SupVisit $visit): void
    {
        if (! $this->canView($user, $visit)) {
            abort(403, 'Anda tidak terlibat pada kegiatan supervisi ini.');
        }
    }

    /**
     * Boleh mengisi jawaban/konferensi/temuan: pelaksana kegiatan.
     *
     * Kegiatan yang sudah difinalkan sengaja **tidak** ditolak di sini agar
     * pengguna menerima pesan yang tepat ("sudah difinalkan, ajukan pembukaan
     * ulang") alih-alih pesan izin; penolakannya dilakukan `assertEditable`.
     */
    public function canExecute(User $user, SupVisit $visit): bool
    {
        $employeeId = $this->employeeIdFor($user);

        if ($employeeId === null || (int) $visit->supervisor_employee_id !== $employeeId) {
            return false;
        }

        return $visit->status !== VisitStatus::CANCELLED;
    }

    public function assertCanExecute(User $user, SupVisit $visit): void
    {
        if (! $this->canExecute($user, $visit)) {
            abort(403, 'Hanya pelaksana kegiatan yang dapat mengisi kegiatan supervisi ini.');
        }
    }

    /**
     * Boleh memfinalkan nilai.
     *
     * Pelaksana kegiatan harus terdaftar di registry supervisor **dan**
     * berwenang memfinalkan (rekan sejawat/mentor tidak pernah boleh).
     */
    public function canFinalize(User $user, SupVisit $visit): bool
    {
        $employeeId = $this->employeeIdFor($user);

        if ($employeeId === null) {
            return false;
        }

        if ((int) $visit->supervisee_employee_id === $employeeId) {
            return false; // tidak boleh menilai diri sendiri
        }

        if ($visit->status === VisitStatus::CANCELLED) {
            return false;
        }

        if ((int) $visit->supervisor_employee_id !== $employeeId) {
            // Pemegang izin penuh boleh menyelesaikan kegiatan yang macet,
            // semuanya tercatat di jejak audit.
            return $user->can('supervisi.manage');
        }

        $registry = SupSupervisor::query()->where('employee_id', $employeeId)->first();

        return $registry !== null && $registry->is_active && $registry->mayFinalize();
    }

    public function assertCanFinalize(User $user, SupVisit $visit): void
    {
        if (! $this->canFinalize($user, $visit)) {
            abort(403, 'Anda tidak berwenang memfinalkan nilai kegiatan supervisi ini.');
        }

        if ($this->isLocked($visit)) {
            abort(422, 'Kegiatan ini sudah difinalkan.');
        }
    }

    /** Hanya yang disupervisi yang boleh menandai hasil & mengajukan keberatan. */
    public function assertCanAcknowledge(User $user, SupVisit $visit): void
    {
        $employeeId = $this->employeeIdFor($user);

        if ($employeeId === null || (int) $visit->supervisee_employee_id !== $employeeId) {
            abort(403, 'Hanya pegawai yang disupervisi yang dapat menandai hasil ini.');
        }
    }

    /** Kegiatan terkunci setelah finalisasi atau pembatalan. */
    public function isLocked(SupVisit $visit): bool
    {
        return $visit->finalized_at !== null
            || in_array($visit->status, [VisitStatus::CANCELLED, VisitStatus::COMPLETED, VisitStatus::EVALUATED], true);
    }
}
