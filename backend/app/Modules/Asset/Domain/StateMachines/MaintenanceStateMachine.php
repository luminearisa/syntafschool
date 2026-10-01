<?php

namespace App\Modules\Asset\Domain\StateMachines;

use App\Modules\Asset\Domain\Enums\MaintenanceRequestStatus;
use App\Modules\Asset\Domain\Enums\WorkOrderStatus;

class MaintenanceStateMachine extends StateMachine
{
    /** Transisi permintaan pemeliharaan. */
    public static function transitions(): array
    {
        return [
            MaintenanceRequestStatus::NEW->value => [MaintenanceRequestStatus::TRIAGED->value, MaintenanceRequestStatus::REJECTED->value, MaintenanceRequestStatus::CANCELLED->value],
            MaintenanceRequestStatus::TRIAGED->value => [MaintenanceRequestStatus::ASSIGNED->value, MaintenanceRequestStatus::REJECTED->value, MaintenanceRequestStatus::CANCELLED->value],
            MaintenanceRequestStatus::ASSIGNED->value => [MaintenanceRequestStatus::IN_PROGRESS->value, MaintenanceRequestStatus::WAITING_PARTS->value, MaintenanceRequestStatus::WAITING_VENDOR->value, MaintenanceRequestStatus::CANCELLED->value],
            MaintenanceRequestStatus::IN_PROGRESS->value => [MaintenanceRequestStatus::WAITING_PARTS->value, MaintenanceRequestStatus::WAITING_VENDOR->value, MaintenanceRequestStatus::COMPLETED->value, MaintenanceRequestStatus::CANCELLED->value],
            MaintenanceRequestStatus::WAITING_PARTS->value => [MaintenanceRequestStatus::IN_PROGRESS->value, MaintenanceRequestStatus::CANCELLED->value],
            MaintenanceRequestStatus::WAITING_VENDOR->value => [MaintenanceRequestStatus::IN_PROGRESS->value, MaintenanceRequestStatus::CANCELLED->value],
            MaintenanceRequestStatus::COMPLETED->value => [MaintenanceRequestStatus::VERIFIED->value, MaintenanceRequestStatus::IN_PROGRESS->value],
            MaintenanceRequestStatus::VERIFIED->value => [MaintenanceRequestStatus::CLOSED->value],
            MaintenanceRequestStatus::CLOSED->value => [],
            MaintenanceRequestStatus::REJECTED->value => [],
            MaintenanceRequestStatus::CANCELLED->value => [],
        ];
    }

    /** Transisi work order — dipakai `WorkOrderStateMachine`. */
    public static function workOrderTransitions(): array
    {
        return [
            WorkOrderStatus::DRAFT->value => [WorkOrderStatus::OPEN->value, WorkOrderStatus::CANCELLED->value],
            WorkOrderStatus::OPEN->value => [WorkOrderStatus::ASSIGNED->value, WorkOrderStatus::CANCELLED->value],
            WorkOrderStatus::ASSIGNED->value => [WorkOrderStatus::IN_PROGRESS->value, WorkOrderStatus::CANCELLED->value],
            WorkOrderStatus::IN_PROGRESS->value => [WorkOrderStatus::ON_HOLD->value, WorkOrderStatus::COMPLETED->value, WorkOrderStatus::CANCELLED->value],
            WorkOrderStatus::ON_HOLD->value => [WorkOrderStatus::IN_PROGRESS->value, WorkOrderStatus::CANCELLED->value],
            WorkOrderStatus::COMPLETED->value => [WorkOrderStatus::VERIFIED->value, WorkOrderStatus::IN_PROGRESS->value],
            WorkOrderStatus::VERIFIED->value => [],
            WorkOrderStatus::CANCELLED->value => [],
        ];
    }
}
