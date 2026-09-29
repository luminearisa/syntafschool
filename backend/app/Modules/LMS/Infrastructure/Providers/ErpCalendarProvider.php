<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\AcademicEvent;
use App\Modules\LMS\Domain\Contracts\CalendarProviderInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Integrated Mode: agenda akademik dari `academic_events`.
 *
 * Read-only. Tenggat aktivitas dan jadwal kelas langsung tetap dihitung LMS
 * sendiri (`LmsCalendarService`) — provider ini hanya menambahkan kalender
 * akademik resmi (§4).
 */
class ErpCalendarProvider implements CalendarProviderInterface
{
    public function key(): string
    {
        return 'erp';
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('academic_events');
    }

    public function eventsBetween(string $from, string $to, ?int $classId = null, ?int $academicYearId = null): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return AcademicEvent::query()
            ->with('eventType:id,name')
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->orderBy('start_date')
            ->get()
            ->map(fn (AcademicEvent $event) => [
                'id' => 'academic-event:'.$event->id,
                'title' => (string) $event->title,
                'start_at' => $event->start_date?->toDateString(),
                'end_at' => $event->end_date?->toDateString(),
                'type' => $event->eventType?->name,
                'location' => $event->location,
                'all_day' => true,
                'source' => 'erp',
            ])
            ->all();
    }
}
