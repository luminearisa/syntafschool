<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Status satu kemunculan step pada sebuah instance (`workflow_step_instances`).
 */
enum StepStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case WAITING_APPROVAL = 'WAITING_APPROVAL';
    case WAITING_REVIEW = 'WAITING_REVIEW';
    case WAITING_FOR_APPROVER = 'WAITING_FOR_APPROVER';
    case COMPLETED = 'COMPLETED';
    case RETURNED = 'RETURNED';
    case REJECTED = 'REJECTED';
    case SKIPPED = 'SKIPPED';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';

    /** Step masih menunggu sesuatu (manusia atau penetapan approver). */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::PENDING, self::ACTIVE, self::WAITING_APPROVAL,
            self::WAITING_REVIEW, self::WAITING_FOR_APPROVER,
        ], true);
    }

    public function isFinished(): bool
    {
        return ! $this->isOpen();
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Belum Aktif',
            self::ACTIVE => 'Aktif',
            self::WAITING_APPROVAL => 'Menunggu Persetujuan',
            self::WAITING_REVIEW => 'Menunggu Peninjauan',
            self::WAITING_FOR_APPROVER => 'Menunggu Approver',
            self::COMPLETED => 'Selesai',
            self::RETURNED => 'Dikembalikan',
            self::REJECTED => 'Ditolak',
            self::SKIPPED => 'Dilewati',
            self::CANCELLED => 'Dibatalkan',
            self::FAILED => 'Gagal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
