<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status kegiatan pengembangan.
 *
 * `MISSED` sengaja dibedakan dari `CANCELLED`: yang pertama tidak terlaksana tanpa
 * keputusan, yang kedua dibatalkan dengan sengaja — dan keduanya bermakna berbeda
 * saat meninjau kemajuan.
 */
enum DevelopmentActivityStatus: string
{
    case PLANNED = 'PLANNED';
    case SCHEDULED = 'SCHEDULED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case MISSED = 'MISSED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Direncanakan',
            self::SCHEDULED => 'Terjadwal',
            self::IN_PROGRESS => 'Berjalan',
            self::COMPLETED => 'Selesai',
            self::MISSED => 'Tidak terlaksana',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PLANNED, self::SCHEDULED, self::IN_PROGRESS], true);
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
