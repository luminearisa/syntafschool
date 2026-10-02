<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Application\Services;

use App\Modules\SchoolOperations\Events\Domain\Support\EventContext;
use Illuminate\Support\Facades\DB;

/**
 * Bus domain event modul Event (§44).
 *
 * Dua hal terjadi untuk tiap peristiwa: baris dicatat di `event_domain_events`
 * sebagai riwayat yang dapat ditelusuri, lalu diteruskan ke dispatcher Laravel
 * agar listener modul lain (Activity, Asset, Religious, Transportation) dapat
 * menanggapinya.
 *
 * Pencatatan mendahului penyiaran. Bila pencatatan gagal, peristiwa tetap
 * disiarkan — kehilangan riwayat lebih ringan daripada kehilangan reaksi modul
 * lain. Kegagalannya tetap tercatat lewat EventContext::safe().
 */
class EventDomainEventBus
{
    public function __construct(private readonly EventContext $context) {}

    /**
     * Catat dan siarkan satu peristiwa domain.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $channels
     */
    public function publish(
        string $eventName,
        string $aggregateType,
        ?int $aggregateId = null,
        array $payload = [],
        ?int $eventId = null,
        array $channels = [],
    ): void {
        $this->context->safe(fn () => DB::table('event_domain_events')->insert([
            'event_id' => $eventId,
            'event_name' => $eventName,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'channels' => $channels === [] ? null : json_encode($channels, JSON_UNESCAPED_UNICODE),
            'published' => true,
            'published_at' => now(),
            'consumer_count' => 0,
            'created_at' => now(),
        ]));

        // Penerusan ke dispatcher Laravel. Dinamai dengan konvensi modul ini
        // supaya listener dapat mendaftar tanpa bergantung pada kelas event
        // yang harus ada lebih dulu di setiap modul pengonsumsi.
        $this->context->safe(fn () => event($eventName, [$payload, $aggregateType, $aggregateId, $eventId]));
    }

    /**
     * Daftar peristiwa yang tercatat untuk satu event, terbaru lebih dulu.
     *
     * @return list<array<string, mixed>>
     */
    public function forEvent(int $eventId, int $limit = 100): array
    {
        return DB::table('event_domain_events')
            ->where('event_id', $eventId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
