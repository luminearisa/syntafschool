<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum Consciousness — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum Consciousness: string
{
    case ALERT = 'ALERT';
    case VOICE = 'VOICE';
    case PAIN = 'PAIN';
    case UNRESPONSIVE = 'UNRESPONSIVE';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ALERT => 'Sadar Penuh',
            self::VOICE => 'Respons Suara',
            self::PAIN => 'Respons Nyeri',
            self::UNRESPONSIVE => 'Tidak Responsif',
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
