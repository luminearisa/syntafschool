<?php

namespace App\Modules\Hrd\Domain\Enums;

/**
 * Status permintaan HR (§45) — sama bentuknya dengan transaksi karena keduanya
 * melewati Workflow Engine yang sama. Yang berbeda hanya efeknya: permintaan
 * mengubah data pegawai secara terbatas, transaksi mengubah penugasan/status.
 */
enum HrRequestStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case REVIEW = 'review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Diajukan',
            self::REVIEW => 'Ditinjau',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
            self::COMPLETED => 'Selesai',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::DRAFT, self::SUBMITTED, self::REVIEW], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::SUBMITTED, self::CANCELLED],
            self::SUBMITTED => [self::REVIEW, self::APPROVED, self::REJECTED, self::CANCELLED],
            self::REVIEW => [self::APPROVED, self::REJECTED, self::CANCELLED],
            self::APPROVED => [self::COMPLETED, self::CANCELLED],
            self::REJECTED, self::CANCELLED, self::COMPLETED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
