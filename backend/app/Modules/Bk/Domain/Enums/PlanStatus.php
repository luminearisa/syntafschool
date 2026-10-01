<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status rencana konseling.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum PlanStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case REVIEW = 'REVIEW';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ACTIVE => 'Aktif',
            self::REVIEW => 'Ditinjau',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
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
