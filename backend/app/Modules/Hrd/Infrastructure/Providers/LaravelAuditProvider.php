<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Domain\Contracts\AuditProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit lintas modul bawaan (§51, §66).
 *
 * HRD selalu menulis `hr_audit_logs` lebih dulu (jejaknya lengkap dan tidak dapat
 * diubah). Kontrak ini hanya menyampaikan peristiwanya ke audit terpusat bila
 * modul tersebut tersedia — mis. `activity_log` milik Spatie. Kegagalan di sini
 * tidak pernah menggagalkan perubahan data.
 */
class LaravelAuditProvider implements AuditProviderInterface
{
    public function record(string $action, string $entityType, int|string|null $entityId, array $before = [], array $after = [], array $context = []): void
    {
        try {
            if (Schema::hasTable('activity_log') && class_exists(\Spatie\Activitylog\Models\Activity::class)) {
                activity()
                    ->withProperties(['before' => $before, 'after' => $after, 'context' => $context, 'module' => 'hrd'])
                    ->event($action)
                    ->log($entityType.'#'.($entityId ?? '-'));

                return;
            }
        } catch (\Throwable $exception) {
            Log::debug('HRD: audit lintas modul gagal', ['error' => $exception->getMessage()]);
        }
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('activity_log');
    }
}
