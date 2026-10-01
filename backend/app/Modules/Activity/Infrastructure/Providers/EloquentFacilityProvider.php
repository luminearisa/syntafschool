<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\Room;
use App\Modules\Activity\Domain\Contracts\FacilityProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fasilitas dari master `rooms` + `room_bookings` (§30, §64, §101).
 *
 * Tabel pemesanan ruang dipakai apa adanya lewat query builder: ERP ini tidak
 * memiliki model khusus untuknya, dan modul aktivitas memang tidak perlu
 * memiliki model itu — yang dibutuhkan hanya ketersediaan dan pemesanan.
 *
 * Bentrok dilaporkan apa adanya, termasuk menyebut pemesanan yang menghalangi,
 * supaya pengelola aktivitas tahu apa yang harus dinegosiasikan.
 *
 * Status pemesanan mengikuti kosakata tabel yang ada (`pending`, `approved`,
 * `rejected`, `cancelled`) — modul aktivitas tidak memperkenalkan status baru
 * yang tidak dikenal modul akademik.
 */
class EloquentFacilityProvider implements FacilityProviderInterface
{
    public function room(int $roomId): ?array
    {
        $room = Room::query()->find($roomId);

        if ($room === null) {
            return null;
        }

        return [
            'id' => (int) $room->id,
            'code' => $room->code,
            'name' => (string) $room->name,
            'type' => $room->type,
            'capacity' => $room->capacity === null ? null : (int) $room->capacity,
            'building' => $room->building,
            'is_active' => (bool) $room->is_active,
        ];
    }

    public function rooms(array $filters = []): array
    {
        return Room::query()
            ->where('is_active', true)
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->orderBy('name')
            ->get()
            ->map(fn (Room $room) => $this->room((int) $room->id))
            ->filter()
            ->values()
            ->all();
    }

    public function availability(int $roomId, string $date, string $startTime, string $endTime, ?int $ignoreBookingId = null): bool
    {
        return $this->conflict($roomId, $date, $startTime, $endTime, $ignoreBookingId) === null;
    }

    public function book(array $payload): array
    {
        $roomId = (int) $payload['room_id'];

        $conflict = $this->conflict(
            $roomId,
            (string) $payload['date'],
            (string) $payload['start_time'],
            (string) $payload['end_time'],
            $payload['ignore_booking_id'] ?? null,
        );

        if ($conflict !== null) {
            return ['status' => 'CONFLICT', 'conflict' => $conflict, 'reference' => null];
        }

        $bookingId = DB::table('room_bookings')->insertGetId([
            'room_id' => $roomId,
            'employee_id' => $payload['employee_id'] ?? null,
            'title' => $payload['title'] ?? 'Kegiatan aktivitas siswa',
            'date' => $payload['date'],
            'start_time' => $payload['start_time'],
            'end_time' => $payload['end_time'],
            'purpose' => $payload['purpose'] ?? 'ACTIVITY',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['status' => 'CONFIRMED', 'reference' => 'room_booking:'.$bookingId, 'conflict' => null];
    }

    public function cancel(string $reference): bool
    {
        if (! str_starts_with($reference, 'room_booking:')) {
            return false;
        }

        $id = (int) substr($reference, strlen('room_booking:'));

        return DB::table('room_bookings')
            ->where('id', $id)
            ->update(['status' => 'cancelled', 'updated_at' => now()]) > 0;
    }

    /** @return array|null pemesanan yang menghalangi */
    protected function conflict(int $roomId, string $date, string $startTime, string $endTime, ?int $ignoreBookingId = null): ?array
    {
        $booking = DB::table('room_bookings')
            ->where('room_id', $roomId)
            ->where('date', $date)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->orderBy('start_time')
            ->first();

        if ($booking === null) {
            return null;
        }

        return [
            'booking_id' => (int) $booking->id,
            'title' => (string) $booking->title,
            'start_time' => (string) $booking->start_time,
            'end_time' => (string) $booking->end_time,
            'reason' => 'Ruang sudah dipesan untuk "'.$booking->title.'" pada jam tersebut.',
        ];
    }
}
