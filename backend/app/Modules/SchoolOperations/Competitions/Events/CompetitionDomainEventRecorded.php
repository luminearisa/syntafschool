<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Dispatched after commit; consumers read the durable outbox row by id. */
class CompetitionDomainEventRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $outboxId, public readonly string $eventName) {}
}
