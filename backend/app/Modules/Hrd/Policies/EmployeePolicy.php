<?php

namespace App\Modules\Hrd\Policies;

use App\Models\User;
use App\Modules\Hrd\Application\Services\HrdAccessService;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Kebijakan akses baris untuk data pegawai (§52, §79).
 *
 * Middleware `permission:hr.*` menentukan **layar/aksi** yang boleh dibuka;
 * kebijakan ini yang menentukan **baris mana** yang boleh disentuh. Karena itu
 * seorang pemegang `hr.employee.view` yang hanya mengelola unit Pesantren tetap
 * tidak dapat membuka pegawai unit lain — dan penolakannya konsisten, baik lewat
 * API maupun lewat layar.
 */
class EmployeePolicy
{
    public function __construct(private readonly HrdAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->can($user, ['hr.employee.view', 'hr.employee.view.all', 'hr.team.view'])
            || $this->access->can($user, 'hr.self.view');
    }

    public function view(User $user, HrEmployee $employee): bool
    {
        if ($this->access->can($user, 'hr.self.view') && $this->access->employeeIdForUser($user) === (int) $employee->getKey()) {
            return true;
        }

        return $this->access->canViewEmployee($user, $employee);
    }

    public function create(User $user): bool
    {
        return $this->access->can($user, 'hr.employee.create');
    }

    public function update(User $user, HrEmployee $employee): bool
    {
        return $this->access->canManageEmployee($user, $employee);
    }

    /** Menghapus pegawai hanya dengan izin khusus; arsip adalah jalur normal (§59). */
    public function delete(User $user, HrEmployee $employee): bool
    {
        return $this->access->can($user, 'hr.employee.delete') && $this->access->isGlobal($user);
    }

    public function archive(User $user, HrEmployee $employee): bool
    {
        return $this->access->can($user, 'hr.employee.archive') && $this->access->canViewEmployee($user, $employee);
    }

    /** Melihat NIK, nomor rekening, dan identitas sensitif lain (§52). */
    public function viewSensitive(User $user, ?HrEmployee $employee = null): bool
    {
        if (! $this->access->canViewSensitive($user)) {
            return false;
        }

        return $employee === null || $this->access->canViewEmployee($user, $employee);
    }

    /** Menyetujui transaksi SDM: dipisah dari izin mengubah data (§79). */
    public function approveLifecycle(User $user): bool
    {
        return $this->access->can($user, 'hr.lifecycle.approve');
    }

    public function executeLifecycle(User $user): bool
    {
        return $this->access->can($user, 'hr.lifecycle.execute');
    }

    public function merge(User $user): bool
    {
        return $this->access->can($user, 'hr.employee.merge') && $this->access->isGlobal($user);
    }

    public function export(User $user): bool
    {
        return $this->access->can($user, 'hr.employee.export');
    }

    public function viewAudit(User $user): bool
    {
        return $this->access->can($user, 'hr.audit.view');
    }
}
