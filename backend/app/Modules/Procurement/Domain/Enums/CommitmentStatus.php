<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status komitmen anggaran (§35). */
enum CommitmentStatus: string
{
    use EnumOptions;

    case RESERVED = 'RESERVED';
    case COMMITTED = 'COMMITTED';
    case CONSUMED = 'CONSUMED';
    case RELEASED = 'RELEASED';

    public function label(): string
    {
        return match ($this) {
            self::RESERVED => 'Direservasi',
            self::COMMITTED => 'Dikomitkan',
            self::CONSUMED => 'Terpakai',
            self::RELEASED => 'Dilepas',
        };
    }
}
