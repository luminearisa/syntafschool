<?php

namespace App\Modules\Asset\Domain\StateMachines;

use App\Modules\Asset\Domain\Enums\StockOpnameStatus;

class StockOpnameStateMachine extends StateMachine
{
    public static function transitions(): array
    {
        return [
            StockOpnameStatus::PLANNED->value => [StockOpnameStatus::IN_PROGRESS->value, StockOpnameStatus::CANCELLED->value],
            // Tahap COUNTING opsional: sesi yang mencatat lewat pemindaian dapat
            // langsung masuk verifikasi. Tidak ada gunanya memaksa satu klik
            // tambahan hanya untuk menghormati bagan.
            StockOpnameStatus::IN_PROGRESS->value => [StockOpnameStatus::COUNTING->value, StockOpnameStatus::VERIFICATION->value, StockOpnameStatus::CANCELLED->value],
            StockOpnameStatus::COUNTING->value => [StockOpnameStatus::VERIFICATION->value, StockOpnameStatus::CANCELLED->value],
            StockOpnameStatus::VERIFICATION->value => [StockOpnameStatus::APPROVAL->value, StockOpnameStatus::COUNTING->value, StockOpnameStatus::CANCELLED->value],
            StockOpnameStatus::APPROVAL->value => [StockOpnameStatus::COMPLETED->value, StockOpnameStatus::VERIFICATION->value, StockOpnameStatus::CANCELLED->value],
            StockOpnameStatus::COMPLETED->value => [],
            StockOpnameStatus::CANCELLED->value => [],
        ];
    }
}
