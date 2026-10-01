<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum RegistrationStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum RegistrationStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case SELECTED = 'SELECTED';
    case WAITLISTED = 'WAITLISTED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Diajukan',
            self::UNDER_REVIEW => 'Sedang Ditinjau',
            self::SELECTED => 'Terpilih',
            self::WAITLISTED => 'Daftar Tunggu',
            self::APPROVED => 'Diterima',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Status akhir: tidak ada lagi keputusan yang menunggu. */
    public function isFinal(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED, self::CANCELLED], true);
    }

    /** Pendaftaran masih menunggu keputusan panitia/pembina. */
    public function isOpen(): bool
    {
        return in_array($this, [self::SUBMITTED, self::UNDER_REVIEW, self::SELECTED, self::WAITLISTED], true);
    }
}
