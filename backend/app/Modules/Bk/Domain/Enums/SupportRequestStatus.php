<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status permintaan dukungan di antrean BK.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum SupportRequestStatus: string
{
    case QUEUED = 'QUEUED';
    case REVIEWED = 'REVIEWED';
    case ASSIGNED = 'ASSIGNED';
    case SCHEDULED = 'SCHEDULED';
    case CLOSED = 'CLOSED';
    case DECLINED = 'DECLINED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Dalam antrean',
            self::REVIEWED => 'Ditinjau',
            self::ASSIGNED => 'Ditugaskan',
            self::SCHEDULED => 'Terjadwal',
            self::CLOSED => 'Selesai',
            self::DECLINED => 'Ditolak',
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
