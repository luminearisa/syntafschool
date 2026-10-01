<?php

namespace App\Modules\Asset\Domain\StateMachines;

use App\Modules\Asset\Domain\Enums\LoanStatus;

class LoanStateMachine extends StateMachine
{
    public static function transitions(): array
    {
        return [
            LoanStatus::REQUESTED->value => [LoanStatus::APPROVED->value, LoanStatus::REJECTED->value, LoanStatus::CANCELLED->value],
            LoanStatus::APPROVED->value => [LoanStatus::RESERVED->value, LoanStatus::HANDED_OVER->value, LoanStatus::CANCELLED->value],
            LoanStatus::RESERVED->value => [LoanStatus::HANDED_OVER->value, LoanStatus::CANCELLED->value],
            LoanStatus::HANDED_OVER->value => [LoanStatus::ACTIVE->value, LoanStatus::RETURNED->value, LoanStatus::DAMAGED->value, LoanStatus::LOST->value, LoanStatus::CANCELLED->value],
            LoanStatus::ACTIVE->value => [LoanStatus::OVERDUE->value, LoanStatus::RETURNED->value, LoanStatus::DAMAGED->value, LoanStatus::LOST->value],
            LoanStatus::OVERDUE->value => [LoanStatus::RETURNED->value, LoanStatus::DAMAGED->value, LoanStatus::LOST->value],
            LoanStatus::DAMAGED->value => [LoanStatus::RETURNED->value, LoanStatus::LOST->value],
            LoanStatus::RETURNED->value => [],
            LoanStatus::LOST->value => [],
            LoanStatus::CANCELLED->value => [],
            LoanStatus::REJECTED->value => [],
        ];
    }
}
