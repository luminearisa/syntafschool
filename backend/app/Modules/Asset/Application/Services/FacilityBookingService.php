<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Application\Support\AssetSettingsService;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\BookingSource;
use App\Modules\Asset\Domain\Enums\BookingStatus;
use App\Modules\Asset\Domain\Enums\FacilityStatus;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\BookingStateMachine;
use App\Modules\Asset\Models\Facility;
use App\Modules\Asset\Models\FacilityBooking;
use App\Modules\Asset\Models\FacilityUtilizationDaily;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Booking fasilitas dengan pemeriksaan benturan yang tidak dapat dilewati.
 *
 * Pemeriksaan dilakukan DUA KALI: sebelum menyimpan (supaya pengguna mendapat
 * penjelasan) dan di dalam transaksi dengan `lockForUpdate` (supaya dua
 * permintaan yang datang bersamaan tidak sama-sama lolos). Yang kedua itulah yang
 * benar-benar mencegah jadwal dobel; yang pertama hanya membuat pesannya ramah.
 */
class FacilityBookingService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly FacilityService $facilities,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly AssetSettingsService $settings,
    ) {}

    public function book(User $actor, Facility $facility, array $data): FacilityBooking
    {
        $start = Carbon::parse($data['start_at']);
        $end = Carbon::parse($data['end_at']);

        if ($end->lessThanOrEqualTo($start)) {
            throw new AssetValidationException(['end_at' => ['Waktu berakhir harus setelah waktu mulai.']]);
        }

        $this->assertBookingRules($facility, $start, $end, $actor);

        return DB::transaction(function () use ($actor, $facility, $data, $start, $end): FacilityBooking {
            // Kunci baris fasilitas: dua booking bersamaan antre di sini.
            Facility::query()->whereKey($facility->id)->lockForUpdate()->first();

            $conflict = $this->conflict($facility, $start, $end);

            if ($conflict !== null) {
                throw new AssetDomainException('Fasilitas sudah dipakai pada rentang itu: '.$conflict, 409);
            }

            $requiresApproval = $this->requiresApproval($facility);

            $booking = FacilityBooking::query()->create([
                'booking_number' => $this->numbers->next('booking', ['tahun' => $start->year]),
                'facility_id' => $facility->id,
                'location_id' => $facility->location_id,
                'requested_by' => $actor->id,
                'source' => $data['source'] ?? BookingSource::STAFF->value,
                'status' => BookingStatus::REQUESTED->value,
                'title' => $data['title'],
                'purpose' => $data['purpose'] ?? null,
                'attendee_count' => $data['attendee_count'] ?? null,
                'start_at' => $start,
                'end_at' => $end,
                'requires_approval' => $requiresApproval,
                'event_reference' => $data['event_reference'] ?? null,
                'activity_reference' => $data['activity_reference'] ?? null,
                'academic_reference' => $data['academic_reference'] ?? null,
                'conflict_checked_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // Tanpa persetujuan (fasilitas umum untuk staf): langsung tersimpan.
            if (! $requiresApproval) {
                $booking->forceFill([
                    'status' => BookingStatus::RESERVED->value,
                    'approved_at' => now(),
                    'approved_by' => $actor->id,
                ])->save();
            }

            $this->audit->record(AuditAction::FACILITY_BOOKED, $booking, $actor, [
                'status_after' => $booking->status,
                'from' => $start->toDateTimeString(),
                'to' => $end->toDateTimeString(),
                'reason' => $booking->title,
                'context' => ['requires_approval' => $requiresApproval, 'attendee_count' => $booking->attendee_count],
            ]);

            $this->notifications->notify(
                array_values(array_filter([$facility->manager_user_id])),
                ['FACILITY_MANAGER'],
                'FACILITY_BOOKED',
                'Booking baru: '.$facility->name,
                sprintf('%s — %s s.d. %s', $booking->title, $start->toDateTimeString(), $end->toDateTimeString()),
                $booking,
            );

            return $booking->refresh();
        });
    }

    public function approve(User $actor, FacilityBooking $booking, ?string $notes = null): FacilityBooking
    {
        BookingStateMachine::assert($this->status($booking), BookingStatus::RESERVED->value, 'booking');

        $booking->forceFill([
            'status' => BookingStatus::RESERVED->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'notes' => $notes ?? $booking->notes,
        ])->save();

        $this->audit->record(AuditAction::FACILITY_BOOKED, $booking, $actor, [
            'status_before' => BookingStatus::REQUESTED->value,
            'status_after' => BookingStatus::RESERVED->value,
            'reason' => $notes ?? 'Booking disetujui.',
        ]);

        return $booking->refresh();
    }

    public function reject(User $actor, FacilityBooking $booking, string $reason): FacilityBooking
    {
        BookingStateMachine::assert($this->status($booking), BookingStatus::REJECTED->value, 'booking');

        $booking->forceFill(['status' => BookingStatus::REJECTED->value, 'rejection_reason' => $reason])->save();

        return $booking->refresh();
    }

    public function checkIn(User $actor, FacilityBooking $booking): FacilityBooking
    {
        BookingStateMachine::assert($this->status($booking), BookingStatus::IN_USE->value, 'booking');

        $booking->forceFill(['status' => BookingStatus::IN_USE->value, 'checked_in_at' => now()])->save();

        return $booking->refresh();
    }

    /** Selesaikan pemakaian dan perbarui agregat pemanfaatan harian. */
    public function complete(User $actor, FacilityBooking $booking): FacilityBooking
    {
        BookingStateMachine::assert($this->status($booking), BookingStatus::COMPLETED->value, 'booking');

        return DB::transaction(function () use ($actor, $booking): FacilityBooking {
            $booking->forceFill(['status' => BookingStatus::COMPLETED->value, 'completed_at' => now()])->save();

            $this->refreshUtilization($booking);

            $facility = $booking->facility;

            if ($facility !== null) {
                $facility->forceFill([
                    'booking_count' => (int) $facility->booking_count + 1,
                ])->save();
            }

            $this->audit->record(AuditAction::FACILITY_BOOKED, $booking, $actor, [
                'status_before' => BookingStatus::IN_USE->value,
                'status_after' => BookingStatus::COMPLETED->value,
                'reason' => 'Pemakaian fasilitas selesai.',
            ]);

            return $booking->refresh();
        });
    }

    public function cancel(User $actor, FacilityBooking $booking, string $reason): FacilityBooking
    {
        BookingStateMachine::assert($this->status($booking), BookingStatus::CANCELLED->value, 'booking');

        $booking->forceFill([
            'status' => BookingStatus::CANCELLED->value,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ])->save();

        $this->audit->record(AuditAction::FACILITY_BOOKING_CANCELLED, $booking, $actor, [
            'status_after' => BookingStatus::CANCELLED->value, 'reason' => $reason,
        ]);

        return $booking->refresh();
    }

    /** Ketersediaan + slot bebas pada satu hari (untuk layar pemesanan). */
    public function availability(Facility $facility, string $date): array
    {
        $day = Carbon::parse($date);
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();

        $status = $facility->status instanceof FacilityStatus ? $facility->status : FacilityStatus::tryFrom((string) $facility->status);

        $result = [
            'facility_id' => $facility->id,
            'date' => $day->toDateString(),
            'status' => $status?->value ?? (string) $facility->status,
            'bookable' => $status?->isBookable() ?? false,
            'slots' => [],
            'bookings' => [],
        ];

        $result['bookings'] = FacilityBooking::query()
            ->where('facility_id', $facility->id)
            ->whereBetween('start_at', [$dayStart, $dayEnd])
            ->orderBy('start_at')
            ->get()
            ->map(fn (FacilityBooking $b): array => [
                'id' => $b->id,
                'booking_number' => $b->booking_number,
                'title' => $b->title,
                'start_at' => $b->start_at?->toDateTimeString(),
                'end_at' => $b->end_at?->toDateTimeString(),
                'status' => $b->status?->value ?? (string) $b->status,
            ])
            ->all();

        $hours = $facility->operatingHours()->where('day_of_week', $day->dayOfWeek)->first();

        if ($hours === null || $hours->is_closed) {
            $result['slots'] = [];

            return $result;
        }

        $cursor = Carbon::parse($day->toDateString().' '.$hours->open_time);
        $close = Carbon::parse($day->toDateString().' '.$hours->close_time);
        $duration = (int) ($facility->min_booking_minutes ?? 30);

        while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($close)) {
            $slotEnd = $cursor->copy()->addMinutes($duration);

            $result['slots'][] = [
                'start_at' => $cursor->toDateTimeString(),
                'end_at' => $slotEnd->toDateTimeString(),
                'available' => $this->conflict($facility, $cursor, $slotEnd) === null,
            ];

            $cursor = $slotEnd;
        }

        return $result;
    }

    /** Benturan: booking, penutupan, atau jadwal akademik. */
    public function conflict(Facility $facility, CarbonInterface $start, CarbonInterface $end): ?string
    {
        $availability = $this->facilities->availability($facility, $start, $end);

        if ($availability['available']) {
            return null;
        }

        $current = FacilityBooking::query()->whereKey(0)->first();

        return implode(' ', array_slice($availability['reasons'], 0, 2)) ?: 'benturan jadwal';
    }

    protected function assertBookingRules(Facility $facility, CarbonInterface $start, CarbonInterface $end, User $actor): void
    {
        $status = $facility->status instanceof FacilityStatus ? $facility->status : FacilityStatus::tryFrom((string) $facility->status);

        if ($status === null || ! $status->isBookable()) {
            throw new AssetDomainException('Fasilitas berstatus '.($status?->label() ?? (string) $facility->status).' dan tidak dapat dibooking.', 409);
        }

        $minutes = $start->diffInMinutes($end);
        $min = (int) ($facility->min_booking_minutes ?? config('asset.booking.default_min_duration_minutes', 30));
        $max = (int) ($facility->max_booking_minutes ?? config('asset.booking.default_max_duration_minutes', 480));

        if ($minutes < $min) {
            throw new AssetValidationException(['end_at' => ["Durasi minimum $min menit."]]);
        }

        if ($minutes > $max) {
            throw new AssetValidationException(['end_at' => ["Durasi maksimum $max menit."]]);
        }

        $advance = (int) ($facility->advance_booking_days ?? config('asset.booking.advance_booking_days', 90));

        if ($start->greaterThan(now()->addDays($advance))) {
            throw new AssetValidationException(['start_at' => ["Pemesanan hanya dapat dilakukan maksimal $advance hari sebelumnya."]]);
        }

        // Jam operasional: booking di luar jam buka harus ditolak, bukan
        // "dibiarkan tetapi dicatat" — penjaga malam tidak bisa membuka aula
        // hanya karena ada yang memesan tengah malam.
        $hours = $facility->operatingHours()->where('day_of_week', $start->dayOfWeek)->first();

        if ($hours !== null) {
            if ($hours->is_closed) {
                throw new AssetDomainException('Fasilitas tutup pada hari tersebut.', 409);
            }

            if ($hours->open_time !== null && $start->format('H:i') < substr((string) $hours->open_time, 0, 5)) {
                throw new AssetValidationException(['start_at' => ['Pemesanan dimulai sebelum jam buka ('.$hours->open_time.').']]);
            }

            if ($hours->close_time !== null && $end->format('H:i') > substr((string) $hours->close_time, 0, 5)) {
                throw new AssetValidationException(['end_at' => ['Pemesanan melewati jam tutup ('.$hours->close_time.').']]);
            }
        }
    }

    protected function requiresApproval(Facility $facility): bool
    {
        if ($facility->requires_approval !== null) {
            return (bool) $facility->requires_approval;
        }

        return (bool) ($facility->type?->requires_approval ?? true);
    }

    protected function refreshUtilization(FacilityBooking $booking): void
    {
        $date = $booking->start_at?->toDateString();

        if ($date === null || $booking->facility_id === null) {
            return;
        }

        $minutes = (int) ($booking->start_at?->diffInMinutes($booking->end_at) ?? 0);

        FacilityUtilizationDaily::query()->updateOrCreate(
            ['facility_id' => $booking->facility_id, 'date' => $date],
            [
                'booking_count' => FacilityBooking::query()
                    ->where('facility_id', $booking->facility_id)
                    ->whereDate('start_at', $date)
                    ->where('status', BookingStatus::COMPLETED->value)
                    ->count(),
                'usage_minutes' => (int) FacilityBooking::query()
                    ->where('facility_id', $booking->facility_id)
                    ->whereDate('start_at', $date)
                    ->where('status', BookingStatus::COMPLETED->value)
                    ->get()
                    ->sum(fn (FacilityBooking $b) => (int) ($b->start_at?->diffInMinutes($b->end_at) ?? 0)),
                'attendee_count' => (int) FacilityBooking::query()
                    ->where('facility_id', $booking->facility_id)
                    ->whereDate('start_at', $date)
                    ->where('status', BookingStatus::COMPLETED->value)
                    ->sum('attendee_count'),
                'peak_hour' => $booking->start_at?->format('H:i'),
            ],
        );
    }

    protected function status(FacilityBooking $booking): string
    {
        return $booking->status instanceof BookingStatus ? $booking->status->value : (string) $booking->status;
    }
}
