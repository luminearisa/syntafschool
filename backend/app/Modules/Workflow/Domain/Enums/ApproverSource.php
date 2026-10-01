<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Asal-usul seorang approver pada sebuah penugasan (§22).
 *
 * Disimpan agar audit dapat menjawab "kenapa orang ini yang muncul?"
 * tanpa menebak dari konfigurasi versi yang mungkin sudah berubah.
 */
enum ApproverSource: string
{
    case USER = 'USER';
    case ROLE = 'ROLE';
    case MANAGER = 'MANAGER';
    case DEPARTMENT = 'DEPARTMENT';
    case COST_CENTER = 'COST_CENTER';
    case POSITION = 'POSITION';
    case DYNAMIC = 'DYNAMIC';
    case DELEGATION = 'DELEGATION';
    case ESCALATION = 'ESCALATION';
    case FALLBACK = 'FALLBACK';
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::USER => 'Pengguna Tertentu',
            self::ROLE => 'Peran Sistem',
            self::MANAGER => 'Atasan Langsung',
            self::DEPARTMENT => 'Kepala Unit/Departemen',
            self::COST_CENTER => 'Manajer Cost Center',
            self::POSITION => 'Jabatan',
            self::DYNAMIC => 'Resolver Dinamis (modul)',
            self::DELEGATION => 'Delegasi',
            self::ESCALATION => 'Eskalasi',
            self::FALLBACK => 'Cadangan',
            self::MANUAL => 'Ditetapkan Manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
