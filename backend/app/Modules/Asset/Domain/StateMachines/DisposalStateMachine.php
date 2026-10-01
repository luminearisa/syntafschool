<?php

namespace App\Modules\Asset\Domain\StateMachines;

use App\Modules\Asset\Domain\Enums\DisposalStatus;

class DisposalStateMachine extends StateMachine
{
    public static function transitions(): array
    {
        return [
            DisposalStatus::DRAFT->value => [DisposalStatus::SUBMITTED->value, DisposalStatus::CANCELLED->value],
            DisposalStatus::SUBMITTED->value => [DisposalStatus::INSPECTED->value, DisposalStatus::REJECTED->value, DisposalStatus::CANCELLED->value],
            DisposalStatus::INSPECTED->value => [DisposalStatus::VALUED->value, DisposalStatus::REJECTED->value],
            DisposalStatus::VALUED->value => [DisposalStatus::APPROVED->value, DisposalStatus::REJECTED->value],
            DisposalStatus::APPROVED->value => [DisposalStatus::EXECUTED->value, DisposalStatus::CANCELLED->value],
            DisposalStatus::EXECUTED->value => [DisposalStatus::RETIRED->value],
            DisposalStatus::REJECTED->value => [],
            DisposalStatus::CANCELLED->value => [],
            DisposalStatus::RETIRED->value => [],
        ];
    }
}
