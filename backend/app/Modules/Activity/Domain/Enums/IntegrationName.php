<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum IntegrationName — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum IntegrationName: string
{
    case FINANCE = 'FINANCE';
    case INVENTORY = 'INVENTORY';
    case ASSET = 'ASSET';
    case FACILITY = 'FACILITY';
    case LMS = 'LMS';
    case EVENT = 'EVENT';
    case TRANSPORTATION = 'TRANSPORTATION';
    case ATTENDANCE = 'ATTENDANCE';
    case ACADEMIC = 'ACADEMIC';
    case COUNSELING = 'COUNSELING';
    case BOARDING = 'BOARDING';
    case CERTIFICATE = 'CERTIFICATE';
    case DOCUMENT = 'DOCUMENT';
    case NOTIFICATION = 'NOTIFICATION';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::FINANCE => 'Keuangan',
            self::INVENTORY => 'Inventaris',
            self::ASSET => 'Aset',
            self::FACILITY => 'Fasilitas',
            self::LMS => 'LMS',
            self::EVENT => 'Event',
            self::TRANSPORTATION => 'Transportasi',
            self::ATTENDANCE => 'Kehadiran',
            self::ACADEMIC => 'Akademik',
            self::COUNSELING => 'Bimbingan Konseling',
            self::BOARDING => 'Asrama',
            self::CERTIFICATE => 'Sertifikat',
            self::DOCUMENT => 'Dokumen',
            self::NOTIFICATION => 'Notifikasi',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
