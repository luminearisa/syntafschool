<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status alur referral \u2014 SUBMITTED \u2192 \u2026 \u2192 RESOLVED/REJECTED/CANCELLED.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum ReferralStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case RECEIVED = 'RECEIVED';
    case SCREENING = 'SCREENING';
    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case REFERRED = 'REFERRED';
    case RESOLVED = 'RESOLVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Diajukan',
            self::RECEIVED => 'Diterima BK',
            self::SCREENING => 'Sedang diskrining',
            self::ASSIGNED => 'Ditugaskan',
            self::IN_PROGRESS => 'Sedang ditangani',
            self::REFERRED => 'Diteruskan ke pihak lain',
            self::RESOLVED => 'Selesai',
            self::REJECTED => 'Ditolak',
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

    /** Sudah tidak menerima aksi lagi. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::RESOLVED, self::REJECTED, self::CANCELLED], true);
    }

    /** Masih menunggu tindakan BK (dasar widget \"perlu screening\"). */
    public function needsAttention(): bool
    {
        return in_array($this, [self::SUBMITTED, self::RECEIVED, self::SCREENING], true);
    }
}
