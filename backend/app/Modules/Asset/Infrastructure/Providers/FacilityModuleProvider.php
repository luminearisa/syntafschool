<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Application\Services\FacilityBookingService;
use App\Modules\Asset\Application\Services\FacilityService;
use App\Modules\Asset\Domain\Contracts\FacilityProviderInterface;
use App\Modules\Asset\Models\Facility;

/**
 * Kontrak fasilitas untuk modul lain (Akademik, Event, Kegiatan).
 *
 * Pemeriksaan benturan TETAP dilakukan di sini: pemanggil tidak pernah diberi
 * kemampuan menyimpan booking langsung, sehingga tidak ada modul yang dapat
 * "melewati" pemeriksaan jadwal.
 */
class FacilityModuleProvider implements FacilityProviderInterface
{
    public function info(int $facilityId): ?array
    {
        $facility = Facility::query()->with('location:id,name')->find($facilityId);

        if ($facility === null) {
            return null;
        }

        return [
            'id' => $facility->id,
            'facility_code' => $facility->facility_code,
            'name' => $facility->name,
            'status' => $facility->status?->value ?? (string) $facility->status,
            'capacity_total' => $facility->capacity_total,
            'location' => $facility->location?->name,
        ];
    }

    public function availability(int $facilityId, string $from, string $to): array
    {
        $facility = Facility::query()->find($facilityId);

        if ($facility === null) {
            return ['available' => false, 'reasons' => ['Fasilitas tidak ditemukan.']];
        }

        return app(FacilityService::class)->availability($facility, \Illuminate\Support\Carbon::parse($from), \Illuminate\Support\Carbon::parse($to));
    }

    public function book(array $payload): array
    {
        $facility = Facility::query()->findOrFail((int) ($payload['facility_id'] ?? 0));
        $actor = \App\Models\User::query()->findOrFail((int) ($payload['actor_id'] ?? 0));

        $booking = app(FacilityBookingService::class)->book($actor, $facility, [
            'title' => $payload['title'] ?? 'Pemesanan fasilitas',
            'purpose' => $payload['purpose'] ?? null,
            'attendee_count' => $payload['attendee_count'] ?? null,
            'start_at' => $payload['start_at'],
            'end_at' => $payload['end_at'],
            'source' => $payload['source'] ?? 'EVENT',
            'event_reference' => $payload['event_reference'] ?? null,
            'activity_reference' => $payload['activity_reference'] ?? null,
            'academic_reference' => $payload['academic_reference'] ?? null,
        ]);

        return ['status' => $booking->status?->value ?? (string) $booking->status, 'booking_id' => $booking->id, 'booking_number' => $booking->booking_number];
    }

    public function close(array $payload): array
    {
        $facility = Facility::query()->findOrFail((int) ($payload['facility_id'] ?? 0));
        $actor = \App\Models\User::query()->findOrFail((int) ($payload['actor_id'] ?? 0));

        return app(FacilityService::class)->close($actor, $facility, (string) ($payload['reason'] ?? 'Ditutup dari modul lain.'));
    }

    public function reopen(int $facilityId, ?string $reason = null): array
    {
        $facility = Facility::query()->findOrFail($facilityId);
        $actor = \App\Models\User::query()->orderBy('id')->firstOrFail();

        $facility = app(FacilityService::class)->reopen($actor, $facility, $reason);

        return ['status' => $facility->status?->value ?? (string) $facility->status];
    }
}
