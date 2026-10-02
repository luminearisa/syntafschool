<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\EventGatewayInterface;

class EloquentEventGateway implements EventGatewayInterface
{
    use GatewaySupport;

    public function find(int $eventId): ?array
    {
        $provider = $this->delegate('App\Modules\Activity\Domain\Contracts\EventProviderInterface');
        if ($provider && method_exists($provider, 'find')) {
            try {
                $result = $provider->find($eventId);
                if (is_array($result) && $result !== []) {
                    return $result + ['source' => 'activity'];
                }
            } catch (\Throwable) {
            }
        }

        foreach (['academic_events', 'activity_programs', 'activity_competitions'] as $table) {
            if (! $this->has($table)) {
                continue;
            }
            $row = $this->row($table, $eventId);
            if ($row) {
                $title = $this->pick($this->columns($table), ['title', 'name', 'program_name']);

                return [
                    'id' => (int) $row['id'],
                    'title' => $title ? ($row[$title] ?? null) : null,
                    'start_date' => $row['start_date'] ?? ($row['start_at'] ?? null),
                    'end_date' => $row['end_date'] ?? ($row['end_at'] ?? null),
                    'location' => $row['location'] ?? null,
                    'source' => $table,
                ];
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->has('academic_events') || $this->has('activity_programs');
    }
}
