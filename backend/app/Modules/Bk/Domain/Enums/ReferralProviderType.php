<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis penyedia rujukan.
 *
 * Jenis menentukan apakah persetujuan wali diwajibkan sebelum data dibagikan
 * (`requiresConsent`), semata-mata agar keputusan itu tidak bergantung ingatan.
 */
enum ReferralProviderType: string
{
    case PSYCHOLOGIST = 'PSYCHOLOGIST';
    case DOCTOR = 'DOCTOR';
    case SPECIALIST = 'SPECIALIST';
    case EXTERNAL_COUNSELOR = 'EXTERNAL_COUNSELOR';
    case SOCIAL_SERVICE = 'SOCIAL_SERVICE';
    case EDUCATIONAL_INSTITUTION = 'EDUCATIONAL_INSTITUTION';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PSYCHOLOGIST => 'Psikolog',
            self::DOCTOR => 'Dokter',
            self::SPECIALIST => 'Spesialis',
            self::EXTERNAL_COUNSELOR => 'Konselor luar',
            self::SOCIAL_SERVICE => 'Layanan sosial',
            self::EDUCATIONAL_INSTITUTION => 'Lembaga pendidikan',
            self::OTHER => 'Penyedia lain',
        };
    }

    /** Rujukan yang menyentuh data pribadi selalu memerlukan persetujuan wali. */
    public function requiresConsent(): bool
    {
        return true;
    }

    public function defaultConfidentiality(): ConfidentialityLevel
    {
        return match ($this) {
            self::PSYCHOLOGIST, self::DOCTOR, self::SPECIALIST, self::EXTERNAL_COUNSELOR => ConfidentialityLevel::CONFIDENTIAL,
            default => ConfidentialityLevel::RESTRICTED,
        };
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
