<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis dokumen kasus BK.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum DocumentType: string
{
    case REFERRAL = 'REFERRAL';
    case CONSENT = 'CONSENT';
    case MEETING_MINUTES = 'MEETING_MINUTES';
    case SUPPORT_PLAN = 'SUPPORT_PLAN';
    case ASSESSMENT = 'ASSESSMENT';
    case CASE_ATTACHMENT = 'CASE_ATTACHMENT';
    case REFERRAL_LETTER = 'REFERRAL_LETTER';
    case CERTIFICATE = 'CERTIFICATE';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REFERRAL => 'Dokumen referral',
            self::CONSENT => 'Persetujuan orang tua',
            self::MEETING_MINUTES => 'Notulen pertemuan',
            self::SUPPORT_PLAN => 'Rencana dukungan',
            self::ASSESSMENT => 'Hasil asesmen',
            self::CASE_ATTACHMENT => 'Lampiran kasus',
            self::REFERRAL_LETTER => 'Surat rujukan',
            self::CERTIFICATE => 'Keterangan',
            self::OTHER => 'Lain-lain',
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
