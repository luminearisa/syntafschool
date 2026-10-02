<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\ClinicGatewayInterface;
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Domain\TransportStatus;
use App\Modules\SchoolOperations\Transportation\Models\{
    IncidentAction, IncidentAttachment, PassengerTransfer, TransportationIncident, Trip, TripPassenger, VehicleProfile, DriverProfile
};
use Illuminate\Validation\ValidationException;

/**
 * Insiden (§45-§49): pelaporan pengemudi/staf, klasifikasi keparahan,
 * eskalasi, tindakan darurat, penggantian kendaraan/pengemudi dengan
 * pemindahan penumpang, resolusi, tinjauan, dan penutupan.
 */
class IncidentService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly TripService $trips,
        private readonly TransportationNotificationService $notifications,
        private readonly ClinicGatewayInterface $clinic,
    ) {
    }

    /** Laporkan insiden baru (idempoten untuk sinkronisasi offline). */
    public function report(array $payload, ?string $idempotencyKey = null): array
    {
        $type = strtoupper((string) ($payload['incident_type'] ?? 'OTHER'));
        if (! in_array($type, TransportStatus::incidentTypes(), true)) {
            throw ValidationException::withMessages(['incident_type' => 'Jenis insiden tidak dikenal: '.$type]);
        }
        $severity = strtoupper((string) ($payload['severity'] ?? 'LOW'));
        if (! in_array($severity, TransportStatus::incidentSeverities(), true)) {
            throw ValidationException::withMessages(['severity' => 'Tingkat keparahan tidak dikenal: '.$severity]);
        }

        $result = $this->context->idempotent('INCIDENT', $idempotencyKey, function () use ($payload, $type, $severity, $idempotencyKey): array {
            $trip = ! empty($payload['trip_id']) ? Trip::query()->find((int) $payload['trip_id']) : null;

            $incident = TransportationIncident::query()->create([
                'incident_number' => 'INC-'.now()->format('ymd').'-'.str_pad((string) (TransportationIncident::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'incident_type' => $type,
                'severity' => $severity,
                'trip_id' => $trip?->id,
                'vehicle_id' => $payload['vehicle_id'] ?? $trip?->vehicle_id,
                'driver_profile_id' => $payload['driver_profile_id'] ?? $trip?->driver_profile_id,
                'student_id' => $payload['student_id'] ?? null,
                'route_id' => $payload['route_id'] ?? $trip?->route_id,
                'stop_id' => $payload['stop_id'] ?? null,
                'occurred_at' => $payload['occurred_at'] ?? now(),
                'location_description' => $payload['location_description'] ?? null,
                'latitude' => $payload['latitude'] ?? null,
                'longitude' => $payload['longitude'] ?? null,
                'description' => (string) ($payload['description'] ?? 'Insiden dilaporkan tanpa deskripsi rinci.'),
                'injured_count' => (int) ($payload['injured_count'] ?? 0),
                'passengers_involved' => (bool) ($payload['passengers_involved'] ?? false),
                'status' => 'REPORTED',
                'reported_by' => $this->context->actorId(),
                'reported_by_type' => strtoupper((string) ($payload['reported_by_type'] ?? 'DRIVER')),
                'idempotency_key' => $idempotencyKey,
                'offline_sync' => (bool) ($payload['offline_sync'] ?? false),
                'meta' => $payload['meta'] ?? null,
            ]);

            $emergency = $this->emergencyPlan($incident, $severity);
            $incident->forceFill(['emergency_actions' => $emergency])->save();

            $actions = [];
            $actions[] = IncidentAction::query()->create([
                'incident_id' => $incident->id,
                'action_type' => 'REPORTED',
                'description' => 'Insiden dilaporkan oleh '.$incident->reported_by_type.'.',
                'performed_by' => $this->context->actorId(),
                'performed_at' => now(),
                'payload' => ['severity' => $severity, 'type' => $type],
            ]);

            if ($trip) {
                $this->trips->markIncident($trip, $incident->incident_number);
            }

            // Notifikasi admin/koordinator (§86 admin: CriticalIncident, VehicleFailure, …)
            $adminEvent = match ($type) {
                'BREAKDOWN' => 'VehicleFailure',
                'DELAY' => 'TripDelayed',
                default => $severity === 'CRITICAL' ? 'CriticalIncident' : 'IncidentReported',
            };
            $adminNotification = $this->notifications->toAdmins($adminEvent,
                'Insiden '.$type.' ('.$severity.')',
                $incident->incident_number.': '.mb_substr($incident->description, 0, 160),
                ['incident_id' => $incident->id, 'trip_id' => $incident?->id, 'idempotency_key' => 'inc-admin-'.$incident->id]
            );

            // Notifikasi wali bila penumpang terlibat
            $guardianNotifications = [];
            if ($incident->passengers_involved && $trip) {
                $guardianNotifications[] = $this->notifyGuardiansOfTrip($incident, $trip, 'Insiden pada perjalanan');
                $incident->forceFill(['parent_notified' => true])->save();
            }

            if ($severity === 'CRITICAL') {
                $this->escalate($incident, null, 'Eskalasi otomatis untuk insiden CRITICAL.');
            }

            $this->context->publish('IncidentCreated', $incident, [
                'incident_number' => $incident->incident_number,
                'type' => $type,
                'severity' => $severity,
                'trip_id' => $trip?->id,
            ]);

            return [
                'incident_id' => $incident->id,
                'incident_number' => $incident->incident_number,
                'status' => $incident->status,
                'severity' => $incident->severity,
                'emergency_plan' => $emergency,
                'trip_status' => $trip?->refresh()->status,
                'actions' => count($actions),
                'admin_notification' => $adminNotification,
                'guardian_notifications' => $guardianNotifications,
            ];
        }, ['source' => 'INCIDENT', 'device_id' => $payload['device_id'] ?? null]);

        return $result;
    }

    /** Rencana darurat: kontak & langkah sesuai jenis/ keparahan (§48). */
    public function emergencyPlan(TransportationIncident $incident, string $severity): array
    {
        $contacts = $this->clinic->emergencyContacts();
        $steps = match ($incident->incident_type) {
            'ACCIDENT' => ['Pastikan keselamatan di lokasi', 'Hubungi layanan darurat bila ada korban', 'Laporkan ke dispatcher', 'Dokumentasi foto'],
            'BREAKDOWN' => ['Amankan kendaraan di tempat aman', 'Laporkan ke dispatcher untuk kendaraan pengganti', 'Jaga penumpang tetap di dalam bila aman'],
            'MEDICAL' => ['Berikan pertolongan pertama bila terlatih', 'Hubungi klinik/ambulans', 'Informasikan kepada wali'],
            'MISSING_PASSENGER' => ['Hentikan perjalanan', 'Cek manifes & titik terakhir', 'Hubungi wali & keamanan'],
            default => ['Laporkan ke dispatcher', 'Catat kronologi', 'Tunggu instruksi lanjutan'],
        };

        return [
            'severity' => $severity,
            'contacts' => $contacts,
            'steps' => $steps,
            'escalation_required' => in_array($severity, ['HIGH', 'CRITICAL'], true),
            'escalation_minutes' => (int) config('transportation.emergency.critical_escalation_minutes', 15),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function acknowledge(int $incidentId, ?int $actorId = null): array
    {
        $incident = TransportationIncident::query()->findOrFail($incidentId);
        $incident->forceFill([
            'status' => $incident->escalated ? 'ESCALATED' : 'ACKNOWLEDGED',
            'acknowledged_at' => now(),
            'acknowledged_by' => $actorId ?? $this->context->actorId(),
        ])->save();

        $this->addAction($incident, ['action_type' => 'ACKNOWLEDGED', 'description' => 'Insiden ditindaklanjuti dispatcher.']);

        return ['incident_id' => $incident->id, 'status' => $incident->status];
    }

    public function assign(int $incidentId, int $userId): array
    {
        $incident = TransportationIncident::query()->findOrFail($incidentId);
        $incident->forceFill(['assigned_to' => $userId, 'status' => 'IN_PROGRESS'])->save();
        $this->addAction($incident, ['action_type' => 'ASSIGNED', 'description' => 'Insiden ditugaskan ke petugas.', 'performed_by' => $userId]);

        return ['incident_id' => $incident->id, 'assigned_to' => $userId, 'status' => $incident->status];
    }

    public function escalate(TransportationIncident $incident, ?int $toUserId, string $reason): array
    {
        $incident->forceFill([
            'escalated' => true,
            'escalated_at' => now(),
            'escalated_to' => $toUserId,
            'status' => 'ESCALATED',
        ])->save();

        $this->addAction($incident, ['action_type' => 'ESCALATED', 'description' => $reason]);
        $this->context->publish('IncidentEscalated', $incident, ['reason' => $reason, 'severity' => $incident->severity]);

        return ['incident_id' => $incident->id, 'escalated' => true, 'escalated_to' => $toUserId, 'status' => $incident->status];
    }

    public function addAction(TransportationIncident $incident, array $payload): IncidentAction
    {
        $action = IncidentAction::query()->create([
            'incident_id' => $incident->id,
            'action_type' => strtoupper((string) ($payload['action_type'] ?? 'NOTE')),
            'description' => $payload['description'] ?? null,
            'performed_by' => $payload['performed_by'] ?? $this->context->actorId(),
            'performed_at' => now(),
            'payload' => $payload['payload'] ?? null,
            'status' => $payload['status'] ?? 'DONE',
        ]);

        $this->context->audit('IncidentActionAdded', $incident, ['action_type' => $action->action_type]);

        return $action;
    }

    public function attach(int $incidentId, array $payload): IncidentAttachment
    {
        $attachment = IncidentAttachment::query()->create([
            'incident_id' => $incidentId,
            'attachment_type' => strtoupper((string) ($payload['attachment_type'] ?? 'PHOTO')),
            'file_path' => $payload['file_path'],
            'original_name' => $payload['original_name'] ?? null,
            'mime_type' => $payload['mime_type'] ?? null,
            'uploaded_by' => $this->context->actorId(),
            'caption' => $payload['caption'] ?? null,
        ]);

        $this->context->audit('IncidentAttachmentAdded', $attachment, ['type' => $attachment->attachment_type]);

        return $attachment;
    }

    /**
     * Alur mogok kendaraan (§49): insiden BREAKDOWN → kendaraan & pengemudi
     * pengganti → pemindahan penumpang → notifikasi wali → trip dilanjutkan.
     * Trip asli tetap dipertahankan beserta relasinya.
     */
    public function handleBreakdown(Trip $trip, array $payload): array
    {
        $reported = $this->report([
            'incident_type' => 'BREAKDOWN',
            'severity' => strtoupper((string) ($payload['severity'] ?? 'HIGH')),
            'trip_id' => $trip->id,
            'description' => $payload['description'] ?? 'Kendaraan mengalami kerusakan di tengah perjalanan.',
            'passengers_involved' => true,
            'location_description' => $payload['location_description'] ?? null,
        ], $payload['idempotency_key'] ?? null);

        $incident = $reported['result'] ?? $reported;
        $incidentModel = TransportationIncident::query()->findOrFail($incident['incident_id']);

        $vehicleReplacement = null;
        if (! empty($payload['replacement_vehicle_id'])) {
            $replacement = VehicleProfile::query()->findOrFail((int) $payload['replacement_vehicle_id']);
            $vehicleReplacement = $this->trips->replaceVehicle($trip, $replacement, $payload['reason'] ?? 'Penggantian kendaraan karena kerusakan.', true, $this->context->actorId());
        }

        $driverReplacement = null;
        if (! empty($payload['replacement_driver_profile_id'])) {
            $driver = DriverProfile::query()->findOrFail((int) $payload['replacement_driver_profile_id']);
            $driverReplacement = app(DriverService::class)->replaceDriver($trip, $driver, $payload['reason'] ?? 'Penggantian pengemudi karena insiden.', $this->context->actorId());
        }

        // Trip dilanjutkan (bukan trip baru) sehingga riwayat tetap utuh.
        if ($trip->refresh()->status === 'INCIDENT' && $vehicleReplacement && ($vehicleReplacement['assigned'] ?? false)) {
            $trip->forceFill(['status' => 'IN_TRANSIT'])->save();
        }

        $this->addAction($incidentModel, [
            'action_type' => 'REPLACEMENT_DISPATCHED',
            'description' => 'Kendaraan pengganti diterbitkan untuk melanjutkan perjalanan.',
            'payload' => ['vehicle_id' => $vehicleReplacement['replacement_vehicle_id'] ?? null],
        ]);

        $passengerCount = TripPassenger::query()->where('trip_id', $trip->id)->count();

        return [
            'incident' => $incident,
            'vehicle_replacement' => $vehicleReplacement,
            'driver_replacement' => $driverReplacement,
            'passengers_transferred' => $payload['transfer_passengers'] ?? true ? $passengerCount : 0,
            'trip_status' => $trip->refresh()->status,
            'original_trip_preserved' => true,
            'trip_id' => $trip->id,
        ];
    }

    public function resolve(int $incidentId, string $resolution, ?int $actorId = null): array
    {
        $incident = TransportationIncident::query()->findOrFail($incidentId);
        $incident->forceFill([
            'status' => 'RESOLVED',
            'resolution' => $resolution,
            'resolved_at' => now(),
            'resolved_by' => $actorId ?? $this->context->actorId(),
        ])->save();

        $this->addAction($incident, ['action_type' => 'RESOLVED', 'description' => $resolution]);
        $this->context->publish('IncidentResolved', $incident, ['incident_number' => $incident->incident_number, 'resolution' => $resolution]);

        return ['incident_id' => $incident->id, 'status' => $incident->status, 'resolved_at' => $incident->resolved_at];
    }

    public function review(int $incidentId, string $notes, ?int $actorId = null): array
    {
        $incident = TransportationIncident::query()->findOrFail($incidentId);
        $incident->forceFill([
            'reviewed_by' => $actorId ?? $this->context->actorId(),
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        $this->addAction($incident, ['action_type' => 'REVIEWED', 'description' => $notes]);

        return ['incident_id' => $incident->id, 'reviewed_at' => $incident->reviewed_at, 'status' => $incident->status];
    }

    public function close(int $incidentId, ?int $actorId = null): array
    {
        $incident = TransportationIncident::query()->findOrFail($incidentId);
        if (! in_array($incident->status, ['RESOLVED', 'ESCALATED', 'IN_PROGRESS', 'ACKNOWLEDGED'], true)) {
            throw ValidationException::withMessages(['status' => 'Insiden berstatus '.$incident->status.' belum dapat ditutup.']);
        }

        $incident->forceFill([
            'status' => 'CLOSED',
            'closed_at' => now(),
            'closed_by' => $actorId ?? $this->context->actorId(),
        ])->save();

        $this->addAction($incident, ['action_type' => 'CLOSED', 'description' => 'Insiden ditutup.']);
        $this->context->audit('IncidentClosed', $incident, ['severity' => $incident->severity]);

        return ['incident_id' => $incident->id, 'status' => 'CLOSED', 'closed_at' => $incident->closed_at];
    }

    public function incidents(array $filters = []): array
    {
        return TransportationIncident::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->when($filters['severity'] ?? null, fn ($q, $severity) => $q->where('severity', strtoupper($severity)))
            ->when($filters['incident_type'] ?? null, fn ($q, $type) => $q->where('incident_type', strtoupper($type)))
            ->when($filters['trip_id'] ?? null, fn ($q, $tripId) => $q->where('trip_id', $tripId))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('occurred_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('occurred_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('occurred_at')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (TransportationIncident $incident): array => $this->snapshot($incident))
            ->all();
    }

    public function snapshot(TransportationIncident $incident): array
    {
        return [
            'id' => $incident->id,
            'incident_number' => $incident->incident_number,
            'incident_type' => $incident->incident_type,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'trip_id' => $incident->trip_id,
            'vehicle_id' => $incident->vehicle_id,
            'driver_profile_id' => $incident->driver_profile_id,
            'student_id' => $incident->student_id,
            'occurred_at' => $incident->occurred_at,
            'description' => $incident->description,
            'injured_count' => (int) $incident->injured_count,
            'passengers_involved' => (bool) $incident->passengers_involved,
            'escalated' => (bool) $incident->escalated,
            'parent_notified' => (bool) $incident->parent_notified,
            'resolution' => $incident->resolution,
            'resolved_at' => $incident->resolved_at,
            'closed_at' => $incident->closed_at,
            'emergency_actions' => $incident->emergency_actions,
            'actions' => IncidentAction::query()->where('incident_id', $incident->id)->orderBy('id')
                ->get(['id', 'action_type', 'description', 'performed_at'])->all(),
        ];
    }

    private function notifyGuardiansOfTrip(TransportationIncident $incident, Trip $trip, string $title): array
    {
        $notifications = [];
        foreach (TripPassenger::query()->where('trip_id', $trip->id)->pluck('student_id')->filter()->unique() as $studentId) {
            $notifications[] = $this->notifications->toGuardians('IncidentOccurred', (int) $studentId,
                $title,
                'Terdapat insiden ('.$incident->incident_type.') pada perjalanan '.$trip->trip_number.'. Tim transportasi sedang menangani.',
                ['trip_id' => $trip->id, 'incident_id' => $incident->id, 'idempotency_key' => 'inc-guard-'.$incident->id.'-'.$studentId]
            );
        }

        return ['sent' => count($notifications), 'notification_ids' => array_column($notifications, 'notification_id')];
    }
}
