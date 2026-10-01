<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Cakupan persetujuan.
 *
 * Persetujuan bersifat per-cakupan: izin konseling tidak berarti izin berbagi
 * dokumen, dan izin mengikuti program tidak berarti izin merujuk ke pihak luar.
 */
enum ConsentScope: string
{
    case COUNSELING = 'COUNSELING';
    case PARENT_MEETING = 'PARENT_MEETING';
    case ASSESSMENT = 'ASSESSMENT';
    case EXTERNAL_REFERRAL = 'EXTERNAL_REFERRAL';
    case DOCUMENT_SHARING = 'DOCUMENT_SHARING';
    case PROGRAM_PARTICIPATION = 'PROGRAM_PARTICIPATION';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::COUNSELING => 'Konseling',
            self::PARENT_MEETING => 'Pertemuan orang tua',
            self::ASSESSMENT => 'Asesmen',
            self::EXTERNAL_REFERRAL => 'Rujukan eksternal',
            self::DOCUMENT_SHARING => 'Berbagi dokumen',
            self::PROGRAM_PARTICIPATION => 'Mengikuti program',
        };
    }

    /** Cakupan yang menyentuh pihak luar menuntut persetujuan tertulis wali. */
    public function requiresGuardianDecision(): bool
    {
        return in_array($this, [self::EXTERNAL_REFERRAL, self::DOCUMENT_SHARING, self::ASSESSMENT], true);
    }

    /** Cakupan yang juga dapat diminta untuk wali sendiri (bukan hanya siswa). */
    public function canTargetGuardian(): bool
    {
        return in_array($this, [self::PARENT_MEETING, self::DOCUMENT_SHARING], true);
    }

    public function defaultValidityDays(): int
    {
        return match ($this) {
            self::EXTERNAL_REFERRAL => 180,
            self::DOCUMENT_SHARING => 90,
            self::PROGRAM_PARTICIPATION => 365,
            default => 180,
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
