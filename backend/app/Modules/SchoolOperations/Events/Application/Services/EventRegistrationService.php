<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Application\Services;

use App\Modules\SchoolOperations\Events\Domain\Support\EventContext;
use App\Modules\SchoolOperations\Events\Domain\Support\EventStatus;
use App\Modules\SchoolOperations\Events\Models\Event;
use App\Modules\SchoolOperations\Events\Models\EventRegistration;
use Illuminate\Support\Facades\DB;

/**
 * Pendaftaran peserta event (§19).
 *
 * Dua aturan yang menentukan bentuk kelas ini:
 *
 * 1. Kapasitas adalah keputusan, bukan akibat. Pendaftar yang mendaftar saat
 *    kuota penuh TIDAK ditolak — ia masuk daftar tunggu bila event
 *    mengizinkannya. Menolak diam-diam akan menghilangkan orang yang justru
 *    berminat paling besar.
 *
 * 2. Menaikkan peserta dari daftar tunggu berurutan berdasarkan prioritas,
 *    bukan sekadar yang terlama menunggu: peserta dengan kebutuhan khusus harus
 *    dapat didahulukan panitia.
 */
class EventRegistrationService
{
    /** Status yang mengunci pendaftaran dari perubahan lebih lanjut. */
    private const TERMINAL = ['REJECTED', 'CANCELLED', 'NO_SHOW'];

    /** Status yang memakai satu slot kapasitas. */
    private const OCCUPYING = ['APPROVED', 'ATTENDED'];

    public function __construct(
        private readonly EventContext $context,
        private readonly EventAuditService $audit,
        private readonly EventDomainEventBus $bus,
    ) {}

    /**
     * Daftarkan peserta.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(Event $event, array $data): EventRegistration
    {
        $idempotencyKey = $data['idempotency_key'] ?? $this->context->idempotencyKey(
            'event-registration',
            (string) $event->id,
            (string) ($data['student_id'] ?? $data['external_email'] ?? $data['registrant_type'] ?? 'anon'),
        );

        // Idempotensi ditangani sendiri, bukan lewat EventContext::idempotent().
        // Helper itu mengembalikan penanda ['written' => false, 'reason' =>
        // 'DUPLICATE'] saat kunci diputar ulang — berguna sebagai penjaga, tetapi
        // tidak dapat memenuhi kontrak di sini: pemanggil yang mengirim ulang
        // pendaftaran harus menerima pendaftaran yang SAMA, bukan penanda bahwa
        // permintaannya diabaikan.
        if ($existing = $this->findByIdempotencyKey($idempotencyKey)) {
            return $existing;
        }

        return DB::transaction(function () use ($event, $data, $idempotencyKey): EventRegistration {
            $this->assertRegistrationOpen($event);

            $full = $this->availableCapacity($event) <= 0;

            if ($full && ! $event->waitlist_enabled) {
                throw new \DomainException("Event #{$event->id} sudah penuh dan tidak membuka daftar tunggu.");
            }

            return $this->createRegistration($event, $data, $full, $idempotencyKey);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createRegistration(Event $event, array $data, bool $full, string $idempotencyKey): EventRegistration
    {
        return DB::transaction(function () use ($event, $data, $full, $idempotencyKey): EventRegistration {
                $registration = new EventRegistration();
                $registration->fill($this->fillable($data));
                $registration->event_id = $event->id;
                $registration->registration_code = $data['registration_code']
                    ?? $this->context->generateCode('REG', 'event_registrations', 'registration_code');

                $this->assertWithinWaitlistCapacity($event, $full);

                $registration->status = $full ? 'WAITLIST' : 'SUBMITTED';
                $registration->waitlist_position = $full ? $this->nextWaitlistPosition($event) : null;
                $registration->submitted_at = now();
                $registration->save();

                $saved = $registration->refresh();

                $this->audit->record(
                    action: $full ? 'event.registration.waitlisted' : 'event.registration.submitted',
                    entityType: EventRegistration::class,
                    entityId: $saved->id,
                    new: ['status' => $saved->status, 'code' => $saved->registration_code, 'waitlist_position' => $saved->waitlist_position],
                    eventId: (int) $event->id,
                );

                $this->bus->publish(
                    $full ? 'event.registration.waitlisted' : 'event.registration.submitted',
                    EventRegistration::class,
                    $saved->id,
                    ['event_id' => $event->id, 'status' => $saved->status],
                    (int) $event->id,
                );

                // Kunci idempotensi ditulis di dalam transaksi yang sama dengan
                // pendaftarannya. Bila ditulis terpisah dan proses terhenti di
                // antaranya, baris pendaftaran akan ada tanpa kunci — dan
                // pengiriman ulang berikutnya melahirkan peserta kedua.
                DB::table('event_idempotency_keys')->insert([
                    'idempotency_key' => $idempotencyKey,
                    'scope' => 'event.registration',
                    'source' => 'WEB',
                    'external_reference' => (string) $saved->id,
                    'processed_at' => now(),
                    'created_at' => now(),
                ]);

                return $saved;
        });
    }

    /** Pendaftaran yang sudah pernah dibuat dengan kunci idempotensi ini. */
    private function findByIdempotencyKey(string $key): ?EventRegistration
    {
        $row = DB::table('event_idempotency_keys')->where('idempotency_key', $key)->first();

        if ($row === null || $row->external_reference === null) {
            return null;
        }

        return EventRegistration::find((int) $row->external_reference);
    }

    /** Setujui pendaftaran dan pakai satu slot kapasitas. */
    public function approve(EventRegistration $registration, ?string $note = null): EventRegistration
    {
        $this->assertMutable($registration);

        $event = $registration->event;
        if ($event instanceof Event && $this->availableCapacity($event) <= 0) {
            throw new \DomainException("Kapasitas event #{$event->id} sudah penuh; tawarkan daftar tunggu atau tolak.");
        }

        return $this->transition($registration, 'APPROVED', $note, fn (EventRegistration $r) => $r->approved_at = now());
    }

    /** Tolak pendaftaran beserta alasannya. */
    public function reject(EventRegistration $registration, string $reason): EventRegistration
    {
        return $this->transition($registration, 'REJECTED', $reason, function (EventRegistration $r) use ($reason): void {
            $r->rejected_at = now();
            $r->rejection_reason = $reason;
        });
    }

    /** Tarik pendaftaran atas permintaan peserta. */
    public function withdraw(EventRegistration $registration, string $reason): EventRegistration
    {
        return $this->transition($registration, 'CANCELLED', $reason, function (EventRegistration $r) use ($reason): void {
            $r->cancelled_at = now();
            $r->cancel_reason = $reason;
        });
    }

    /** Tandai peserta hadir. */
    public function checkIn(EventRegistration $registration): EventRegistration
    {
        $this->assertMutable($registration);

        return $this->transition($registration, 'ATTENDED', null, fn (EventRegistration $r) => $r->checked_in_at ??= now());
    }

    /** Tandai peserta tidak hadir. */
    public function markNoShow(EventRegistration $registration): EventRegistration
    {
        return $this->transition($registration, 'NO_SHOW', 'Ditandai tidak hadir oleh panitia');
    }

    /**
     * Naikkan peserta dari daftar tunggu menjadi disetujui.
     *
     * Urutan: prioritas tertinggi lebih dulu, lalu yang paling lama menunggu.
     *
     * @return list<EventRegistration>
     */
    public function promoteFromWaitlist(Event $event, int $count = 1): array
    {
        $promoted = [];

        for ($i = 0; $i < $count; $i++) {
            if ($this->availableCapacity($event) <= 0) {
                break;
            }

            $next = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('status', 'WAITLIST')
                ->orderByDesc('manual_priority')
                ->orderByDesc('priority_score')
                ->orderBy('waitlist_position')
                ->first();

            if (! $next instanceof EventRegistration) {
                break;
            }

            $next->waitlist_position = null;
            $next->save();

            $promoted[] = $this->approve($next, 'Dinaikkan otomatis dari daftar tunggu');
        }

        if ($promoted !== []) {
            $this->bus->publish('event.waitlist.promoted', Event::class, $event->id, [
                'count' => count($promoted),
                'registration_ids' => array_map(fn (EventRegistration $r) => $r->id, $promoted),
            ], (int) $event->id);
        }

        return $promoted;
    }

    /** Sisa kapasitas yang benar-benar dapat dipakai. */
    public function availableCapacity(Event $event): int
    {
        $max = $event->max_participants;

        // Tanpa batas maksimum, kapasitas dianggap tak terhingga.
        if ($max === null || (int) $max <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, (int) $max - $this->occupyingCount($event));
    }

    /** Jumlah peserta yang sedang memakai slot kapasitas. */
    public function occupyingCount(Event $event): int
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereIn('status', self::OCCUPYING)
            ->count();
    }

    /** Jumlah peserta dalam daftar tunggu. */
    public function waitlistCount(Event $event): int
    {
        return EventRegistration::query()->where('event_id', $event->id)->where('status', 'WAITLIST')->count();
    }

    /**
     * Pendaftaran hanya terbuka pada tahap yang mengizinkannya.
     *
     * @throws \DomainException
     */
    public function assertRegistrationOpen(Event $event): void
    {
        $status = (string) $event->status;

        if (! in_array($status, ['REGISTRATION_OPEN', 'PLANNING', 'READY'], true)) {
            throw new \DomainException("Event #{$event->id} berstatus {$status}; pendaftaran belum dibuka.");
        }

        if ($event->registration_close_at !== null && now()->greaterThan($event->registration_close_at)) {
            throw new \DomainException("Pendaftaran event #{$event->id} sudah ditutup pada {$event->registration_close_at}.");
        }
    }

    /**
     * Daftar tunggu sendiri punya batas; tanpa ini antrean dapat tumbuh tanpa
     * kendali sementara peluang promosinya nihil.
     *
     * @throws \DomainException
     */
    private function assertWithinWaitlistCapacity(Event $event, bool $full): void
    {
        if (! $full || $event->waitlist_capacity === null) {
            return;
        }

        if ($this->waitlistCount($event) >= (int) $event->waitlist_capacity) {
            throw new \DomainException("Daftar tunggu event #{$event->id} sudah penuh.");
        }
    }

    private function nextWaitlistPosition(Event $event): int
    {
        return $this->waitlistCount($event) + 1;
    }

    /**
     * @param  callable(EventRegistration): void|null  $mutate
     */
    private function transition(EventRegistration $registration, string $to, ?string $reason, ?callable $mutate = null): EventRegistration
    {
        if (! in_array($to, EventStatus::registrationStates(), true)) {
            throw new \DomainException("Status pendaftaran {$to} tidak dikenali.");
        }

        return DB::transaction(function () use ($registration, $to, $reason, $mutate): EventRegistration {
            $from = (string) $registration->status;

            $registration->status = $to;
            if ($reason !== null) {
                $registration->status_note = $reason;
            }
            if ($mutate !== null) {
                $mutate($registration);
            }
            $registration->save();

            $saved = $registration->refresh();

            $this->audit->record(
                action: "event.registration.{$this->verb($to)}",
                entityType: EventRegistration::class,
                entityId: $saved->id,
                old: ['status' => $from],
                new: ['status' => $saved->status],
                reason: $reason,
                eventId: $saved->event_id !== null ? (int) $saved->event_id : null,
            );

            $this->bus->publish("event.registration.{$this->verb($to)}", EventRegistration::class, $saved->id, [
                'from' => $from,
                'to' => $saved->status,
                'reason' => $reason,
            ], $saved->event_id !== null ? (int) $saved->event_id : null);

            return $saved;
        });
    }

    private function verb(string $status): string
    {
        return match ($status) {
            'APPROVED' => 'approved',
            'REJECTED' => 'rejected',
            'CANCELLED' => 'withdrawn',
            'ATTENDED' => 'checked_in',
            'NO_SHOW' => 'no_show',
            default => strtolower($status),
        };
    }

    /**
     * @throws \DomainException
     */
    public function assertMutable(EventRegistration $registration): void
    {
        if (in_array((string) $registration->status, self::TERMINAL, true)) {
            throw new \DomainException(
                "Pendaftaran #{$registration->id} berstatus {$registration->status} dan tidak dapat diubah lagi."
            );
        }
    }

    /**
     * Kolom yang dikelola layanan ini tidak boleh diisi lewat mass assignment.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillable(array $data): array
    {
        $guarded = [
            'id', 'event_id', 'registration_code', 'status', 'waitlist_position',
            'submitted_at', 'approved_at', 'rejected_at', 'cancelled_at', 'checked_in_at',
        ];

        return array_diff_key($data, array_flip($guarded));
    }
}
