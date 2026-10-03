<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only evidence record. Deliberately has no soft-delete/update timestamp behavior. */
class CompetitionAuditLog extends Model
{
    protected $table = 'competition_audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    public function save(array $options = [])
    {
        if ($this->exists && $this->isDirty()) {
            throw new LogicException('Competition audit records are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Competition audit records cannot be deleted.');
    }

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
