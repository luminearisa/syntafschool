<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum DocumentType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum DocumentType: string
{
    case MEDICAL_CERTIFICATE = 'MEDICAL_CERTIFICATE';
    case REFERRAL_LETTER = 'REFERRAL_LETTER';
    case TREATMENT_NOTE = 'TREATMENT_NOTE';
    case HEALTH_CLEARANCE = 'HEALTH_CLEARANCE';
    case MEDICATION_INSTRUCTION = 'MEDICATION_INSTRUCTION';
    case RETURN_TO_SCHOOL = 'RETURN_TO_SCHOOL';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::MEDICAL_CERTIFICATE => 'Surat Keterangan Medis',
            self::REFERRAL_LETTER => 'Surat Rujukan',
            self::TREATMENT_NOTE => 'Catatan Tindakan',
            self::HEALTH_CLEARANCE => 'Surat Sehat',
            self::MEDICATION_INSTRUCTION => 'Instruksi Obat',
            self::RETURN_TO_SCHOOL => 'Surat Kembali Sekolah',
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
