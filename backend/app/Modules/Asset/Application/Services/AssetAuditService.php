<?php

namespace App\Modules\Asset\Application\Services;

use App\Modules\Asset\Domain\Contracts\AuditProviderInterface;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Models\AssetAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Jejak audit modul Asset & Sarpras.
 *
 * Setiap perubahan siklus hidup dicatat dengan pertanyaan pemeriksa sebagai
 * kerangka: siapa, apa, kapan, di mana, mengapa, dari mana, ke mana, status
 * sebelum, dan status sesudah. Tanpa status sebelum/sesudah, pertanyaan "siapa
 * yang mengubah ini" tetap tidak terjawab.
 */
class AssetAuditService
{
    public function __construct(private readonly AuditProviderInterface $external) {}

    /**
     * @param array{before?:array|null, after?:array|null, reason?:string|null, context?:array|null,
     *              status_before?:string|null, status_after?:string|null, from?:string|null, to?:string|null} $meta
     */
    public function record(AuditAction $action, Model $subject, ?Model $actor = null, array $meta = []): AssetAuditLog
    {
        $actor ??= Auth::user();

        $assetId = $subject instanceof \App\Modules\Asset\Models\Asset ? $subject->getKey() : ($subject->asset_id ?? $meta['asset_id'] ?? null);
        $facilityId = $subject instanceof \App\Modules\Asset\Models\Facility ? $subject->getKey() : ($subject->facility_id ?? $meta['facility_id'] ?? null);

        $log = AssetAuditLog::query()->create([
            'action' => $action->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'asset_id' => $assetId,
            'facility_id' => $facilityId,
            'actor_id' => $actor?->getKey(),
            'actor_role' => $this->actorRole($actor),
            'status_before' => $meta['status_before'] ?? null,
            'status_after' => $meta['status_after'] ?? null,
            'from_location' => $meta['from'] ?? null,
            'to_location' => $meta['to'] ?? null,
            'reason' => $meta['reason'] ?? null,
            'before' => $meta['before'] ?? null,
            'after' => $meta['after'] ?? null,
            'context' => $meta['context'] ?? $this->requestContext(),
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 200),
            'occurred_at' => now(),
        ]);

        // Modul lain boleh menitipkan catatan agar satu riwayat tidak terbelah.
        $this->external->record(
            $action->value,
            $subject->getMorphClass(),
            (int) $subject->getKey(),
            $meta + ['actor_id' => $actor?->getKey()],
        );

        return $log;
    }

    /** Catat percobaan akses (diberikan atau ditolak) — termasuk yang ditolak. */
    public function logAccess(AuditAction $action, Model $subject, bool $granted, ?string $reason = null, ?Model $actor = null): AssetAuditLog
    {
        return $this->record($action, $subject, $actor, [
            'reason' => $reason,
            'context' => $this->requestContext() + ['granted' => $granted],
        ]);
    }

    /** Riwayat audit sebuah subjek (terbaru lebih dulu). */
    public function history(Model $subject, int $limit = 100)
    {
        return AssetAuditLog::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    protected function actorRole(?Model $actor): ?string
    {
        if ($actor === null || ! method_exists($actor, 'getRoleNames')) {
            return null;
        }

        return $actor->getRoleNames()->first();
    }

    /** @return array<string, mixed> */
    protected function requestContext(): array
    {
        if (app()->runningInConsole()) {
            return ['source' => 'console'];
        }

        return [
            'source' => 'http',
            'method' => request()->method(),
            'path' => request()->path(),
        ];
    }
}
