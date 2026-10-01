<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\AuditProviderInterface;
use App\Modules\Asset\Models\AssetAuditLog;

/**
 * Penyedia audit bawaan.
 *
 * Jejak audit modul ini SUDAH ditulis ke `asset_audit_logs` oleh
 * `AssetAuditService` (immutable). Kontrak ini hanya jembatan bila sekolah ingin
 * mengalirkan catatan yang sama ke pusat audit lintas modul; bawaannya tidak
 * menggandakan apa pun.
 */
class LocalAuditProvider implements AuditProviderInterface
{
    public function record(string $action, string $subjectType, int $subjectId, array $context = []): void
    {
        // Sengaja kosong: `asset_audit_logs` adalah sumber kebenarannya. Menggandakan
        // baris audit ke tempat kedua justru membuat dua riwayat yang bisa berbeda.
    }

    public function history(string $subjectType, int $subjectId, int $limit = 100): array
    {
        return AssetAuditLog::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (AssetAuditLog $log): array => [
                'action' => $log->action?->value ?? (string) $log->action,
                'occurred_at' => $log->occurred_at?->toDateTimeString(),
                'actor_id' => $log->actor_id,
                'status_before' => $log->status_before,
                'status_after' => $log->status_after,
                'reason' => $log->reason,
            ])
            ->all();
    }
}
