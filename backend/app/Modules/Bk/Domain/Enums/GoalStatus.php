<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status sasaran pada rencana konseling.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum GoalStatus: string
{
    case PENDING = 'PENDING';
    case IN_PROGRESS = 'IN_PROGRESS';
    case ACHIEVED = 'ACHIEVED';
    case PARTIALLY_ACHIEVED = 'PARTIALLY_ACHIEVED';
    case NOT_ACHIEVED = 'NOT_ACHIEVED';
    case DROPPED = 'DROPPED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Belum mulai',
            self::IN_PROGRESS => 'Berjalan',
            self::ACHIEVED => 'Tercapai',
            self::PARTIALLY_ACHIEVED => 'Sebagian tercapai',
            self::NOT_ACHIEVED => 'Tidak tercapai',
            self::DROPPED => 'Dihentikan',
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
