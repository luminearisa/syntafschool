<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Models\WorkflowAuditLog;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jejak audit workflow yang **tidak dapat disunting** (§48).
 *
 * Setiap baris memuat `previous_hash` + `hash` dirinya, membentuk rantai per
 * instance (dan rantai global untuk baris tanpa instance). Verifikasi ulang
 * rantai (`verifyChain`) membuat penghapusan atau penyuntingan satu baris di
 * tengah sejarah selalu terdeteksi — termasuk bila dilakukan langsung di basis
 * data, bukan lewat aplikasi.
 *
 * Kelas ini sengaja **tidak menyediakan** update/delete. Koreksi dilakukan
 * dengan mencatat peristiwa baru yang merujuk peristiwa lama.
 */
class WorkflowAuditService
{
    /**
     * Catat satu peristiwa audit.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        AuditAction $action,
        ?WorkflowInstance $instance = null,
        ?Model $target = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?User $actor = null,
        array $extra = [],
    ): WorkflowAuditLog {
        $actor ??= $this->currentUser();

        $log = new WorkflowAuditLog();
        $log->forceFill([
            'instance_id' => $instance?->id ?? ($extra['instance_id'] ?? null),
            'definition_id' => $extra['definition_id'] ?? $instance?->definition_id,
            'version_id' => $extra['version_id'] ?? $instance?->version_id,
            'step_instance_id' => $extra['step_instance_id'] ?? null,
            'actor_id' => $actor?->id,
            'action' => $action->value,
            'target_type' => $target !== null ? $target::class : ($extra['target_type'] ?? null),
            'target_id' => $target !== null ? (string) $target->getKey() : ($extra['target_id'] ?? null),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip_address' => $extra['ip_address'] ?? request()?->ip(),
            'user_agent' => mb_substr((string) ($extra['user_agent'] ?? request()?->userAgent()), 0, 250),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $log->previous_hash = $this->lastHash($instance?->id);
        $log->hash = $this->hashFor($log->payloadForHash(), $log->previous_hash);
        $log->save();

        Log::channel(config('logging.default'))->info('workflow.audit', [
            'action' => $action->value,
            'instance' => $instance?->code,
            'actor' => $actor?->id,
            'reason' => $reason,
        ]);

        return $log;
    }

    /**
     * Verifikasi rantai audit (§48).
     *
     * Rantai dijaga **per instance** (dan satu rantai global untuk baris tanpa
     * instance). Verifikasi menyusuri seluruh baris berurutan `id`, menjaga hash
     * terakhir tiap rantai, sehingga penyuntingan atau penghapusan satu baris di
     * tengah sejarah selalu terbaca sebagai pemutusan.
     *
     * @return array{valid: bool, checked: int, broken_at: int|null, expected: string|null, actual: string|null}
     */
    public function verifyChain(?int $instanceId = null, int $limit = 5000): array
    {
        $query = WorkflowAuditLog::query()->orderBy('id');

        if ($instanceId !== null) {
            $query->where('instance_id', $instanceId);
        }

        $checked = 0;
        $broken = null;
        /** @var array<string, string|null> $lastHashByChain */
        $lastHashByChain = [];

        $query->chunk(500, function ($logs) use (&$checked, &$broken, &$lastHashByChain, $limit) {
            foreach ($logs as $log) {
                $chain = $log->instance_id === null ? 'global' : 'instance:'.$log->instance_id;

                $expected = $this->hashFor($log->payloadForHash(), $log->previous_hash);
                $checked++;

                if ($expected !== $log->hash
                    || ($lastHashByChain[$chain] ?? null) !== $log->previous_hash) {
                    $broken = [
                        'valid' => false,
                        'checked' => $checked,
                        'broken_at' => $log->id,
                        'expected' => $expected,
                        'actual' => $log->hash,
                    ];

                    return false;
                }

                $lastHashByChain[$chain] = $log->hash;

                if ($checked >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $broken ?? [
            'valid' => true,
            'checked' => $checked,
            'broken_at' => null,
            'expected' => null,
            'actual' => null,
        ];
    }

    /** Hash terakhir pada rantai sebuah instance (atau rantai global). */
    protected function lastHash(?int $instanceId): ?string
    {
        $query = WorkflowAuditLog::query()->orderByDesc('id');

        if ($instanceId === null) {
            $query->whereNull('instance_id');
        } else {
            $query->where('instance_id', $instanceId);
        }

        return $query->value('hash');
    }

    /** @param array<string, mixed> $payload */
    protected function hashFor(array $payload, ?string $previousHash): string
    {
        $canonical = $this->canonicalize($payload);

        try {
            $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $encoded = serialize($canonical);
        }

        return hash('sha256', ($previousHash ?? 'genesis').'|'.$encoded);
    }

    protected function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }

    protected function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** Rantai audit satu instance, siap ditampilkan ke auditor. */
    public function chainFor(WorkflowInstance $instance, int $limit = 200): array
    {
        return WorkflowAuditLog::query()
            ->where('instance_id', $instance->id)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** Ringkasan jumlah baris audit per aksi (untuk dasbor auditor). */
    public function summary(?int $days = 30): array
    {
        return WorkflowAuditLog::query()
            ->select('action', DB::raw('COUNT(*) as total'))
            ->when($days !== null, fn ($q) => $q->where('occurred_at', '>=', now()->subDays($days)))
            ->groupBy('action')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row) => ['action' => (string) $row->action, 'total' => (int) $row->total])
            ->all();
    }
}
