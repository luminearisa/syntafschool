<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\CalendarGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Kalender institusi (§85). Jadwal shalat, kajian, Jumat, dan Ramadan
 * dipublikasikan ke kalender bila modulnya tersedia.
 */
class ErpCalendarGateway implements CalendarGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\LMS\\Domain\\Contracts\\CalendarProviderInterface');
    }

    protected function table(): ?string
    {
        return $this->firstTable(['calendar_events', 'academic_calendar_events', 'lms_calendar_events']);
    }

    public function publish(array $entry): array
    {
        if ($provider = $this->provider()) {
            try {
                foreach (['publish', 'create', 'store'] as $method) {
                    if (method_exists($provider, $method)) {
                        return ['published' => true, 'reference' => $provider->{$method}($entry)];
                    }
                }
            } catch (\Throwable $e) {
                return ['published' => false, 'error' => $e->getMessage()];
            }
        }

        $table = $this->table();
        if ($table === null) {
            return ['published' => false, 'reason' => $this->unavailableReason()];
        }

        $columns = $this->columns($table);
        $data = array_intersect_key($entry, array_flip($columns));
        foreach (['created_at', 'updated_at'] as $stamp) {
            if (in_array($stamp, $columns, true)) {
                $data[$stamp] = now();
            }
        }

        $id = DB::table($table)->insertGetId($data);

        return ['published' => true, 'id' => $id, 'source' => $table];
    }

    public function publishMany(array $entries): array
    {
        $results = [];
        foreach ($entries as $entry) {
            $results[] = $this->publish($entry);
        }

        return $results;
    }

    public function remove(string $reference): bool
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'remove')) {
                    return (bool) $provider->remove($reference);
                }
            } catch (\Throwable) {
            }
        }

        $table = $this->table();
        if ($table === null) {
            return false;
        }

        $columns = $this->columns($table);
        $column = $this->pick($columns, ['external_reference', 'reference', 'source_reference']);
        if ($column === null) {
            return false;
        }

        return DB::table($table)->where($column, $reference)->delete() > 0;
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->table() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Kalender institusi tidak tersedia pada instalasi ini.';
    }
}
