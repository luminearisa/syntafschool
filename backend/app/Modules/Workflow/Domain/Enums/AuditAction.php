<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Aksi yang dicatat pada `workflow_audit_logs` (§48).
 *
 * Daftar ini sengaja granular: auditor harus dapat menjawab "siapa mengubah
 * apa, kapan, dengan alasan apa" tanpa membaca tabel lain.
 */
enum AuditAction: string
{
    case DEFINITION_CREATED = 'DEFINITION_CREATED';
    case DEFINITION_UPDATED = 'DEFINITION_UPDATED';
    case VERSION_CREATED = 'VERSION_CREATED';
    case VERSION_UPDATED = 'VERSION_UPDATED';
    case VERSION_VALIDATED = 'VERSION_VALIDATED';
    case VERSION_PUBLISHED = 'VERSION_PUBLISHED';
    case VERSION_ACTIVATED = 'VERSION_ACTIVATED';
    case VERSION_ARCHIVED = 'VERSION_ARCHIVED';
    case VERSION_SIMULATED = 'VERSION_SIMULATED';
    case INSTANCE_CREATED = 'INSTANCE_CREATED';
    case INSTANCE_SUBMITTED = 'INSTANCE_SUBMITTED';
    case INSTANCE_REVISED = 'INSTANCE_REVISED';
    case INSTANCE_RESUBMITTED = 'INSTANCE_RESUBMITTED';
    case INSTANCE_CANCELLED = 'INSTANCE_CANCELLED';
    case INSTANCE_COMPLETED = 'INSTANCE_COMPLETED';
    case INSTANCE_REJECTED = 'INSTANCE_REJECTED';
    case INSTANCE_RETURNED = 'INSTANCE_RETURNED';
    case INSTANCE_FAILED = 'INSTANCE_FAILED';
    case INSTANCE_EXPIRED = 'INSTANCE_EXPIRED';
    case STEP_ACTIVATED = 'STEP_ACTIVATED';
    case STEP_COMPLETED = 'STEP_COMPLETED';
    case STEP_SKIPPED = 'STEP_SKIPPED';
    case STEP_BLOCKED = 'STEP_BLOCKED';
    case APPROVAL_ASSIGNED = 'APPROVAL_ASSIGNED';
    case DECISION_RECORDED = 'DECISION_RECORDED';
    case APPROVER_REASSIGNED = 'APPROVER_REASSIGNED';
    case DELEGATION_CREATED = 'DELEGATION_CREATED';
    case DELEGATION_REVOKED = 'DELEGATION_REVOKED';
    case DELEGATION_ACTIVATED = 'DELEGATION_ACTIVATED';
    case ESCALATION_APPLIED = 'ESCALATION_APPLIED';
    case SLA_BREACHED = 'SLA_BREACHED';
    case SLA_COMPLETED = 'SLA_COMPLETED';
    case NOTIFICATION_SENT = 'NOTIFICATION_SENT';
    case NOTIFICATION_SKIPPED = 'NOTIFICATION_SKIPPED';
    case ACTION_EXECUTED = 'ACTION_EXECUTED';
    case ACTION_FAILED = 'ACTION_FAILED';
    case CONTEXT_CAPTURED = 'CONTEXT_CAPTURED';
    case COMMENT_ADDED = 'COMMENT_ADDED';
    case ATTACHMENT_LINKED = 'ATTACHMENT_LINKED';
    case CALENDAR_UPDATED = 'CALENDAR_UPDATED';
    case DIRECTORY_UPDATED = 'DIRECTORY_UPDATED';

    public function label(): string
    {
        return str_replace('_', ' ', ucfirst(strtolower($this->value)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
