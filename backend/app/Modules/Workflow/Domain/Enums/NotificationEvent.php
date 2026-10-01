<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Peristiwa notifikasi workflow (§67).
 */
enum NotificationEvent: string
{
    case APPROVAL_REQUESTED = 'APPROVAL_REQUESTED';
    case APPROVAL_REMINDER = 'APPROVAL_REMINDER';
    case APPROVAL_DUE_SOON = 'APPROVAL_DUE_SOON';
    case APPROVAL_OVERDUE = 'APPROVAL_OVERDUE';
    case APPROVAL_ESCALATED = 'APPROVAL_ESCALATED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case RETURNED = 'RETURNED';
    case RESUBMITTED = 'RESUBMITTED';
    case CANCELLED = 'CANCELLED';
    case WORKFLOW_COMPLETED = 'WORKFLOW_COMPLETED';
    case WORKFLOW_FAILED = 'WORKFLOW_FAILED';
    case ASSIGNMENT_CHANGED = 'ASSIGNMENT_CHANGED';

    public function label(): string
    {
        return match ($this) {
            self::APPROVAL_REQUESTED => 'Permintaan Persetujuan',
            self::APPROVAL_REMINDER => 'Pengingat Persetujuan',
            self::APPROVAL_DUE_SOON => 'Segera Jatuh Tempo',
            self::APPROVAL_OVERDUE => 'Melewati SLA',
            self::APPROVAL_ESCALATED => 'Dieskalasi',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::RETURNED => 'Dikembalikan untuk Revisi',
            self::RESUBMITTED => 'Disubmit Ulang',
            self::CANCELLED => 'Dibatalkan',
            self::WORKFLOW_COMPLETED => 'Workflow Selesai',
            self::WORKFLOW_FAILED => 'Workflow Gagal',
            self::ASSIGNMENT_CHANGED => 'Approver Berubah',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
