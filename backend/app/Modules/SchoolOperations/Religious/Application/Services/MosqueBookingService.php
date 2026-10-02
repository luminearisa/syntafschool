<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{FacilityGatewayInterface, WorkflowGatewayInterface};
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{Mosque, MosqueArea, MosqueBooking};
use Illuminate\Validation\ValidationException;

/**
 * Pemesanan masjid & area (§59, §60).
 *
 * Aturan 8 & 9: pemesanan masjid/area tidak boleh bertumpang waktu.
 * Modul Facility/Workflow dipakai untuk persetujuan, bukan dibangun ulang.
 */
class MosqueBookingService
{
    public function __construct(
        protected ReligiousContext $context,
        protected FacilityGatewayInterface $facilities,
        protected WorkflowGatewayInterface $workflow,
    ) {
    }

    public function request(array $payload): MosqueBooking
    {
        $mosqueId = (int) ($payload['mosque_id'] ?? 0);
        if (! $mosqueId) {
            throw ValidationException::withMessages(['mosque_id' => 'Pemesanan wajib menyebut masjid.']);
        }

        $startsAt = $payload['starts_at'] ?? null;
        $endsAt = $payload['ends_at'] ?? null;
        if (! $startsAt || ! $endsAt) {
            throw ValidationException::withMessages(['starts_at' => 'Waktu mulai dan selesai wajib diisi.']);
        }
        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages(['ends_at' => 'Waktu selesai harus setelah waktu mulai.']);
        }

        $conflict = $this->detectConflict($mosqueId, $payload['area_id'] ?? null, $startsAt, $endsAt);
        if ($conflict) {
            throw ValidationException::withMessages([
                'starts_at' => 'Bentrok jadwal: '.$conflict.' (Aturan 8/9).',
            ]);
        }

        $booking = MosqueBooking::query()->create([
            'mosque_id' => $mosqueId,
            'area_id' => $payload['area_id'] ?? null,
            'code' => $payload['code'] ?? $this->context->nextCode('BKG', 'mosque_bookings'),
            'title' => $payload['title'],
            'purpose' => $payload['purpose'] ?? null,
            'booking_type' => strtoupper((string) ($payload['booking_type'] ?? 'INTERNAL')),
            'requested_by' => $this->context->actorId(),
            'requester_name' => $payload['requester_name'] ?? null,
            'contact_phone' => $payload['contact_phone'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'expected_attendees' => $payload['expected_attendees'] ?? null,
            'status' => 'REQUESTED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        // Minta persetujuan lewat Workflow bila tersedia (§79).
        if (! empty($payload['require_approval'])) {
            $started = $this->workflow->start([
                'type' => 'MOSQUE_BOOKING',
                'reference' => $booking->code,
                'subject_id' => $booking->getKey(),
                'requested_by' => $this->context->actorId(),
                'title' => $booking->title,
            ]);
            if (! empty($started['instance_id'])) {
                $booking->forceFill(['workflow_instance_id' => $started['instance_id']])->save();
            }
            $booking->setAttribute('workflow', $started);
        }

        $this->context->audit('religious.booking.requested', $booking, [
            'mosque_id' => $mosqueId, 'area_id' => $booking->area_id,
        ]);

        return $booking;
    }

    /**
     * Deteksi bentrok pemesanan (§60). Mengembalikan deskripsi bentrok
     * pada pemesanan internal, atau pada modul Facility bila tersedia.
     */
    public function detectConflict(int $mosqueId, ?int $areaId, string $startsAt, string $endsAt, ?int $ignoreId = null): ?string
    {
        $existing = MosqueBooking::query()
            ->where('mosque_id', $mosqueId)
            ->when($areaId, fn ($q) => $q->where('area_id', $areaId))
            ->whereNotIn('status', ['CANCELLED', 'REJECTED'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->first();

        if ($existing) {
            return 'dengan "'.$existing->title.'" ('.$existing->code.')';
        }

        // Cek juga bentrok jadwal shalat/kegiatan tetap pada masjid yang sama.
        $prayerConflict = $this->context->safe(fn () => \App\Modules\SchoolOperations\Religious\Models\ReligiousActivitySession::query()
            ->where('mosque_id', $mosqueId)
            ->when($areaId, fn ($q) => $q->where('area_id', $areaId))
            ->whereDate('session_date', substr($startsAt, 0, 10))
            ->where('start_time', '<', substr($endsAt, 11, 5))
            ->where('end_time', '>', substr($startsAt, 11, 5))
            ->first());

        if ($prayerConflict) {
            return 'dengan kegiatan "'.($prayerConflict->title ?? $prayerConflict->topic).'"';
        }

        return null;
    }

    public function approve(int $bookingId, array $payload = []): MosqueBooking
    {
        $booking = MosqueBooking::query()->findOrFail($bookingId);

        $booking->forceFill([
            'status' => 'APPROVED',
            'approval_status' => 'APPROVED',
            'approved_by' => $this->context->actorId(),
            'approved_at' => now(),
        ])->save();

        // Teruskan ke modul fasilitas bila pemesanan memakai fasilitas institusi.
        $facilityResult = null;
        if (! empty($payload['facility_id'])) {
            $facilityResult = $this->facilities->requestBooking([
                'facility_id' => $payload['facility_id'],
                'reference' => $booking->code,
                'title' => $booking->title,
                'starts_at' => $booking->starts_at,
                'ends_at' => $booking->ends_at,
                'attendees' => $booking->expected_attendees,
            ]);

            if (! empty($facilityResult['reference'])) {
                $booking->forceFill(['facility_reference' => $facilityResult['reference']])->save();
            }
        }

        if ($booking->workflow_instance_id) {
            $this->workflow->approve($booking->workflow_instance_id, ['note' => $payload['note'] ?? null]);
        }

        $this->context->audit('religious.booking.approved', $booking, ['facility' => $facilityResult], $payload['note'] ?? null);

        return $booking;
    }

    public function reject(int $bookingId, string $reason): MosqueBooking
    {
        $booking = MosqueBooking::query()->findOrFail($bookingId);

        $booking->forceFill([
            'status' => 'REJECTED',
            'approval_status' => 'REJECTED',
            'approved_by' => $this->context->actorId(),
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        if ($booking->workflow_instance_id) {
            $this->workflow->reject($booking->workflow_instance_id, $reason);
        }

        $this->context->audit('religious.booking.rejected', $booking, [], $reason);

        return $booking;
    }

    /** Ketersediaan masjid/area pada rentang waktu (§60). */
    public function availability(int $mosqueId, ?int $areaId, string $date): array
    {
        $areas = MosqueArea::query()->where('mosque_id', $mosqueId)
            ->when($areaId, fn ($q) => $q->whereKey($areaId))->where('bookable', true)->get();

        $bookings = MosqueBooking::query()->where('mosque_id', $mosqueId)
            ->when($areaId, fn ($q) => $q->where('area_id', $areaId))
            ->whereDate('starts_at', $date)
            ->whereNotIn('status', ['CANCELLED', 'REJECTED'])->get();

        $activities = $this->context->safe(fn () => \App\Modules\SchoolOperations\Religious\Models\ReligiousActivitySession::query()
            ->where('mosque_id', $mosqueId)->whereDate('session_date', $date)->get(), collect());

        return [
            'date' => $date,
            'mosque_id' => $mosqueId,
            'areas' => $areas->map(fn (MosqueArea $a): array => [
                'id' => $a->getKey(),
                'name' => $a->name,
                'capacity' => $a->capacity,
                'busy_slots' => $bookings->where('area_id', $a->getKey())
                    ->map(fn (MosqueBooking $b): array => ['from' => $b->starts_at, 'to' => $b->ends_at, 'title' => $b->title])
                    ->values()->all(),
            ])->all(),
            'activities' => $activities->map(fn ($s): array => [
                'title' => $s->title ?? $s->topic, 'from' => $s->start_time, 'to' => $s->end_time,
            ])->all(),
            'conflicts' => $bookings->count() + $activities->count(),
        ];
    }

    public function list(array $filters = []): array
    {
        return MosqueBooking::query()
            ->when(! empty($filters['mosque_id']), fn ($q) => $q->where('mosque_id', $filters['mosque_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', strtoupper((string) $filters['status'])))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('starts_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('starts_at', '<=', $filters['to']))
            ->orderByDesc('starts_at')->limit((int) ($filters['limit'] ?? 100))->get()
            ->map(fn (MosqueBooking $b): array => [
                'id' => $b->getKey(), 'code' => $b->code, 'title' => $b->title,
                'mosque_id' => $b->mosque_id, 'area_id' => $b->area_id,
                'starts_at' => $b->starts_at?->toIso8601String(),
                'ends_at' => $b->ends_at?->toIso8601String(),
                'expected_attendees' => $b->expected_attendees,
                'status' => $b->status, 'approval_status' => $b->approval_status,
            ])->all();
    }
}
