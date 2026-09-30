<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum Priority — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum Priority: string
{
    case EMERGENCY = 'EMERGENCY';
    case URGENT = 'URGENT';
    case SEMI_URGENT = 'SEMI_URGENT';
    case NON_URGENT = 'NON_URGENT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::EMERGENCY => 'Gawat Darurat',
            self::URGENT => 'Mendesak',
            self::SEMI_URGENT => 'Semi Mendesak',
            self::NON_URGENT => 'Tidak Mendesak',
        };
    }


    /** Urutan penanganan: makin kecil makin didahulukan. */
    public function weight(): int
    {
        return match ($this) {
            self::EMERGENCY => 1,
            self::URGENT => 2,
            self::SEMI_URGENT => 3,
            self::NON_URGENT => 4,
        };
    }

    /** Prioritas ini butuh penanganan segera. */
    public function isCritical(): bool
    {
        return in_array($this, [self::EMERGENCY, self::URGENT], true);
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
