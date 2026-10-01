<?php

namespace App\Modules\Asset\Domain\StateMachines;

use App\Modules\Asset\Domain\Enums\AssetStatus;

/**
 * Siklus hidup aset: direncanakan → dipesan → diterima → terdaftar → terlabel →
 * aktif → (ditugaskan | dipinjam | diperbaiki | rusak | hilang) → dihapusbukukan
 * → diarsipkan.
 *
 * Jalur "kembali ke ACTIVE" dari status operasional disengaja: setelah dipinjam,
 * dipindahkan, atau selesai diperbaiki, aset memang kembali aktif.
 */
class AssetStateMachine extends StateMachine
{
    public static function transitions(): array
    {
        return [
            AssetStatus::PLANNED->value => [AssetStatus::ORDERED->value, AssetStatus::RETIRED->value],
            AssetStatus::ORDERED->value => [AssetStatus::RECEIVED->value, AssetStatus::PLANNED->value, AssetStatus::RETIRED->value],
            AssetStatus::RECEIVED->value => [AssetStatus::REGISTERED->value, AssetStatus::RETIRED->value],
            AssetStatus::REGISTERED->value => [AssetStatus::TAGGED->value, AssetStatus::ACTIVE->value, AssetStatus::RETIRED->value],
            AssetStatus::TAGGED->value => [AssetStatus::ACTIVE->value, AssetStatus::IN_STORAGE->value, AssetStatus::RETIRED->value],
            AssetStatus::ACTIVE->value => [
                AssetStatus::ASSIGNED->value, AssetStatus::IN_STORAGE->value, AssetStatus::ON_LOAN->value,
                AssetStatus::IN_MAINTENANCE->value, AssetStatus::DAMAGED->value, AssetStatus::LOST->value,
                AssetStatus::UNDER_INVESTIGATION->value, AssetStatus::DISPOSED->value,
            ],
            AssetStatus::ASSIGNED->value => [
                AssetStatus::ACTIVE->value, AssetStatus::IN_STORAGE->value, AssetStatus::ON_LOAN->value,
                AssetStatus::IN_MAINTENANCE->value, AssetStatus::DAMAGED->value, AssetStatus::LOST->value,
                AssetStatus::UNDER_INVESTIGATION->value, AssetStatus::DISPOSED->value,
            ],
            AssetStatus::IN_STORAGE->value => [
                AssetStatus::ACTIVE->value, AssetStatus::ASSIGNED->value, AssetStatus::ON_LOAN->value,
                AssetStatus::IN_MAINTENANCE->value, AssetStatus::DAMAGED->value, AssetStatus::LOST->value,
                AssetStatus::DISPOSED->value,
            ],
            AssetStatus::ON_LOAN->value => [AssetStatus::ACTIVE->value, AssetStatus::DAMAGED->value, AssetStatus::LOST->value, AssetStatus::IN_MAINTENANCE->value],
            AssetStatus::IN_MAINTENANCE->value => [AssetStatus::ACTIVE->value, AssetStatus::IN_STORAGE->value, AssetStatus::DAMAGED->value, AssetStatus::DISPOSED->value],
            AssetStatus::DAMAGED->value => [AssetStatus::IN_MAINTENANCE->value, AssetStatus::UNDER_INVESTIGATION->value, AssetStatus::DISPOSED->value, AssetStatus::ACTIVE->value],
            AssetStatus::LOST->value => [AssetStatus::UNDER_INVESTIGATION->value, AssetStatus::DISPOSED->value, AssetStatus::ACTIVE->value],
            AssetStatus::UNDER_INVESTIGATION->value => [AssetStatus::ACTIVE->value, AssetStatus::LOST->value, AssetStatus::DISPOSED->value],
            AssetStatus::DISPOSED->value => [AssetStatus::RETIRED->value],
            AssetStatus::RETIRED->value => [],
        ];
    }

    /** Status yang menandakan aset tidak boleh dipinjam/dipindahkan. */
    public static function isFrozen(string $status): bool
    {
        return in_array($status, [
            AssetStatus::IN_MAINTENANCE->value, AssetStatus::DISPOSED->value, AssetStatus::RETIRED->value,
        ], true);
    }
}
