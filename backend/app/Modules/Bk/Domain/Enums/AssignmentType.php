<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Sifat penugasan konselor pada kasus.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum AssignmentType: string
{
    case PRIMARY = 'PRIMARY';
    case SECONDARY = 'SECONDARY';
    case SUPERVISOR = 'SUPERVISOR';
    case TEMPORARY = 'TEMPORARY';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PRIMARY => 'Konselor utama',
            self::SECONDARY => 'Pendamping',
            self::SUPERVISOR => 'Supervisor',
            self::TEMPORARY => 'Sementara (pengganti)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
