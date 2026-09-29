<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Jenis jejak audit nilai (§19, §53). Riwayat **immutable**: hanya boleh
 * ditambah, tidak pernah diubah atau dihapus.
 */
enum GradeHistoryAction: string
{
    case CREATED = 'CREATED';
    case UPDATED = 'UPDATED';
    case CORRECTED = 'CORRECTED';
    case EXEMPTED = 'EXEMPTED';
    case UNEXEMPTED = 'UNEXEMPTED';
    case RETURNED = 'RETURNED';
    case RELEASED = 'RELEASED';
    case REVOKED = 'REVOKED';
    case LOCKED = 'LOCKED';
    case UNLOCKED = 'UNLOCKED';
    case RECALCULATED = 'RECALCULATED';
    case SYNCED = 'SYNCED';
    case SYNC_FAILED = 'SYNC_FAILED';
    case REVIEW_REQUESTED = 'REVIEW_REQUESTED';
    case REVIEW_DECIDED = 'REVIEW_DECIDED';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Dibuat',
            self::UPDATED => 'Diubah',
            self::CORRECTED => 'Dikoreksi',
            self::EXEMPTED => 'Dibebaskan',
            self::UNEXEMPTED => 'Pembebasan dibatalkan',
            self::RETURNED => 'Dikembalikan',
            self::RELEASED => 'Dirilis',
            self::REVOKED => 'Rilis dibatalkan',
            self::LOCKED => 'Dikunci',
            self::UNLOCKED => 'Dibuka kunci',
            self::RECALCULATED => 'Dihitung ulang',
            self::SYNCED => 'Disinkronkan',
            self::SYNC_FAILED => 'Sinkronisasi gagal',
            self::REVIEW_REQUESTED => 'Banding diajukan',
            self::REVIEW_DECIDED => 'Banding diputuskan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
