<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Tahapan rujukan eksternal.
 *
 * Urutannya mengikuti alur kerja nyata: asesmen internal → persetujuan → rujukan →
 * janji temu pihak luar → hasil → tindak lanjut → selesai. Status tidak boleh
 * melompati `PENDING_CONSENT` selama persetujuan masih diperlukan.
 */
enum ExternalReferralStatus: string
{
    case DRAFT = 'DRAFT';
    case ASSESSMENT = 'ASSESSMENT';
    case PENDING_CONSENT = 'PENDING_CONSENT';
    case REFERRED = 'REFERRED';
    case APPOINTMENT_SCHEDULED = 'APPOINTMENT_SCHEDULED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESULT_RECEIVED = 'RESULT_RECEIVED';
    case FOLLOW_UP = 'FOLLOW_UP';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case DECLINED = 'DECLINED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ASSESSMENT => 'Asesmen',
            self::PENDING_CONSENT => 'Menunggu persetujuan',
            self::REFERRED => 'Dirujuk',
            self::APPOINTMENT_SCHEDULED => 'Janji temu dijadwalkan',
            self::IN_PROGRESS => 'Berjalan',
            self::RESULT_RECEIVED => 'Hasil diterima',
            self::FOLLOW_UP => 'Tindak lanjut',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::DECLINED => 'Ditolak',
        };
    }

    /** Urutan tahap yang sah; dipakai layanan untuk menolak lompatan status. */
    public static function sequence(): array
    {
        return [
            self::DRAFT, self::ASSESSMENT, self::PENDING_CONSENT, self::REFERRED,
            self::APPOINTMENT_SCHEDULED, self::IN_PROGRESS, self::RESULT_RECEIVED,
            self::FOLLOW_UP, self::COMPLETED,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::DECLINED], true);
    }

    /** Tahap yang menuntut persetujuan wali sudah ada sebelum dilanjutkan. */
    public function requiresConsent(): bool
    {
        return in_array($this, [
            self::REFERRED, self::APPOINTMENT_SCHEDULED, self::IN_PROGRESS,
            self::RESULT_RECEIVED, self::FOLLOW_UP, self::COMPLETED,
        ], true);
    }

    /** Peringkat tahap untuk memvalidasi kemajuan. */
    public function rank(): int
    {
        $index = array_search($this, self::sequence(), true);

        return $index === false ? 0 : $index + 1;
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
