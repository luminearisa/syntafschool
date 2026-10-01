<?php

namespace App\Modules\Asset\Domain\StateMachines;

use App\Modules\Asset\Domain\Enums\TransferStatus;

class TransferStateMachine extends StateMachine
{
    public static function transitions(): array
    {
        return [
            TransferStatus::REQUESTED->value => [TransferStatus::APPROVED->value, TransferStatus::REJECTED->value, TransferStatus::CANCELLED->value],
            TransferStatus::APPROVED->value => [TransferStatus::HANDED_OVER->value, TransferStatus::CANCELLED->value],
            // Serah terima belum selesai sebelum penerima mengonfirmasi; itulah
            // sebabnya COMPLETED hanya dapat dicapai dari HANDED_OVER.
            TransferStatus::HANDED_OVER->value => [TransferStatus::COMPLETED->value, TransferStatus::CANCELLED->value],
            TransferStatus::COMPLETED->value => [],
            TransferStatus::REJECTED->value => [],
            TransferStatus::CANCELLED->value => [],
        ];
    }
}
