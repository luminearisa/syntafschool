<?php

namespace App\Modules\Asset\Domain\StateMachines;

use App\Modules\Asset\Domain\Enums\BookingStatus;

class BookingStateMachine extends StateMachine
{
    public static function transitions(): array
    {
        return [
            BookingStatus::REQUESTED->value => [BookingStatus::APPROVED->value, BookingStatus::RESERVED->value, BookingStatus::REJECTED->value, BookingStatus::CANCELLED->value],
            // Persetujuan dan penyimpanan slot boleh terjadi dalam satu langkah:
            // memaksa dua langkah hanya menambah klik pada rapat yang sudah jelas.
            BookingStatus::APPROVED->value => [BookingStatus::RESERVED->value, BookingStatus::CANCELLED->value],
            BookingStatus::RESERVED->value => [BookingStatus::IN_USE->value, BookingStatus::NO_SHOW->value, BookingStatus::CANCELLED->value],
            BookingStatus::IN_USE->value => [BookingStatus::COMPLETED->value],
            BookingStatus::COMPLETED->value => [],
            BookingStatus::CANCELLED->value => [],
            BookingStatus::REJECTED->value => [],
            BookingStatus::NO_SHOW->value => [],
        ];
    }
}
