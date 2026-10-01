<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis rekaman yang diatur retensinya.
 *
 * Masa simpan adalah data pada tabel bk_retention_policies, bukan konstanta:
 * sekolah dengan kewajiban berbeda dapat menyesuaikannya tanpa mengubah kode.
 */
enum RetentionEntityType: string
{
    case CASE = 'CASE';
    case NOTE = 'NOTE';
    case DOCUMENT = 'DOCUMENT';
    case CONSENT = 'CONSENT';
    case AUDIT = 'AUDIT';
    case ASSESSMENT = 'ASSESSMENT';
    case FEEDBACK = 'FEEDBACK';
    case CONFERENCE = 'CONFERENCE';
    case EXTERNAL_REFERRAL = 'EXTERNAL_REFERRAL';
    case DEVELOPMENT_PLAN = 'DEVELOPMENT_PLAN';
    case RISK_INDICATOR = 'RISK_INDICATOR';
    case SESSION = 'SESSION';
    case REFERRAL = 'REFERRAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::CASE => 'Kasus',
            self::NOTE => 'Catatan',
            self::DOCUMENT => 'Dokumen',
            self::CONSENT => 'Persetujuan',
            self::AUDIT => 'Jejak audit',
            self::ASSESSMENT => 'Asesmen',
            self::FEEDBACK => 'Umpan balik',
            self::CONFERENCE => 'Konferensi kasus',
            self::EXTERNAL_REFERRAL => 'Rujukan eksternal',
            self::DEVELOPMENT_PLAN => 'Rencana pengembangan',
            self::RISK_INDICATOR => 'Penanda risiko',
            self::SESSION => 'Sesi konseling',
            self::REFERRAL => 'Rujukan internal',
        };
    }

    /** Kelompok yang biasanya menyimpan data paling sensitif. */
    public function isHighlySensitive(): bool
    {
        return in_array($this, [self::NOTE, self::SESSION, self::DOCUMENT, self::CASE], true);
    }

    /** Kebijakan bawaan bila sekolah belum menetapkan apa pun (hari). */
    public function fallbackDays(): int
    {
        return match ($this) {
            self::DOCUMENT => 3650,
            self::CASE, self::NOTE, self::SESSION, self::CONSENT, self::REFERRAL, self::CONFERENCE => 1825,
            self::AUDIT => 2555,
            self::ASSESSMENT, self::DEVELOPMENT_PLAN, self::RISK_INDICATOR, self::EXTERNAL_REFERRAL => 1095,
            self::FEEDBACK => 730,
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
