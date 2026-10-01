<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\AssetProviderInterface;
use App\Modules\Activity\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Activity\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Activity\Domain\Contracts\CertificateProviderInterface;
use App\Modules\Activity\Domain\Contracts\CounselingProviderInterface;
use App\Modules\Activity\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Activity\Domain\Contracts\EventProviderInterface;
use App\Modules\Activity\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Activity\Domain\Contracts\FacilityProviderInterface;
use App\Modules\Activity\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Activity\Domain\Contracts\LmsProviderInterface;
use App\Modules\Activity\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Activity\Domain\Contracts\Scheduling\AcademicProviderInterface as UnusedInterface;
use App\Modules\Activity\Domain\Enums\ChargeStatus;
use App\Modules\Activity\Domain\Enums\EquipmentRequestStatus;
use App\Modules\Activity\Domain\Enums\FacilityBookingStatus;
use App\Modules\Activity\Domain\Enums\IntegrationName;
use App\Modules\Activity\Domain\Enums\IntegrationStatus;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityCharge;
use App\Modules\Activity\Models\ActivityEquipmentRequest;
use App\Modules\Activity\Models\ActivityFacilityBooking;
use App\Modules\Activity\Models\ActivityIntegrationLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Panggung integrasi (§83, §tentang log).
 *
 * Setiap panggilan ke modul lain melewati satu pintu ini, sehingga:
 *  - kegagalan modul tetangga **tidak menjatuhkan** alur aktivitas (ditangkap,
 *    dicatat, dan dilaporkan sebagai status),
 *  - sekolah punya bukti nyata kapan integrasi dipanggil, apa jawabannya, dan
 *    berapa lama — bukan asumsi bahwa "seharusnya jalan".
 */
class ActivityIntegrationService
{
    public function __construct(
        private readonly FinanceProviderInterface $finance,
        private readonly InventoryProviderInterface $inventory,
        private readonly AssetProviderInterface $assets,
        private readonly FacilityProviderInterface $facilities,
        private readonly LmsProviderInterface $lms,
        private readonly EventProviderInterface $events,
        private readonly \App\Modules\Activity\Domain\Contracts\TransportationProviderInterface $transportation,
        private readonly AttendanceProviderInterface $attendance,
        private readonly BoardingProviderInterface $boarding,
        private readonly CertificateProviderInterface $certificates,
        private readonly DocumentProviderInterface $documents,
        private readonly NotificationProviderInterface $notifications,
        private readonly CounselingProviderInterface $counseling,
        private readonly ActivityAuditService $audit,
    ) {}

    public function finance(): FinanceProviderInterface { return $this->finance; }

    public function inventory(): InventoryProviderInterface { return $this->inventory; }

    public function assets(): AssetProviderInterface { return $this->assets; }

    public function facilities(): FacilityProviderInterface { return $this->facilities; }

    public function lms(): LmsProviderInterface { return $this->lms; }

    public function events(): EventProviderInterface { return $this->events; }

    public function transportation(): \App\Modules\Activity\Domain\Contracts\TransportationProviderInterface { return $this->transportation; }

    public function attendance(): AttendanceProviderInterface { return $this->attendance; }

    public function boarding(): BoardingProviderInterface { return $this->boarding; }

    public function certificates(): CertificateProviderInterface { return $this->certificates; }

    public function documents(): DocumentProviderInterface { return $this->documents; }

    public function notifications(): NotificationProviderInterface { return $this->notifications; }

    public function counseling(): CounselingProviderInterface { return $this->counseling; }

    /**
     * Jalankan panggilan integrasi dengan pencatatan.
     *
     * @param  callable():array  $callback
     */
    public function call(IntegrationName|string $integration, string $action, array $payload, callable $callback): array
    {
        $started = microtime(true);

        try {
            $response = $callback();
            $status = ($response['status'] ?? 'OK') === 'SKIPPED' ? IntegrationStatus::SKIPPED : IntegrationStatus::OK;

            $this->log($integration, $action, $payload, $response, $status, null, $started);

            return $response;
        } catch (Throwable $exception) {
            $this->log($integration, $action, $payload, null, IntegrationStatus::FAILED, $exception->getMessage(), $started);

            return [
                'status' => 'FAILED',
                'reason' => $exception->getMessage(),
                'degraded' => true,
            ];
        }
    }

    /** Riwayat panggilan integrasi untuk layar kesehatan modul. */
    public function recent(IntegrationName|string|null $integration = null, int $limit = 50): array
    {
        $value = $integration instanceof IntegrationName ? $integration->value : $integration;

        return ActivityIntegrationLog::query()
            ->when($value !== null, fn ($query) => $query->where('integration', $value))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityIntegrationLog $log) => [
                'id' => (int) $log->id,
                'integration' => $log->integration?->value,
                'action' => $log->action,
                'status' => $log->status?->value,
                'duration_ms' => $log->duration_ms,
                'error' => $log->error,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function health(): array
    {
        return [
            'lms' => $this->lms->isAvailable(),
            'events' => $this->events->isAvailable(),
            'transportation' => $this->transportation->isAvailable(),
            'finance_mode' => config('activity.finance.create_invoices', false) ? 'invoice' : 'queue',
            'unpaid_policy' => $this->finance->unpaidPolicy(),
            'attendance_mirror' => (bool) config('activity.attendance.mirror', false),
        ];
    }

    protected function log(
        IntegrationName|string $integration,
        string $action,
        array $payload,
        ?array $response,
        IntegrationStatus $status,
        ?string $error,
        float $started,
    ): void {
        ActivityIntegrationLog::query()->create([
            'integration' => $integration instanceof IntegrationName ? $integration->value : $integration,
            'action' => $action,
            'subject_type' => $payload['subject_type'] ?? null,
            'subject_id' => isset($payload['subject_id']) ? (string) $payload['subject_id'] : null,
            'payload' => $payload,
            'response' => $response,
            'status' => $status->value,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'actor_user_id' => Auth::id(),
        ]);
    }

    // --------------------------------------------------------------- daftar & tindak lanjut
    //
    // Modul aktivitas adalah pihak yang *meminta* (tagihan, perlengkapan, ruang).
    // Permintaan itu perlu dapat dilihat dan dirapikan dari sisi aktivitas:
    // tagihan yang batal, permintaan perlengkapan yang sudah diserahkan modul
    // inventaris, dan pemesanan ruang yang tidak jadi dipakai. Tanpa ini, izin
    // `activity.finance.*`, `activity.inventory.*`, dan `activity.facility.*`
    // hanya ada di kertas — tidak ada satu pun layar yang memakainya.

    /** @param array<string, mixed> $filters */
    public function charges(array $filters = []): LengthAwarePaginator
    {
        return ActivityCharge::query()
            ->with(['program:id,name', 'student:id,full_name'])
            ->when(isset($filters['program_id']), fn ($query) => $query->where('program_id', $filters['program_id']))
            ->when(isset($filters['student_id']), fn ($query) => $query->where('student_id', $filters['student_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 30));
    }

    /**
     * Batalkan permintaan tagihan milik modul aktivitas.
     *
     * Hanya berlaku selama keuangan belum menerbitkan tagihan (`REQUESTED`) —
     * setelah menjadi tagihan nyata, pembatalan adalah urusan modul Keuangan.
     */
    public function cancelCharge(ActivityCharge $charge, User $actor, ?string $reason = null): ActivityCharge
    {
        if (! $actor->can('activity.finance.manage') && ! $actor->can('activity.program.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang membatalkan permintaan tagihan aktivitas.');
        }

        if ($charge->status !== ChargeStatus::REQUESTED) {
            throw ActivityConflictException::make(
                'Permintaan tagihan yang sudah diproses keuangan tidak dapat dibatalkan dari modul aktivitas.',
                ['status' => $charge->status?->value],
            );
        }

        return DB::transaction(function () use ($charge, $actor, $reason) {
            $before = ['status' => $charge->status?->value];

            $charge->forceFill(['status' => ChargeStatus::CANCELLED->value])->save();

            $this->audit->record(AuditAction::CHARGE_CANCELLED, [
                'subject' => $charge,
                'student_id' => $charge->student_id === null ? null : (int) $charge->student_id,
                'before' => $before,
                'after' => ['status' => $charge->status?->value],
                'metadata' => ['reason' => $reason],
                'actor' => $actor->id,
            ]);

            return $charge->refresh();
        });
    }

    /** @param array<string, mixed> $filters */
    public function equipmentRequests(array $filters = []): LengthAwarePaginator
    {
        return ActivityEquipmentRequest::query()
            ->with(['program:id,name', 'student:id,full_name'])
            ->when(isset($filters['program_id']), fn ($query) => $query->where('program_id', $filters['program_id']))
            ->when(isset($filters['student_id']), fn ($query) => $query->where('student_id', $filters['student_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 30));
    }

    /**
     * Selaraskan status permintaan perlengkapan dengan kenyataan di modul Inventaris.
     *
     * Stok tetap milik modul Inventaris: di sini hanya status permintaan yang
     * dirapikan (mis. barang sudah diserahkan) beserta jejak auditnya.
     */
    public function updateEquipmentRequest(ActivityEquipmentRequest $request, string $status, User $actor, ?string $notes = null): ActivityEquipmentRequest
    {
        if (! $actor->can('activity.inventory.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang menyelaraskan permintaan perlengkapan.');
        }

        $target = EquipmentRequestStatus::tryFrom($status);

        if ($target === null) {
            throw ActivityValidationException::withErrors(['Status permintaan perlengkapan tidak dikenal.']);
        }

        return DB::transaction(function () use ($request, $target, $actor, $notes) {
            $before = ['status' => $request->status?->value];

            $request->forceFill([
                'status' => $target->value,
                'notes' => $notes ?? $request->notes,
                'issued_at' => $target === EquipmentRequestStatus::ISSUED ? ($request->issued_at ?? now()) : $request->issued_at,
                'returned_at' => $target === EquipmentRequestStatus::RETURNED ? now() : $request->returned_at,
            ])->save();

            $this->audit->record(AuditAction::EQUIPMENT_REQUEST_UPDATED, [
                'subject' => $request,
                'before' => $before,
                'after' => ['status' => $target->value],
                'metadata' => ['notes' => $notes],
                'actor' => $actor->id,
            ]);

            return $request->refresh();
        });
    }

    /** @param array<string, mixed> $filters */
    public function facilityBookings(array $filters = []): LengthAwarePaginator
    {
        return ActivityFacilityBooking::query()
            ->with(['program:id,name', 'schedule:id,day_of_week,start_time,end_time'])
            ->when(isset($filters['program_id']), fn ($query) => $query->where('program_id', $filters['program_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['room_id']), fn ($query) => $query->where('room_id', $filters['room_id']))
            ->when(isset($filters['date']), fn ($query) => $query->where('date', $filters['date']))
            ->orderByDesc('date')
            ->paginate((int) ($filters['per_page'] ?? 30));
    }

    /**
     * Batalkan pemesanan ruang aktivitas.
     *
     * Pembatalan diteruskan ke modul Sarpras lewat kontrak: jadwal ruang adalah
     * miliknya, bukan milik modul aktivitas. Bila modul itu tidak menjawab,
     * pemesanan tetap ditandai dibatalkan di sisi aktivitas dan kegagalannya
     * tercatat — bukan disembunyikan.
     */
    public function cancelFacilityBooking(ActivityFacilityBooking $booking, User $actor, ?string $reason = null): ActivityFacilityBooking
    {
        if (! $actor->can('activity.facility.manage') && ! $actor->can('activity.schedule.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang membatalkan pemesanan fasilitas aktivitas.');
        }

        return DB::transaction(function () use ($booking, $actor, $reason) {
            $cancelled = false;

            if ($booking->external_reference !== null) {
                $cancelled = $this->call('FACILITY', 'cancel', ['reference' => $booking->external_reference], fn () => ['cancelled' => $this->facilities->cancel((string) $booking->external_reference)]);
                $cancelled = (bool) ($cancelled['cancelled'] ?? false);
            }

            $before = ['status' => $booking->status?->value];

            $booking->forceFill([
                'status' => FacilityBookingStatus::CANCELLED->value,
                'conflict_reason' => $reason ?? $booking->conflict_reason,
            ])->save();

            $this->audit->record(AuditAction::FACILITY_BOOKING_CANCELLED, [
                'subject' => $booking,
                'before' => $before,
                'after' => ['status' => $booking->status?->value, 'external_cancelled' => $cancelled],
                'metadata' => ['reason' => $reason],
                'actor' => $actor->id,
            ]);

            return $booking->refresh();
        });
    }
}
