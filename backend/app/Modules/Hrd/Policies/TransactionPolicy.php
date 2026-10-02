<?php

namespace App\Modules\Hrd\Policies;

use App\Models\User;
use App\Modules\Hrd\Application\Services\HrdAccessService;
use App\Modules\Hrd\Models\HrTransaction;

/**
 * Kebijakan transaksi SDM (§33, §39, §52).
 *
 * Dua pemisahan yang penting dan disengaja:
 *
 *  - **mengajukan ≠ menyetujui.** Pemohon tidak otomatis boleh menyetujui
 *    pengajuannya sendiri, sekalipun ia atasan;
 *  - **menyetujui ≠ menerapkan.** Penerapan efek (penugasan baru, penutupan
 *    penugasan lama) memerlukan izin `hr.lifecycle.execute`, sehingga keputusan
 *    organisasi dan eksekusinya dapat dipisahkan bila sekolah menghendaki.
 */
class TransactionPolicy
{
    public function __construct(private readonly HrdAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->can($user, ['hr.lifecycle.view', 'hr.employee.view', 'hr.self.view']);
    }

    public function view(User $user, HrTransaction $transaction): bool
    {
        $employee = $transaction->employee;

        if ($employee === null) {
            return $this->access->isGlobal($user);
        }

        if ($this->access->can($user, 'hr.self.view') && $this->access->employeeIdForUser($user) === (int) $employee->getKey()) {
            return true;
        }

        return $this->access->canViewEmployee($user, $employee);
    }

    public function create(User $user): bool
    {
        return $this->access->can($user, 'hr.lifecycle.create');
    }

    public function approve(User $user, HrTransaction $transaction): bool
    {
        if (! $this->access->can($user, 'hr.lifecycle.approve')) {
            return false;
        }

        // Pengaju tidak menyetujui dirinya sendiri, kecuali HR global yang memang
        // bertindak sebagai pemroses tunggal pada instalasi kecil.
        if ((int) $transaction->requested_by === (int) $user->getKey() && ! $this->access->isGlobal($user)) {
            return false;
        }

        return $this->view($user, $transaction);
    }

    public function execute(User $user, HrTransaction $transaction): bool
    {
        return $this->access->can($user, 'hr.lifecycle.execute') && $this->view($user, $transaction);
    }

    public function cancel(User $user, HrTransaction $transaction): bool
    {
        return $this->view($user, $transaction)
            && ($this->access->can($user, 'hr.lifecycle.execute') || (int) $transaction->requested_by === (int) $user->getKey());
    }
}
