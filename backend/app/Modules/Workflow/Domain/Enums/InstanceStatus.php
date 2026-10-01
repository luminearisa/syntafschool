<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Status instance workflow (§17).
 *
 * WAITING_FOR_APPROVER adalah keadaan jujur ketika resolusi approver kosong
 * (§58): request tidak hilang, tidak dilewati diam-diam, dan tetap muncul di
 * dasbor admin sebagai pekerjaan yang harus diselesaikan manusia.
 */
enum InstanceStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case WAITING_APPROVAL = 'WAITING_APPROVAL';
    case WAITING_REVIEW = 'WAITING_REVIEW';
    case WAITING_FOR_APPROVER = 'WAITING_FOR_APPROVER';
    case RETURNED = 'RETURNED';
    case REJECTED = 'REJECTED';
    case APPROVED = 'APPROVED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case FAILED = 'FAILED';

    /** Status yang berarti request masih hidup dan bisa diproses. */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::DRAFT, self::SUBMITTED, self::IN_PROGRESS, self::WAITING_APPROVAL,
            self::WAITING_REVIEW, self::WAITING_FOR_APPROVER, self::RETURNED,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED, self::CANCELLED, self::EXPIRED, self::FAILED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Terkirim',
            self::IN_PROGRESS => 'Berjalan',
            self::WAITING_APPROVAL => 'Menunggu Persetujuan',
            self::WAITING_REVIEW => 'Menunggu Peninjauan',
            self::WAITING_FOR_APPROVER => 'Menunggu Penetapan Approver',
            self::RETURNED => 'Dikembalikan untuk Revisi',
            self::REJECTED => 'Ditolak',
            self::APPROVED => 'Disetujui',
            self::CANCELLED => 'Dibatalkan',
            self::EXPIRED => 'Kedaluwarsa',
            self::FAILED => 'Gagal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
