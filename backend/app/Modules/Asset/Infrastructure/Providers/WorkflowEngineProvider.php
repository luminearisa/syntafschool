<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\WorkflowProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan ke Workflow Engine.
 *
 * Perilaku ketika mesin persetujuan TIDAK tersedia sengaja dipilih dengan hati-hati:
 * permintaan tetap MENUNGGU (tidak diam-diam disetujui) kecuali sekolah memang
 * menyatakan tidak menuntut persetujuan. Menyetujui otomatis akan membuat
 * penghapusan aset bernilai besar terlihat "sah" padahal tidak ada yang menyetujui.
 */
class WorkflowEngineProvider implements WorkflowProviderInterface
{
    public function start(array $payload): array
    {
        $service = $this->service();

        if ($service === null) {
            return ['status' => 'PENDING', 'reference' => null, 'reason' => 'Workflow Engine tidak tersedia; permintaan menunggu keputusan manual.'];
        }

        try {
            if (method_exists($service, 'startFromCode')) {
                $instance = $service->startFromCode(
                    (string) ($payload['type'] ?? 'ASSET'),
                    $payload,
                    $payload['requested_by'] ?? null,
                );

                return [
                    'status' => 'RUNNING',
                    'reference' => 'WF-'.(is_object($instance) ? $instance->id : $instance),
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('Asset: workflow gagal dimulai, permintaan menunggu manual.', [
                'type' => $payload['type'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }

        return ['status' => 'PENDING', 'reference' => null];
    }

    public function decide(array $payload): array
    {
        $service = $this->service();

        if ($service === null) {
            return ['status' => 'PENDING'];
        }

        try {
            if (method_exists($service, 'approve') && isset($payload['reference'])) {
                $service->approve($payload['reference'], $payload['actor_id'] ?? null);

                return ['status' => 'APPROVED'];
            }
        } catch (\Throwable $exception) {
            Log::warning('Asset: keputusan workflow gagal diteruskan.', ['error' => $exception->getMessage()]);
        }

        return ['status' => 'PENDING'];
    }

    public function status(string $subjectType, int $subjectId): ?array
    {
        if (! Schema::hasTable('workflow_instances')) {
            return null;
        }

        $instance = \Illuminate\Support\Facades\DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('id')
            ->first();

        return $instance === null ? null : [
            'instance_id' => $instance->id,
            'status' => $instance->status ?? null,
        ];
    }

    public function isApproved(string $subjectType, int $subjectId): bool
    {
        $status = $this->status($subjectType, $subjectId);

        return $status !== null && in_array(strtoupper((string) $status['status']), ['APPROVED', 'COMPLETED', 'FINISHED'], true);
    }

    protected function service(): ?object
    {
        $class = \App\Modules\Workflow\Application\Services\WorkflowActionService::class;

        return class_exists($class) ? app($class) : null;
    }
}
