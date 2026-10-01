<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Status penugasan seorang approver (`workflow_approvals`).
 */
enum ApprovalStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case RETURNED = 'RETURNED';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case SKIPPED = 'SKIPPED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case REASSIGNED = 'REASSIGNED';

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public static function fromDecision(DecisionType $decision): self
    {
        return match ($decision) {
            DecisionType::APPROVE => self::APPROVED,
            DecisionType::REJECT => self::REJECTED,
            DecisionType::RETURN => self::RETURNED,
            DecisionType::REQUEST_CHANGES => self::CHANGES_REQUESTED,
            DecisionType::SKIP => self::SKIPPED,
            DecisionType::CANCEL => self::CANCELLED,
            DecisionType::REASSIGN => self::REASSIGNED,
            default => self::PENDING,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::RETURNED => 'Dikembalikan',
            self::CHANGES_REQUESTED => 'Diminta Perubahan',
            self::SKIPPED => 'Dilewati',
            self::CANCELLED => 'Dibatalkan',
            self::EXPIRED => 'Kedaluwarsa',
            self::REASSIGNED => 'Dialihkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
