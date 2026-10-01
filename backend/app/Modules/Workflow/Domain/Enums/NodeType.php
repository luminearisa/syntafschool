<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Jenis node pada graf workflow (§18, §54).
 *
 * Engine hanya mengenal delapan jenis node ini. Semua kekhususan bisnis
 * (mis. "Purchase Request", "Cuti", "Disposal Aset") hidup di definisi —
 * bukan di kode engine.
 */
enum NodeType: string
{
    case START = 'START';
    case APPROVAL = 'APPROVAL';
    case REVIEW = 'REVIEW';
    case CONDITION = 'CONDITION';
    case PARALLEL = 'PARALLEL';
    case NOTIFICATION = 'NOTIFICATION';
    case AUTOMATIC = 'AUTOMATIC';
    case END = 'END';

    /** Node yang menunggu keputusan manusia. */
    public function waitsForHuman(): bool
    {
        return in_array($this, [self::APPROVAL, self::REVIEW], true);
    }

    /** Node yang dijalankan seketika lalu melanjutkan alur. */
    public function isAutomatic(): bool
    {
        return in_array($this, [self::START, self::CONDITION, self::PARALLEL, self::NOTIFICATION, self::AUTOMATIC], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::START => 'Mulai',
            self::APPROVAL => 'Persetujuan',
            self::REVIEW => 'Peninjauan',
            self::CONDITION => 'Kondisi',
            self::PARALLEL => 'Paralel',
            self::NOTIFICATION => 'Notifikasi',
            self::AUTOMATIC => 'Aksi Otomatis',
            self::END => 'Selesai',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
