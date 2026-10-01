<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status program kelompok.
 */
enum GroupProgramStatus: string
{
    case DRAFT = 'DRAFT';
    case PLANNED = 'PLANNED';
    case ACTIVE = 'ACTIVE';
    case ON_HOLD = 'ON_HOLD';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::PLANNED => 'Direncanakan',
            self::ACTIVE => 'Berjalan',
            self::ON_HOLD => 'Ditahan',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function acceptsNewMembers(): bool
    {
        return in_array($this, [self::DRAFT, self::PLANNED, self::ACTIVE], true);
    }

    public function isRunning(): bool
    {
        return $this === self::ACTIVE;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
