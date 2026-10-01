<?php

namespace App\Modules\Asset\Domain\StateMachines;

class WorkOrderStateMachine extends StateMachine
{
    public static function transitions(): array
    {
        return MaintenanceStateMachine::workOrderTransitions();
    }
}
