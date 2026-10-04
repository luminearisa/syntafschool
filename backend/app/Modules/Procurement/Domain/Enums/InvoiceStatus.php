<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status invoice vendor (§28–§33). */
enum InvoiceStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case RECEIVED = 'RECEIVED';
    case UNDER_MATCHING = 'UNDER_MATCHING';
    case MATCHED = 'MATCHED';
    case MISMATCH = 'MISMATCH';
    case EXCEPTION = 'EXCEPTION';
    case APPROVED = 'APPROVED';
    case SENT_TO_ACCOUNTING = 'SENT_TO_ACCOUNTING';
    case POSTED = 'POSTED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::RECEIVED => 'Diterima',
            self::UNDER_MATCHING => 'Sedang Dicocokkan',
            self::MATCHED => 'Cocok',
            self::MISMATCH => 'Tidak Cocok',
            self::EXCEPTION => 'Pengecualian',
            self::APPROVED => 'Disetujui',
            self::SENT_TO_ACCOUNTING => 'Dikirim ke Akuntansi',
            self::POSTED => 'Terposting',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** Boleh diserahkan ke Accounting (§52.6). */
    public function isHandoffReady(): bool
    {
        return $this === self::APPROVED;
    }
}
