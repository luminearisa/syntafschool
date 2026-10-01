<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\BookingStatus;
use App\Modules\Asset\Domain\Enums\FacilityStatus;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\Facility;
use App\Modules\Asset\Models\FacilityBlackoutPeriod;
use App\Modules\Asset\Models\FacilityBooking;
use App\Modules\Asset\Models\Location;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fasilitas: kapasitas, jam operasional, penutupan sementara, dan ketersediaan.
 *
 * Yang penting di sini bukan CRUD-nya, melainkan dua hal: (1) ketersediaan
 * dihitung dari SATU sumber (booking + hitam + penutupan + jadwal akademik),
 * dan (2) menutup fasilitas selalu melaporkan booking siapa yang terdampak —
 * penutupan yang tidak memberi tahu siapa pun adalah penutupan yang melahirkan
 * masalah baru.
 */
class FacilityService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly AcademicProviderInterface $academic,
    ) {}

    public function create(User $actor, array $data): Facility
    {
        $location = isset($data['location_id']) ? Location::query()->find($data['location_id']) : null;

        if (isset($data['location_id']) && $location === null) {
            throw new AssetValidationException(['location_id' => ['Lokasi tidak ditemukan.']]);
        }

        return DB::transaction(function () use ($actor, $data, $location): Facility {
            $facility = Facility::query()->create([
                'campus_id' => $data['campus_id'] ?? $location?->campus_id,
                'location_id' => $location?->id,
                'facility_type_id' => $data['facility_type_id'] ?? null,
                'facility_code' => $data['facility_code'] ?? $this->numbers->next('facility', [
                    'kat' => $data['category_code'] ?? 'FCL',
                ]),
                'name' => $data['name'],
                'capacity_total' => $data['capacity_total'] ?? null,
                'capacity_normal' => $data['capacity_normal'] ?? $data['capacity_total'] ?? null,
                'capacity_exam' => $data['capacity_exam'] ?? null,
                'capacity_event' => $data['capacity_event'] ?? null,
                'status' => $data['status'] ?? FacilityStatus::ACTIVE->value,
                'availability' => $data['availability'] ?? 'AVAILABLE',
                'condition' => $data['condition'] ?? 'GOOD',
                'responsible_unit_id' => $data['responsible_unit_id'] ?? $location?->responsible_unit_id,
                'manager_user_id' => $data['manager_user_id'] ?? $location?->manager_user_id,
                'operational_hours' => $data['operational_hours'] ?? null,
                'min_booking_minutes' => $data['min_booking_minutes'] ?? config('asset.booking.default_min_duration_minutes'),
                'max_booking_minutes' => $data['max_booking_minutes'] ?? config('asset.booking.default_max_duration_minutes'),
                'advance_booking_days' => $data['advance_booking_days'] ?? config('asset.booking.advance_booking_days'),
                'requires_approval' => $data['requires_approval'] ?? null,
                'allowed_roles' => $data['allowed_roles'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            foreach (($data['operating_hours'] ?? []) as $hour) {
                $facility->operatingHours()->create([
                    'day_of_week' => (int) $hour['day_of_week'],
                    'open_time' => $hour['open_time'] ?? null,
                    'close_time' => $hour['close_time'] ?? null,
                    'is_closed' => (bool) ($hour['is_closed'] ?? false),
                    'notes' => $hour['notes'] ?? null,
                ]);
            }

            $this->audit->record(AuditAction::FACILITY_CREATED, $facility, $actor, [
                'after' => $facility->only(['facility_code', 'name', 'capacity_total', 'status']),
                'status_after' => $facility->status,
            ]);

            return $facility->refresh();
        });
    }

    public function update(User $actor, Facility $facility, array $data): Facility
    {
        $before = $facility->only(['name', 'capacity_total', 'status', 'availability', 'condition', 'manager_user_id']);

        $facility->fill($data)->save();

        $this->audit->record(AuditAction::FACILITY_UPDATED, $facility, $actor, [
            'before' => $before,
            'after' => $facility->only(array_keys($before)),
        ]);

        return $facility->refresh();
    }

    public function addBlackout(User $actor, Facility $facility, CarbonInterface $start, CarbonInterface $end, ?string $reason = null): FacilityBlackoutPeriod
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new AssetValidationException(['end_at' => ['Waktu berakhir harus setelah waktu mulai.']]);
        }

        return DB::transaction(function () use ($actor, $facility, $start, $end, $reason): FacilityBlackoutPeriod {
            $blackout = $facility->blackoutPeriods()->create([
                'start_at' => $start,
                'end_at' => $end,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            $affected = $this->affectedBookings($facility, $start, $end);

            $this->audit->record(AuditAction::FACILITY_CLOSED, $facility, $actor, [
                'from' => $start->toDateTimeString(),
                'to' => $end->toDateTimeString(),
                'reason' => $reason ?? 'Periode hitam fasilitas.',
                'context' => ['affected_bookings' => $affected->pluck('booking_number')->all()],
            ]);

            return $blackout;
        });
    }

    /**
     * Tutup fasilitas sementara (mis. untuk perbaikan) dan laporkan booking terdampak.
     *
     * @return array{facility:Facility, affected:array<int, array<string, mixed>>}
     */
    public function close(User $actor, Facility $facility, string $reason, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from ??= now();
        $to ??= now()->addDays(7);

        return DB::transaction(function () use ($actor, $facility, $reason, $from, $to): array {
            $before = $facility->status;

            $facility->forceFill([
                'status' => FacilityStatus::MAINTENANCE->value,
                'availability' => 'UNAVAILABLE',
                'maintenance_status' => 'CLOSED',
            ])->save();

            $facility->blackoutPeriods()->create([
                'start_at' => $from,
                'end_at' => $to,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            $affected = $this->affectedBookings($facility, $from, $to);

            // Pemberitahuan dikirim ke pemesan yang terdampak — inti dari aturan
            // "penutupan harus menemukan booking yang bentrok".
            foreach ($affected as $booking) {
                $this->notifications->notify(
                    [(int) $booking->requested_by],
                    ['FACILITY_MANAGER'],
                    'FACILITY_CLOSED',
                    'Fasilitas ditutup: '.$facility->name,
                    sprintf('Booking %s (%s) terdampak penutupan: %s', $booking->booking_number, $booking->start_at?->toDateTimeString(), $reason),
                    $facility,
                    ['booking_id' => $booking->id, 'reason' => $reason],
                );
            }

            $this->audit->record(AuditAction::FACILITY_CLOSED, $facility, $actor, [
                'status_before' => $before,
                'status_after' => $facility->status,
                'reason' => $reason,
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'context' => ['affected_bookings' => $affected->pluck('booking_number')->all()],
            ]);

            return ['facility' => $facility->refresh(), 'affected' => $affected->map(fn (FacilityBooking $b): array => [
                'id' => $b->id,
                'booking_number' => $b->booking_number,
                'requested_by' => $b->requested_by,
                'start_at' => $b->start_at?->toDateTimeString(),
                'end_at' => $b->end_at?->toDateTimeString(),
            ])->all()];
        });
    }

    public function reopen(User $actor, Facility $facility, ?string $reason = null): Facility
    {
        $before = $facility->status;

        $facility->forceFill([
            'status' => FacilityStatus::ACTIVE->value,
            'availability' => 'AVAILABLE',
            'maintenance_status' => null,
        ])->save();

        $this->audit->record(AuditAction::FACILITY_REOPENED, $facility, $actor, [
            'status_before' => $before,
            'status_after' => $facility->status,
            'reason' => $reason ?? 'Fasilitas dibuka kembali.',
        ]);

        return $facility->refresh();
    }

    /**
     * Ketersediaan fasilitas pada satu rentang waktu.
     *
     * @return array{available:bool, reasons:list<string>, bookings:list<array<string,mixed>>, blackouts:list<array<string,mixed>>}
     */
    public function availability(Facility $facility, CarbonInterface $from, CarbonInterface $to): array
    {
        $reasons = [];

        $status = $facility->status;

        if (! $status instanceof FacilityStatus) {
            $reasons[] = 'Status fasilitas tidak dikenali ('.(string) $facility->status.').';
        } elseif (! $status->isBookable()) {
            $reasons[] = 'Fasilitas berstatus '.$status->label().'.';
        }

        $blackouts = $facility->blackoutPeriods()
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->get();

        foreach ($blackouts as $blackout) {
            $reasons[] = 'Ditutup '.$blackout->start_at?->toDateTimeString().'–'.$blackout->end_at?->toDateTimeString()
                .($blackout->reason ? ' ('.$blackout->reason.')' : '');
        }

        $bookings = FacilityBooking::query()
            ->where('facility_id', $facility->id)
            ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, array_filter(BookingStatus::cases(), fn (BookingStatus $s) => $s->holdsSlot())))
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->get();

        foreach ($bookings as $booking) {
            $reasons[] = 'Sudah dibooking '.$booking->booking_number.' pada '.$booking->start_at?->toDateTimeString().'–'.$booking->end_at?->toDateTimeString();
        }

        if ($facility->location_id !== null) {
            foreach ($this->academic->roomScheduleConflicts((int) $facility->location_id, $from->toDateTimeString(), $to->toDateTimeString()) as $conflict) {
                $reasons[] = 'Jadwal akademik: '.($conflict['label'] ?? 'kelas terjadwal');
            }
        }

        return [
            'available' => $reasons === [],
            'reasons' => $reasons,
            'bookings' => $bookings->map(fn (FacilityBooking $b): array => [
                'id' => $b->id, 'booking_number' => $b->booking_number,
                'start_at' => $b->start_at?->toDateTimeString(), 'end_at' => $b->end_at?->toDateTimeString(),
                'status' => $b->status?->value,
            ])->all(),
            'blackouts' => $blackouts->map(fn (FacilityBlackoutPeriod $p): array => [
                'id' => $p->id, 'start_at' => $p->start_at?->toDateTimeString(),
                'end_at' => $p->end_at?->toDateTimeString(), 'reason' => $p->reason,
            ])->all(),
        ];
    }

    /** Booking yang menahan slot pada rentang penutupan. */
    public function affectedBookings(Facility $facility, CarbonInterface $from, CarbonInterface $to)
    {
        $holding = array_map(fn (BookingStatus $s) => $s->value, array_filter(BookingStatus::cases(), fn (BookingStatus $s) => $s->holdsSlot()));

        return FacilityBooking::query()
            ->where('facility_id', $facility->id)
            ->whereIn('status', $holding)
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->orderBy('start_at')
            ->get();
    }

    /** Pemanfaatan: dipakai laporan kapasitas dan perencanaan fasilitas. */
    public function utilization(Facility $facility, Carbon $from, Carbon $to): array
    {
        $bookings = FacilityBooking::query()
            ->where('facility_id', $facility->id)
            ->whereIn('status', [BookingStatus::COMPLETED->value, BookingStatus::IN_USE->value, BookingStatus::RESERVED->value])
            ->whereBetween('start_at', [$from, $to])
            ->get();

        $usageMinutes = $bookings->sum(fn (FacilityBooking $b) => (int) ($b->start_at?->diffInMinutes($b->end_at) ?? 0));
        $availableMinutes = max(1, (int) $from->diffInMinutes($to));

        return [
            'booking_count' => $bookings->count(),
            'usage_minutes' => (int) $usageMinutes,
            'attendee_count' => (int) $bookings->sum('attendee_count'),
            'utilization_rate' => round($usageMinutes / $availableMinutes * 100, 2),
            'capacity_utilization' => $facility->capacity_total
                ? round((float) $bookings->avg('attendee_count') / (float) $facility->capacity_total * 100, 2)
                : null,
        ];
    }
}
