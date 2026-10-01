<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Keputusan yang dapat diambil seorang aktor atas sebuah request (§5, §6, §32).
 *
 * Perbedaan REJECT dan RETURN adalah inti modul ini (§34):
 *  - REJECT  → request berhenti sebagai REJECTED.
 *  - RETURN  → request kembali ke requester untuk diperbaiki, lalu disubmit ulang.
 */
enum DecisionType: string
{
    case APPROVE = 'APPROVE';
    case REJECT = 'REJECT';
    case RETURN = 'RETURN';
    case REQUEST_CHANGES = 'REQUEST_CHANGES';
    case DELEGATE = 'DELEGATE';
    case SKIP = 'SKIP';
    case CANCEL = 'CANCEL';
    case REASSIGN = 'REASSIGN';
    case ESCALATE = 'ESCALATE';
    case RESUBMIT = 'RESUBMIT';

    /** Keputusan yang menghentikan alur saat ini. */
    public function isBlocking(): bool
    {
        return in_array($this, [self::REJECT, self::RETURN, self::REQUEST_CHANGES], true);
    }

    /** Keputusan yang mengembalikan request ke requester (bukan menutupnya). */
    public function returnsToRequester(): bool
    {
        return in_array($this, [self::RETURN, self::REQUEST_CHANGES], true);
    }

    /** Keputusan yang dihitung sebagai suara setuju terhadap quorum. */
    public function isApproval(): bool
    {
        return $this === self::APPROVE;
    }

    public function label(): string
    {
        return match ($this) {
            self::APPROVE => 'Setujui',
            self::REJECT => 'Tolak',
            self::RETURN => 'Kembalikan untuk Revisi',
            self::REQUEST_CHANGES => 'Minta Perubahan',
            self::DELEGATE => 'Delegasikan',
            self::SKIP => 'Lewati Step',
            self::CANCEL => 'Batalkan',
            self::REASSIGN => 'Alihkan Approver',
            self::ESCALATE => 'Eskalasi',
            self::RESUBMIT => 'Submit Ulang',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
