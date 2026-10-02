<?php

namespace App\Modules\Hrd\Domain\StateMachines;

use App\Modules\Hrd\Domain\Enums\EmployeeLifecycleState;

/**
 * Siklus hidup pegawai (§68).
 *
 * Aturan yang dikodekan di sini adalah aturan yang tidak boleh dilanggar siapa
 * pun, termasuk admin:
 *
 *  - `draft` tidak bisa langsung menjadi `confirmed` — harus melewati pra-kerja
 *    dan masa kerja;
 *  - `archived` hanya dapat ditinggalkan lewat proses rehire;
 *  - pegawai yang sedang `offboarding` masih boleh dipindahkan penugasannya,
 *    tetapi tidak dapat diangkat tetap.
 */
class EmployeeLifecycleStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            EmployeeLifecycleState::DRAFT->value => [
                EmployeeLifecycleState::PREBOARDING->value,
                EmployeeLifecycleState::ACTIVE->value,
                EmployeeLifecycleState::INACTIVE->value,
                EmployeeLifecycleState::ARCHIVED->value,
            ],
            EmployeeLifecycleState::PREBOARDING->value => [
                EmployeeLifecycleState::ACTIVE->value,
                EmployeeLifecycleState::PROBATION->value,
                EmployeeLifecycleState::INACTIVE->value,
                EmployeeLifecycleState::ARCHIVED->value,
            ],
            EmployeeLifecycleState::ACTIVE->value => [
                EmployeeLifecycleState::PROBATION->value,
                EmployeeLifecycleState::CONFIRMED->value,
                EmployeeLifecycleState::LEAVE->value,
                EmployeeLifecycleState::SUSPENDED->value,
                EmployeeLifecycleState::OFFBOARDING->value,
                EmployeeLifecycleState::INACTIVE->value,
            ],
            EmployeeLifecycleState::PROBATION->value => [
                EmployeeLifecycleState::CONFIRMED->value,
                EmployeeLifecycleState::ACTIVE->value,
                EmployeeLifecycleState::LEAVE->value,
                EmployeeLifecycleState::SUSPENDED->value,
                EmployeeLifecycleState::OFFBOARDING->value,
                EmployeeLifecycleState::INACTIVE->value,
            ],
            EmployeeLifecycleState::CONFIRMED->value => [
                EmployeeLifecycleState::ACTIVE->value,
                EmployeeLifecycleState::LEAVE->value,
                EmployeeLifecycleState::SUSPENDED->value,
                EmployeeLifecycleState::OFFBOARDING->value,
                EmployeeLifecycleState::INACTIVE->value,
            ],
            EmployeeLifecycleState::LEAVE->value => [
                EmployeeLifecycleState::ACTIVE->value,
                EmployeeLifecycleState::CONFIRMED->value,
                EmployeeLifecycleState::PROBATION->value,
                EmployeeLifecycleState::SUSPENDED->value,
                EmployeeLifecycleState::OFFBOARDING->value,
                EmployeeLifecycleState::INACTIVE->value,
            ],
            EmployeeLifecycleState::SUSPENDED->value => [
                EmployeeLifecycleState::ACTIVE->value,
                EmployeeLifecycleState::CONFIRMED->value,
                EmployeeLifecycleState::OFFBOARDING->value,
                EmployeeLifecycleState::INACTIVE->value,
            ],
            EmployeeLifecycleState::OFFBOARDING->value => [
                EmployeeLifecycleState::INACTIVE->value,
                EmployeeLifecycleState::ACTIVE->value,
                EmployeeLifecycleState::CONFIRMED->value,
                EmployeeLifecycleState::PROBATION->value,
            ],
            EmployeeLifecycleState::INACTIVE->value => [
                EmployeeLifecycleState::ARCHIVED->value,
                // Rehire: kembali bekerja, selalu lewat transaksi rehire.
                EmployeeLifecycleState::PREBOARDING->value,
                EmployeeLifecycleState::ACTIVE->value,
            ],
            EmployeeLifecycleState::ARCHIVED->value => [
                EmployeeLifecycleState::PREBOARDING->value,
            ],
        ];
    }

    public function label(string $state): string
    {
        return EmployeeLifecycleState::tryFrom($state)?->label() ?? $state;
    }

    /** Keadaan yang mengizinkan pegawai menerima penugasan baru (§20). */
    public function allowsAssignment(string $state): bool
    {
        return EmployeeLifecycleState::tryFrom($state)?->allowsAssignment() ?? false;
    }

    /** Keadaan yang mengizinkan penambahan transaksi baru. */
    public function allowsTransaction(string $state): bool
    {
        return $state !== EmployeeLifecycleState::ARCHIVED->value;
    }
}
