<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum CertificateType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum CertificateType: string
{
    case SCHOOL_ACTIVITY = 'SCHOOL_ACTIVITY';
    case SPORTS = 'SPORTS';
    case BOARDING = 'BOARDING';
    case EVENT = 'EVENT';
    case RETURN_TO_SCHOOL = 'RETURN_TO_SCHOOL';
    case MEDICAL_LEAVE = 'MEDICAL_LEAVE';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SCHOOL_ACTIVITY => 'Kegiatan Sekolah',
            self::SPORTS => 'Olahraga',
            self::BOARDING => 'Asrama',
            self::EVENT => 'Acara',
            self::RETURN_TO_SCHOOL => 'Kembali ke Sekolah',
            self::MEDICAL_LEAVE => 'Izin Sakit',
            self::OTHER => 'Lainnya',
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
