<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\{AttendanceGatewayInterface, StudentGatewayInterface};
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{
    AuthorizedPickupPerson, BoardingEvent, DropoffEvent, TransportationAdjustment, Trip, TripPassenger, TripStop
};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Naik/turun penumpang (§34-§38, §42, §88, §89).
 *
 * - Idempoten: satu bukti naik/turun hanya diterapkan sekali walau event ganda.
 * - Menitipkan bukti ke modul presensi (transportasi bukan master presensi).
 * - Menghormati §87 Rule 11 (tidak boleh turun sebelum naik kecuali koreksi berwenang).
 * - Siap offline: batch sinkronisasi divalidasi server & konflik dicatat.
 */
class BoardingService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly ManifestService $manifest,
        private readonly TripService $trips,
        private readonly TransportationNotificationService $notifications,
        private readonly AttendanceGatewayInterface $attendance,
        private readonly StudentGatewayInterface $students,
    ) {
    }

    // ── Naik ───────────────────────────────────────────────────────────────

    public function board(array $payload, ?string $idempotencyKey = null): array
    {
        $trip = $this->resolveTrip($payload);
        $passenger = $this->resolvePassenger($trip, $payload, true);
        $method = strtoupper((string) ($payload['method'] ?? 'MANUAL'));
        $key = $idempotencyKey ?? ($payload['idempotency_key'] ?? 'board-'.$trip->id.'-'.$passenger->id);

        $result = $this->context->idempotent('BOARDING', $key, function () use ($trip, $passenger, $payload, $method): array {
            if (! in_array($trip->status, ['DISPATCHED', 'BOARDING', 'IN_TRANSIT', 'ARRIVED', 'DELAYED', 'INCIDENT'], true)) {
                throw ValidationException::withMessages([
                    'trip' => 'Trip berstatus '.$trip->status.' belum dapat menerima penumpang.',
                ]);
            }
            if ($passenger->boarding_status === 'BOARDED') {
                return ['applied' => false, 'reason' => 'Penumpang sudah tercatat naik.', 'boarding_status' => 'BOARDED'];
            }

            $occurredAt = isset($payload['occurred_at']) ? CarbonImmutable::parse($payload['occurred_at']) : CarbonImmutable::now();
            $stopId = $payload['stop_id'] ?? $passenger->pickup_stop_id;

            $manifest = $this->manifest->recalculate($trip);
            $capacity = (int) ($manifest->capacity ?: optional($trip->vehicle)->student_capacity);
            $override = (bool) ($payload['allow_override'] ?? false);
            $overrideReason = $payload['override_reason'] ?? null;

            if ($capacity > 0 && $manifest->boarded_passengers + 1 > $capacity && ! ($override && $overrideReason)) {
                throw ValidationException::withMessages([
                    'capacity' => 'Kapasitas kendaraan terlampaui ('.$capacity.' kursi). Gunakan override berwenang dengan alasan.',
                ]);
            }

            if ($capacity > 0 && $manifest->boarded_passengers + 1 > $capacity) {
                TransportationAdjustment::query()->create([
                    'reference_type' => 'trip',
                    'reference_id' => $trip->id,
                    'adjustment_type' => 'CAPACITY_OVERRIDE',
                    'before' => ['boarded' => $manifest->boarded_passengers, 'capacity' => $capacity],
                    'after' => ['boarded' => $manifest->boarded_passengers + 1],
                    'reason' => $overrideReason,
                    'requested_by' => $this->context->actorId(),
                    'status' => 'APPLIED',
                ]);
                $this->context->audit('BoardingCapacityOverride', $trip, ['reason' => $overrideReason, 'capacity' => $capacity]);
            }

            $passenger->forceFill([
                'boarding_status' => 'BOARDED',
                'boarded_at' => $occurredAt,
                'boarded_stop_id' => $stopId,
                'boarded_method' => $method,
            ])->save();

            $event = BoardingEvent::query()->create([
                'trip_id' => $trip->id,
                'trip_passenger_id' => $passenger->id,
                'student_id' => $passenger->student_id,
                'employee_id' => $passenger->employee_id,
                'stop_id' => $stopId,
                'method' => $method,
                'status_after' => 'BOARDED',
                'occurred_at' => $occurredAt,
                'card_id' => $payload['card_id'] ?? null,
                'device_id' => $payload['device_id'] ?? null,
                'scanned_token' => $payload['scanned_token'] ?? null,
                'latitude' => $payload['latitude'] ?? null,
                'longitude' => $payload['longitude'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'source' => $payload['source'] ?? 'DRIVER_APP',
                'external_reference' => $payload['external_reference'] ?? null,
                'offline_sync' => (bool) ($payload['offline_sync'] ?? false),
                'recorded_by' => $this->context->actorId(),
                'payload' => ['capacity' => $capacity, 'override' => $override],
            ]);

            if ($stopId) {
                $this->recordStopEvent($trip, (int) $stopId, 'BOARDED', $passenger->student_id, $occurredAt, $payload);
            }

            $this->manifest->updateStatus($passenger, 'boarding_status', 'BOARDED', []);
            $this->manifest->updateStopCounters($trip);
            if (in_array($trip->status, ['DISPATCHED'], true)) {
                $trip->forceFill(['status' => 'BOARDING'])->save();
            }

            $attendance = $this->mirrorAttendance($trip, $passenger, 'PRESENT', $occurredAt, $method);

            $notification = $passenger->student_id
                ? $this->notifications->toGuardians('PassengerBoarded', (int) $passenger->student_id,
                    'Anak Anda sudah naik kendaraan',
                    'Penumpang naik pada trip '.$trip->trip_number.' pukul '.$occurredAt->format('H:i').'.',
                    ['trip_id' => $trip->id, 'idempotency_key' => 'boarded-'.$trip->id.'-'.$passenger->id])
                : null;

            $this->context->publish('PassengerBoarded', $trip, [
                'trip_number' => $trip->trip_number,
                'student_id' => $passenger->student_id,
                'stop_id' => $stopId,
                'method' => $method,
                'offline_sync' => (bool) ($payload['offline_sync'] ?? false),
            ]);

            return [
                'applied' => true,
                'boarding_event_id' => $event->id,
                'trip_id' => $trip->id,
                'passenger_id' => $passenger->id,
                'student_id' => $passenger->student_id,
                'boarding_status' => 'BOARDED',
                'boarded_at' => $occurredAt,
                'method' => $method,
                'stop_id' => $stopId,
                'manifest' => [
                    'boarded' => $this->manifest->recalculate($trip)->boarded_passengers,
                    'capacity' => $capacity,
                ],
                'attendance_reference' => $attendance,
                'notification' => $notification,
            ];
        }, ['source' => $method, 'device_id' => $payload['device_id'] ?? null, 'external_reference' => $payload['external_reference'] ?? null]);

        return $result;
    }

    // ── Turun ──────────────────────────────────────────────────────────────

    public function dropoff(array $payload, ?string $idempotencyKey = null): array
    {
        $trip = $this->resolveTrip($payload);
        $passenger = $this->resolvePassenger($trip, $payload, true);
        $method = strtoupper((string) ($payload['method'] ?? 'MANUAL'));
        $key = $idempotencyKey ?? ($payload['idempotency_key'] ?? 'dropoff-'.$trip->id.'-'.$passenger->id);

        $result = $this->context->idempotent('DROPOFF', $key, function () use ($trip, $passenger, $payload, $method): array {
            if ($passenger->dropoff_status === 'DROPPED_OFF') {
                return ['applied' => false, 'reason' => 'Penumpang sudah tercatat turun.', 'dropoff_status' => 'DROPPED_OFF'];
            }

            $authorizedCorrection = (bool) ($payload['authorized_correction'] ?? false);
            if ($passenger->boarding_status !== 'BOARDED' && ! $authorizedCorrection) {
                throw ValidationException::withMessages([
                    'boarding_status' => 'Penumpang belum tercatat naik. Turun sebelum naik hanya boleh melalui koreksi berwenang '
                        .'(§87 Rule 11).',
                ]);
            }

            if ($passenger->boarding_status !== 'BOARDED' && $authorizedCorrection) {
                TransportationAdjustment::query()->create([
                    'reference_type' => 'trip_passenger',
                    'reference_id' => $passenger->id,
                    'adjustment_type' => 'DROPOFF_CORRECTION',
                    'before' => ['boarding_status' => $passenger->boarding_status],
                    'after' => ['boarding_status' => 'BOARDED', 'dropoff_status' => 'DROPPED_OFF'],
                    'reason' => $payload['override_reason'] ?? 'Koreksi berwenang oleh petugas transportasi.',
                    'requested_by' => $this->context->actorId(),
                    'approved_by' => $this->context->actorId(),
                    'approved_at' => now(),
                    'status' => 'APPLIED',
                ]);
                $passenger->forceFill([
                    'boarding_status' => 'BOARDED',
                    'boarded_at' => $passenger->boarded_at ?? now(),
                    'boarded_method' => $passenger->boarded_method ?? 'MANUAL',
                ])->save();
                $this->context->audit('DropoffAuthorizedCorrection', $passenger, ['reason' => $payload['override_reason'] ?? null]);
            }

            $occurredAt = isset($payload['occurred_at']) ? CarbonImmutable::parse($payload['occurred_at']) : CarbonImmutable::now();
            $stopId = $payload['stop_id'] ?? $passenger->dropoff_stop_id;

            // §24/§35 verifikasi penjemput yang berwenang
            $pickupCheck = $this->verifyAuthorizedPickup($passenger, $payload);
            if (! $pickupCheck['authorized'] && ! ($payload['allow_override'] ?? false)) {
                throw ValidationException::withMessages(['authorized_pickup' => $pickupCheck['reason']]);
            }

            $status = $pickupCheck['authorized_pickup_person_id'] ? 'AUTHORIZED_PICKUP' : 'DROPPED_OFF';

            $passenger->forceFill([
                'dropoff_status' => $status,
                'dropped_off_at' => $occurredAt,
                'dropped_off_stop_id' => $stopId,
                'dropped_off_method' => $method,
                'authorized_pickup_person_id' => $pickupCheck['authorized_pickup_person_id'],
                'picked_up_by_name' => $pickupCheck['name'],
            ])->save();

            $event = DropoffEvent::query()->create([
                'trip_id' => $trip->id,
                'trip_passenger_id' => $passenger->id,
                'student_id' => $passenger->student_id,
                'employee_id' => $passenger->employee_id,
                'stop_id' => $stopId,
                'method' => $method,
                'status_after' => $status,
                'occurred_at' => $occurredAt,
                'authorized_pickup_person_id' => $pickupCheck['authorized_pickup_person_id'],
                'picked_up_by_name' => $pickupCheck['name'],
                'authorized_pickup_verified' => $pickupCheck['authorized'],
                'override_used' => ! $pickupCheck['authorized'] && (bool) ($payload['allow_override'] ?? false),
                'override_reason' => $payload['override_reason'] ?? null,
                'device_id' => $payload['device_id'] ?? null,
                'latitude' => $payload['latitude'] ?? null,
                'longitude' => $payload['longitude'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'source' => $payload['source'] ?? 'DRIVER_APP',
                'offline_sync' => (bool) ($payload['offline_sync'] ?? false),
                'recorded_by' => $this->context->actorId(),
            ]);

            if ($stopId) {
                $this->recordStopEvent($trip, (int) $stopId, 'DROPPED_OFF', $passenger->student_id, $occurredAt, $payload);
            }

            $this->manifest->recalculate($trip);
            $this->manifest->updateStopCounters($trip);

            $attendance = $this->mirrorAttendance($trip, $passenger, 'PRESENT', $occurredAt, $method, 'DROPOFF');

            $notification = $passenger->student_id
                ? $this->notifications->toGuardians('PassengerDroppedOff', (int) $passenger->student_id,
                    'Anak Anda sudah turun',
                    'Penumpang turun pada trip '.$trip->trip_number.' pukul '.$occurredAt->format('H:i')
                        .($pickupCheck['name'] ? ' diserahkan kepada '.$pickupCheck['name'].'.' : '.'),
                    ['trip_id' => $trip->id, 'idempotency_key' => 'dropped-'.$trip->id.'-'.$passenger->id])
                : null;

            $this->context->publish('PassengerDroppedOff', $trip, [
                'trip_number' => $trip->trip_number,
                'student_id' => $passenger->student_id,
                'stop_id' => $stopId,
                'authorized_pickup' => $pickupCheck['authorized'],
                'status' => $status,
            ]);

            return [
                'applied' => true,
                'dropoff_event_id' => $event->id,
                'trip_id' => $trip->id,
                'passenger_id' => $passenger->id,
                'student_id' => $passenger->student_id,
                'dropoff_status' => $status,
                'dropped_off_at' => $occurredAt,
                'authorized_pickup' => $pickupCheck,
                'attendance_reference' => $attendance,
                'notification' => $notification,
            ];
        }, ['source' => $method, 'device_id' => $payload['device_id'] ?? null]);
        return $result;
    }

    public function markNoShow(array $payload, ?string $idempotencyKey = null): array
    {
        $trip = $this->resolveTrip($payload);
        $passenger = $this->resolvePassenger($trip, $payload, false);
        $key = $idempotencyKey ?? ($payload['idempotency_key'] ?? 'noshow-'.$trip->id.'-'.$passenger->id);

        return $this->context->idempotent('BOARDING', $key, function () use ($trip, $passenger, $payload): array {
            $passenger->forceFill([
                'boarding_status' => 'NO_SHOW',
                'absence_reason' => $payload['reason'] ?? 'Tidak hadir di titik penjemputan.',
            ])->save();

            $this->manifest->recalculate($trip);
            $this->manifest->updateStopCounters($trip);

            $notification = $passenger->student_id
                ? $this->notifications->toGuardians('PassengerNoShow', (int) $passenger->student_id,
                    'Anak Anda tidak naik kendaraan',
                    'Penumpang tidak hadir pada titik penjemputan trip '.$trip->trip_number.' ('.$trip->trip_date?->toDateString().').',
                    ['trip_id' => $trip->id, 'idempotency_key' => 'noshow-'.$trip->id.'-'.$passenger->id])
                : null;

            $this->context->publish('PassengerNoShow', $trip, [
                'trip_number' => $trip->trip_number, 'student_id' => $passenger->student_id,
                'reason' => $payload['reason'] ?? null,
            ]);

            return [
                'applied' => true,
                'passenger_id' => $passenger->id,
                'student_id' => $passenger->student_id,
                'boarding_status' => 'NO_SHOW',
                'reason' => $passenger->absence_reason,
                'notification' => $notification,
            ];
        }, ['source' => 'DRIVER_APP']);
    }

    public function markAbsent(array $payload): array
    {
        $trip = $this->resolveTrip($payload);
        $passenger = $this->resolvePassenger($trip, $payload, false);
        $status = strtoupper((string) ($payload['status'] ?? 'ABSENT'));
        if (! in_array($status, ['ABSENT', 'EXCUSED'], true)) {
            throw ValidationException::withMessages(['status' => 'Status ketidakhadiran harus ABSENT atau EXCUSED.']);
        }

        $passenger->forceFill([
            'boarding_status' => $status,
            'absence_reason' => $payload['reason'] ?? null,
        ])->save();

        $this->manifest->recalculate($trip);

        $this->context->audit('PassengerMarkedAbsent', $passenger, ['status' => $status, 'reason' => $payload['reason'] ?? null]);

        return ['applied' => true, 'passenger_id' => $passenger->id, 'boarding_status' => $status];
    }

    // ── RFID / NFC / QR (§37/§38) ──────────────────────────────────────────

    public function ingestScan(array $payload): array
    {
        $deviceId = $payload['device_id'] ?? null;
        if ($deviceId && ! $this->deviceAvailable((string) $deviceId)) {
            $this->context->audit('ScanRejectedUnknownDevice', null, ['device_id' => $deviceId]);

            return [
                'applied' => false,
                'reason' => 'Perangkat tidak terdaftar atau tidak aktif; event diabaikan.',
                'device_id' => $deviceId,
            ];
        }

        $studentId = $this->resolveScannedStudent($payload);
        if (! $studentId) {
            return ['applied' => false, 'reason' => 'Kartu/token tidak dapat dipetakan ke siswa mana pun.'];
        }

        $trip = $this->resolveTrip($payload);
        $passenger = $this->resolvePassenger($trip, ['student_id' => $studentId], true);

        $payload['student_id'] = $studentId;
        $payload['method'] = strtoupper((string) ($payload['method'] ?? 'RFID'));

        $action = $this->decideScanAction($trip, $passenger, $payload);

        return $action === 'DROPOFF'
            ? $this->dropoff($payload + ['trip_id' => $trip->id])
            : $this->board($payload + ['trip_id' => $trip->id]);
    }

    /** Perangkat harus terdaftar & aktif sebelum eventnya dipercaya (§90). */
    public function deviceAvailable(string $deviceId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('transportation_devices')) {
            return false;
        }

        return DB::table('transportation_devices')
            ->where('device_id', $deviceId)
            ->where('active', true)
            ->exists();
    }

    private function resolveScannedStudent(array $payload): ?int
    {
        if (! empty($payload['student_id'])) {
            return (int) $payload['student_id'];
        }

        if (! empty($payload['token'])) {
            $verified = $this->verifyBoardingToken((string) $payload['token']);
            if ($verified['valid']) {
                return (int) $verified['payload']['student_id'];
            }
        }

        if (! empty($payload['card_id'])) {
            $previous = BoardingEvent::query()->where('card_id', $payload['card_id'])
                ->whereNotNull('student_id')->orderByDesc('id')->first();
            if ($previous) {
                return (int) $previous->student_id;
            }
        }

        if (! empty($payload['student_reference'])) {
            $matches = $this->students->search((string) $payload['student_reference'], 1);
            if ($matches !== [] && ! empty($matches[0]['id'])) {
                return (int) $matches[0]['id'];
            }
        }

        return null;
    }

    private function decideScanAction(Trip $trip, TripPassenger $passenger, array $payload): string
    {
        $direction = strtoupper((string) ($payload['scan_context'] ?? $trip->direction));

        if ($passenger->boarding_status === 'EXPECTED' && $passenger->dropoff_status === 'EXPECTED') {
            return $direction === 'DROPOFF' ? 'BOARD' : 'BOARD';
        }
        if ($passenger->boarding_status === 'BOARDED' && $passenger->dropoff_status !== 'DROPPED_OFF') {
            return 'DROPOFF';
        }

        return 'BOARD';
    }

    /** Token QR bertanda tangan (§38) — tanpa data pribadi di dalam token. */
    public function issueBoardingToken(int $studentId, int $tripId, array $options = []): array
    {
        $ttl = (int) ($options['ttl_minutes'] ?? config('transportation.qr.ttl_minutes', 120));
        $payload = [
            'student_id' => $studentId,
            'trip_id' => $tripId,
            'exp' => now()->addMinutes($ttl)->getTimestamp(),
            'nonce' => bin2hex(random_bytes(6)),
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, (string) config('app.key'));

        return [
            'token' => $encoded.'.'.$signature,
            'expires_at' => now()->addMinutes($ttl)->toIso8601String(),
            'ttl_minutes' => $ttl,
            'contains_personal_data' => false,
        ];
    }

    public function verifyBoardingToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return ['valid' => false, 'reason' => 'Format token tidak dikenal.'];
        }
        [$encoded, $signature] = $parts;
        $expected = hash_hmac('sha256', $encoded, (string) config('app.key'));
        if (! hash_equals($expected, $signature)) {
            return ['valid' => false, 'reason' => 'Tanda tangan token tidak sah.'];
        }

        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
        if (! is_array($payload) || ($payload['exp'] ?? 0) < now()->getTimestamp()) {
            return ['valid' => false, 'reason' => 'Token kedaluwarsa.'];
        }

        return ['valid' => true, 'payload' => $payload];
    }

    // ── Sinkronisasi offline (§89) ─────────────────────────────────────────

    public function syncOfflineBatch(array $events): array
    {
        $results = [];
        foreach ($events as $index => $event) {
            $type = strtoupper((string) ($event['type'] ?? 'BOARD'));
            $payload = (array) ($event['payload'] ?? []);
            $payload['offline_sync'] = true;
            $payload['device_id'] = $payload['device_id'] ?? ($event['device_id'] ?? null);

            try {
                $result = match ($type) {
                    'BOARD' => $this->board($payload, $event['idempotency_key'] ?? null),
                    'DROPOFF' => $this->dropoff($payload, $event['idempotency_key'] ?? null),
                    'NO_SHOW' => $this->markNoShow($payload, $event['idempotency_key'] ?? null),
                    'PRETRIP' => app(InspectionService::class)->preTrip($payload, $event['idempotency_key'] ?? null),
                    'POSTTRIP' => app(InspectionService::class)->postTrip($payload, $event['idempotency_key'] ?? null),
                    'INCIDENT' => app(IncidentService::class)->report($payload, $event['idempotency_key'] ?? null),
                    default => ['applied' => false, 'reason' => 'Jenis event offline tidak dikenal: '.$type],
                };
                $results[] = ['index' => $index, 'type' => $type, 'status' => ($result['duplicate'] ?? false) ? 'DUPLICATE' : ($result['applied'] ?? true ? 'APPLIED' : 'SKIPPED'), 'result' => $result];
            } catch (ValidationException $exception) {
                $results[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => 'CONFLICT',
                    'reason' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                    'resolution' => config('transportation.offline.conflict_resolution', 'SERVER_WINS_WITH_AUDIT'),
                    'recorded_for_review' => true,
                ];
                $this->context->audit('OfflineSyncConflict', null, ['index' => $index, 'type' => $type, 'errors' => $exception->errors()]);
            }
        }

        return [
            'received' => count($events),
            'applied' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'APPLIED')),
            'duplicates' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'DUPLICATE')),
            'conflicts' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'CONFLICT')),
            'results' => $results,
            'synced_at' => now()->toIso8601String(),
        ];
    }

    // ── Utilitas ───────────────────────────────────────────────────────────

    private function resolveTrip(array $payload): Trip
    {
        if (! empty($payload['trip_id'])) {
            return Trip::query()->findOrFail((int) $payload['trip_id']);
        }
        if (! empty($payload['trip_number'])) {
            return Trip::query()->where('trip_number', $payload['trip_number'])->firstOrFail();
        }

        throw ValidationException::withMessages(['trip_id' => 'Trip wajib ditentukan (trip_id atau trip_number).']);
    }

    private function resolvePassenger(Trip $trip, array $payload, bool $createIfMissing): TripPassenger
    {
        if (! empty($payload['trip_passenger_id'])) {
            return TripPassenger::query()->where('trip_id', $trip->id)->findOrFail((int) $payload['trip_passenger_id']);
        }

        $studentId = $payload['student_id'] ?? null;
        $employeeId = $payload['employee_id'] ?? null;

        $passenger = TripPassenger::query()
            ->where('trip_id', $trip->id)
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when(! $studentId && $employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->first();

        if ($passenger) {
            return $passenger;
        }

        if (! $createIfMissing) {
            throw ValidationException::withMessages([
                'student_id' => 'Penumpang tidak terdaftar pada manifes trip ini.',
            ]);
        }

        if (! $studentId && ! $employeeId) {
            throw ValidationException::withMessages(['student_id' => 'Penumpang tidak terdaftar dan data siswa/pegawai tidak diberikan.']);
        }

        return $this->manifest->addPassenger($trip, [
            'student_id' => $studentId,
            'employee_id' => $employeeId,
            'pickup_stop_id' => $payload['stop_id'] ?? null,
            'dropoff_stop_id' => $payload['stop_id'] ?? null,
            'passenger_type' => $studentId ? 'STUDENT' : 'STAFF',
        ]);
    }

    private function recordStopEvent(Trip $trip, int $stopId, string $type, ?int $studentId, CarbonImmutable $at, array $payload): void
    {
        DB::table('trip_stop_events')->insert([
            'trip_id' => $trip->id,
            'trip_stop_id' => $stopId,
            'event_type' => $type,
            'student_id' => $studentId,
            'driver_profile_id' => $trip->driver_profile_id,
            'occurred_at' => $at,
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'device_id' => $payload['device_id'] ?? null,
            'source' => $payload['source'] ?? 'DRIVER_APP',
            'idempotency_key' => $payload['idempotency_key'] ?? null,
            'recorded_by' => $this->context->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mirrorAttendance(Trip $trip, TripPassenger $passenger, string $status, CarbonImmutable $at, string $method, string $phase = 'BOARDING'): ?array
    {
        $reference = $this->attendance->mirror([
            'student_id' => $passenger->student_id,
            'employee_id' => $passenger->employee_id,
            'date' => $at->toDateString(),
            'time' => $at->format('H:i:s'),
            'status' => $status,
            'source' => 'TRANSPORTATION',
            'method' => $method,
            'phase' => $phase,
            'reference' => 'trip:'.$trip->id,
            'trip_number' => $trip->trip_number,
        ]);

        return $reference ? ['reference' => $reference, 'master' => 'attendance_module'] : ['delegated' => false];
    }

    /** Verifikasi penjemput berwenang (§24). */
    public function verifyAuthorizedPickup(TripPassenger $passenger, array $payload): array
    {
        $personId = $payload['authorized_pickup_person_id'] ?? null;
        $name = $payload['picked_up_by_name'] ?? null;

        if (! $passenger->student_id) {
            return ['authorized' => true, 'authorized_pickup_person_id' => null, 'name' => $name, 'reason' => null];
        }

        if (! $personId && ! $name) {
            $hasRestriction = DB::table('student_transport_profiles')
                ->where('student_id', $passenger->student_id)
                ->where('authorized_pickup_restricted', true)
                ->exists();

            if ($hasRestriction) {
                return [
                    'authorized' => false,
                    'authorized_pickup_person_id' => null,
                    'name' => null,
                    'reason' => 'Siswa ini hanya boleh diserahkan kepada penjemput yang terdaftar.',
                ];
            }

            return ['authorized' => true, 'authorized_pickup_person_id' => null, 'name' => null, 'reason' => null];
        }

        $query = AuthorizedPickupPerson::query()
            ->where('student_id', $passenger->student_id)
            ->where('status', 'ACTIVE');

        $person = $personId
            ? (clone $query)->where('id', $personId)->first()
            : (clone $query)->where('name', $name)->first();

        if (! $person) {
            return [
                'authorized' => false,
                'authorized_pickup_person_id' => $personId,
                'name' => $name,
                'reason' => 'Penjemput tidak terdaftar sebagai penjemput berwenang untuk siswa ini.',
            ];
        }

        $today = now()->toDateString();
        $valid = (! $person->valid_from || $person->valid_from->toDateString() <= $today)
            && (! $person->valid_until || $person->valid_until->toDateString() >= $today);

        if (! $valid) {
            return [
                'authorized' => false,
                'authorized_pickup_person_id' => $person->id,
                'name' => $person->name,
                'reason' => 'Masa berlaku izin penjemput sudah berakhir/belum berlaku.',
            ];
        }

        return [
            'authorized' => true,
            'authorized_pickup_person_id' => $person->id,
            'name' => $person->name,
            'relationship' => $person->relationship,
            'reason' => null,
        ];
    }

    /** Status hari ini untuk portal wali/siswa (§73/§74). */
    public function studentTodayStatus(int $studentId, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $passengers = TripPassenger::query()
            ->where('student_id', $studentId)
            ->whereHas('trip', fn ($q) => $q->whereDate('trip_date', $date))
            ->with(['trip.route', 'trip.vehicle', 'trip.driver'])
            ->get();

        return [
            'date' => $date,
            'trips' => $passengers->map(fn (TripPassenger $passenger): array => [
                'trip_id' => $passenger->trip_id,
                'trip_number' => $passenger->trip?->trip_number,
                'direction' => $passenger->trip?->direction,
                'status' => $passenger->trip?->status,
                'boarded_at' => $passenger->boarded_at,
                'dropped_off_at' => $passenger->dropped_off_at,
                'boarding_status' => $passenger->boarding_status,
                'dropoff_status' => $passenger->dropoff_status,
                'pickup_stop_id' => $passenger->pickup_stop_id,
                'dropoff_stop_id' => $passenger->dropoff_stop_id,
                'vehicle' => $passenger->trip?->vehicle?->only(['id', 'vehicle_code', 'registration_number']),
                'driver' => $passenger->trip?->driver?->only(['id', 'driver_code', 'full_name']),
            ])->all(),
        ];
    }

    public function tripStopsWithCounts(int $tripId): array
    {
        return TripStop::query()->where('trip_id', $tripId)->orderBy('sequence')->get()->map(
            fn (TripStop $stop): array => [
                'id' => $stop->id,
                'sequence' => $stop->sequence,
                'name' => $stop->name,
                'status' => $stop->status,
                'planned_arrival_at' => $stop->planned_arrival_at,
                'actual_arrival_at' => $stop->actual_arrival_at,
                'planned_passengers' => $stop->planned_passengers,
                'boarded_count' => $stop->boarded_count,
                'dropped_off_count' => $stop->dropped_off_count,
                'no_show_count' => $stop->no_show_count,
            ]
        )->all();
    }
}
