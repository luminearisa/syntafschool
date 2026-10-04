<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Aksi yang wajib diaudit (§51). */
enum ProcurementAuditAction: string
{
    use EnumOptions;

    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case SUBMIT = 'SUBMIT';
    case APPROVE = 'APPROVE';
    case REJECT = 'REJECT';
    case RETURN = 'RETURN';
    case CANCEL = 'CANCEL';
    case ISSUE = 'ISSUE';
    case AMEND = 'AMEND';
    case RECEIVE = 'RECEIVE';
    case INSPECT = 'INSPECT';
    case MATCH = 'MATCH';
    case OVERRIDE = 'OVERRIDE';
    case HANDOFF = 'HANDOFF';
    case RETRY = 'RETRY';
    case CLOSE = 'CLOSE';
    case REOPEN = 'REOPEN';

    public function label(): string
    {
        return match ($this) {
            self::CREATE => 'Dibuat',
            self::UPDATE => 'Diubah',
            self::DELETE => 'Dihapus',
            self::SUBMIT => 'Diajukan',
            self::APPROVE => 'Disetujui',
            self::REJECT => 'Ditolak',
            self::RETURN => 'Dikembalikan',
            self::CANCEL => 'Dibatalkan',
            self::ISSUE => 'Diterbitkan',
            self::AMEND => 'Diamandemen',
            self::RECEIVE => 'Diterima',
            self::INSPECT => 'Diinspeksi',
            self::MATCH => 'Dicocokkan',
            self::OVERRIDE => 'Override',
            self::HANDOFF => 'Diserahkan',
            self::RETRY => 'Coba Ulang',
            self::CLOSE => 'Ditutup',
            self::REOPEN => 'Dibuka Kembali',
        };
    }
}
