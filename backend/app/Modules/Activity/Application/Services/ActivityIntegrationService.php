<?php

namespace App\Modules\Activity\Application\Services;

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
use App\Modules\Activity\Domain\Enums\IntegrationName;
use App\Modules\Activity\Domain\Enums\IntegrationStatus;
use App\Modules\Activity\Models\ActivityIntegrationLog;
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
}
