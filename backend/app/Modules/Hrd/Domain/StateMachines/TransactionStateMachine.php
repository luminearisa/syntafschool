<?php

namespace App\Modules\Hrd\Domain\StateMachines;

use App\Modules\Hrd\Domain\Enums\TransactionStatus;

/**
 * Keadaan transaksi SDM (§33).
 *
 * Ditegakkan terpisah dari siklus hidup pegawai karena kesalahannya berbeda:
 * yang pertama soal "boleh diproses atau tidak", yang kedua soal "boleh jadi
 * pegawai tetap atau tidak".
 */
class TransactionStateMachine extends StateMachine
{
    public function transitions(): array
    {
        $map = [];

        foreach (TransactionStatus::cases() as $status) {
            $map[$status->value] = array_map(fn (TransactionStatus $s) => $s->value, $status->allowedTransitions());
        }

        return $map;
    }

    public function label(string $state): string
    {
        return TransactionStatus::tryFrom($state)?->label() ?? $state;
    }

    /** Transaksi dalam keadaan ini masih boleh diubah isinya. */
    public function isEditable(string $state): bool
    {
        return $state === TransactionStatus::DRAFT->value;
    }

    /** Transaksi dalam keadaan ini boleh diterapkan ke data pegawai. */
    public function isAppliable(string $state): bool
    {
        return $state === TransactionStatus::APPROVED->value;
    }
}
