<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum QueueStage — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum QueueStage: string
{
    case WAITING = 'WAITING';
    case TRIAGE = 'TRIAGE';
    case WAITING_PROVIDER = 'WAITING_PROVIDER';
    case IN_EXAMINATION = 'IN_EXAMINATION';
    case PHARMACY = 'PHARMACY';
    case DISCHARGE = 'DISCHARGE';
    case COMPLETED = 'COMPLETED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::WAITING => 'Menunggu',
            self::TRIAGE => 'Triase',
            self::WAITING_PROVIDER => 'Menunggu Penanggung Jawab',
            self::IN_EXAMINATION => 'Pemeriksaan',
            self::PHARMACY => 'Farmasi',
            self::DISCHARGE => 'Pemulangan',
            self::COMPLETED => 'Selesai',
        };
    }


    /** Urutan tahap antrean untuk penomoran dan pelaporan. */
    public function step(): int
    {
        return match ($this) {
            self::WAITING => 1,
            self::TRIAGE => 2,
            self::WAITING_PROVIDER => 3,
            self::IN_EXAMINATION => 4,
            self::PHARMACY => 5,
            self::DISCHARGE => 6,
            self::COMPLETED => 7,
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
