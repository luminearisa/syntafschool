<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Support;

/** Shared lifecycle values; persisted as extensible strings, not DB enums. */
final class CompetitionStatus
{
    public const EDITION = [
        'DRAFT', 'PLANNING', 'REGISTRATION_OPEN', 'REGISTRATION_CLOSED',
        'SELECTION', 'TECHNICAL_MEETING', 'RUNNING', 'FINAL', 'COMPLETED',
        'CANCELLED', 'ARCHIVED',
    ];

    public const REGISTRATION = [
        'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION', 'ELIGIBILITY_FAILED',
        'DOCUMENT_FAILED', 'WAITLISTED', 'APPROVED', 'REJECTED', 'CANCELLED', 'WITHDRAWN',
    ];

    public const SCORE = ['DRAFT', 'SUBMITTED', 'LOCKED', 'AMENDED', 'VOID'];

    public const MATCH = ['SCHEDULED', 'CHECK_IN', 'READY', 'RUNNING', 'PAUSED', 'COMPLETED', 'POSTPONED', 'CANCELLED', 'VOID'];

    private function __construct() {}
}
