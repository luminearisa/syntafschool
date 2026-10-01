<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status pengisian asesmen.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum AssessmentStatus: string
{
    case DRAFT = 'DRAFT';
    case SENT = 'SENT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case REVIEWED = 'REVIEWED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SENT => 'Terkirim',
            self::IN_PROGRESS => 'Sedang diisi',
            self::COMPLETED => 'Selesai',
            self::REVIEWED => 'Sudah ditelaah',
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
