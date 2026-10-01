<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status pelaksanaan intervensi.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum InterventionStatus: string
{
    case PLANNED = 'PLANNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Direncanakan',
            self::IN_PROGRESS => 'Berjalan',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::FAILED => 'Tidak berhasil',
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
