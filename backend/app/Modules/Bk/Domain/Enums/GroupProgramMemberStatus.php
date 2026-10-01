<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status keikutsertaan siswa pada program.
 */
enum GroupProgramMemberStatus: string
{
    case INVITED = 'INVITED';
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';
    case WITHDRAWN = 'WITHDRAWN';
    case REMOVED = 'REMOVED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::INVITED => 'Diundang',
            self::ACTIVE => 'Aktif',
            self::COMPLETED => 'Selesai mengikuti',
            self::WITHDRAWN => 'Mengundurkan diri',
            self::REMOVED => 'Dikeluarkan',
        };
    }

    public function isParticipating(): bool
    {
        return in_array($this, [self::INVITED, self::ACTIVE, self::COMPLETED], true);
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
