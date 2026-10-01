<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status konferensi kasus.
 *
 * Konferensi berjalan sebagai pertemuan yang punya awal dan akhir: undangan disusun,
 * pertemuan berlangsung, keputusan diambil, lalu tindak lanjutnya dijalankan.
 */
enum ConferenceStatus: string
{
    case DRAFT = 'DRAFT';
    case SCHEDULED = 'SCHEDULED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case RESCHEDULED = 'RESCHEDULED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SCHEDULED => 'Terjadwal',
            self::IN_PROGRESS => 'Berlangsung',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::RESCHEDULED => 'Dijadwalkan ulang',
        };
    }

    /** Status yang masih menerima undangan & tindak lanjut. */
    public function isOpen(): bool
    {
        return in_array($this, [self::DRAFT, self::SCHEDULED, self::IN_PROGRESS, self::RESCHEDULED], true);
    }

    /** Konferensi yang sudah selesai tidak dapat dihapus, hanya diarsipkan. */
    public function isClosed(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
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
