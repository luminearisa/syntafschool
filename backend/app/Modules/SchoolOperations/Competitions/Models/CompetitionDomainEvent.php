<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;

/** Transactional outbox row. Rows are retained for retry/reconciliation. */
class CompetitionDomainEvent extends Model
{
    protected $table = 'competition_domain_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'channels' => 'array',
            'published' => 'boolean',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
