<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Events\CompetitionDomainEventRecorded;
use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAuditLog;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionDomainEvent;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Append-only audit and transactional-outbox writer for critical operations. */
class CompetitionAuditService
{
    public function record(
        string $action,
        ?User $actor,
        Model|array|null $entity = null,
        ?CompetitionEdition $edition = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): CompetitionAuditLog {
        $edition ??= $this->editionFor($entity);
        $competition = $entity instanceof Competition ? $entity : $edition?->competition;
        $request = app()->bound('request') ? request() : null;

        $log = CompetitionAuditLog::query()->create([
            'competition_id' => $competition?->id,
            'edition_id' => $edition?->id,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'entity_type' => $entity instanceof Model ? $entity::class : null,
            'entity_id' => $entity instanceof Model ? $entity->getKey() : null,
            'old_value' => $before,
            'new_value' => $after ?? (is_array($entity) ? $entity : null),
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
            'campus_id' => $edition?->campus_id ?? $competition?->campus_id,
            'created_at' => now(),
        ]);

        // Outbox row is persisted in the caller's transaction. It is never
        // treated as authoritative business state and can be retried safely.
        $outbox = CompetitionDomainEvent::query()->create([
            'edition_id' => $edition?->id,
            'event_name' => $action,
            'aggregate_type' => $entity instanceof Model ? $entity::class : null,
            'aggregate_id' => $entity instanceof Model ? $entity->getKey() : null,
            'payload' => [
                'competition_id' => $competition?->id,
                'edition_id' => $edition?->id,
                'actor_id' => $actor?->id,
                'reason' => $reason,
                'data' => $after,
            ],
            'channels' => [],
            'published' => false,
            'created_at' => now(),
        ]);

        DB::afterCommit(fn () => event(new CompetitionDomainEventRecorded((int) $outbox->id, $action)));

        return $log;
    }

    private function editionFor(Model|array|null $entity): ?CompetitionEdition
    {
        if ($entity instanceof CompetitionEdition) {
            return $entity;
        }

        if ($entity instanceof Model) {
            $editionId = $entity->getAttribute('edition_id');
            if ($editionId) {
                return CompetitionEdition::query()->find($editionId);
            }

            $categoryId = $entity->getAttribute('category_id');
            if ($categoryId) {
                return CompetitionCategory::query()
                    ->whereKey($categoryId)->first()?->competitionEdition;
            }
        }

        return null;
    }
}
