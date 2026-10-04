<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status penyerahan ke modul Accounting (§33). */
enum AccountingHandoffStatus: string
{
    use EnumOptions;

    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
    case POSTED = 'POSTED';
    case FAILED = 'FAILED';
    case RETRYING = 'RETRYING';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::SENT => 'Terkirim',
            self::ACCEPTED => 'Diterima',
            self::REJECTED => 'Ditolak',
            self::POSTED => 'Terposting',
            self::FAILED => 'Gagal',
            self::RETRYING => 'Mencoba Ulang',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::POSTED, self::REJECTED], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::FAILED, self::REJECTED], true);
    }
}
