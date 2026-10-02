<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Contracts\AuditProviderInterface;
use App\Modules\Hrd\Models\HrAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Jejak audit HRD (§51).
 *
 * Setiap perubahan penting dicatat lengkap: siapa, apa, kapan, sebelum, sesudah,
 * dari alamat mana, dan atas alasan apa. Tiga hal yang membuatnya berguna, bukan
 * sekadar ada:
 *
 *  1. **Hanya kolom yang berubah** yang disimpan (`changed_fields`), sehingga
 *     barisnya terbaca manusia dan tidak membengkakkan basis data.
 *  2. **`transaction_id`/`batch_id`** mengelompokkan perubahan yang berasal dari
 *     satu operasi — satu transfer menghasilkan satu baris audit per entitas, dan
 *     semuanya dapat ditarik dengan satu kueri.
 *  3. **Tidak dapat diubah.** Modelnya menolak pembaruan dan penghapusan; tidak
 *     ada endpoint yang menyuntingnya. Inilah yang dimaksud §51 dengan "audit
 *     tidak boleh dapat diedit oleh user biasa" — dan bahkan oleh admin HR.
 *
 * Peristiwa yang juga perlu diketahui modul lain dikirimkan ke audit terpusat
 * lewat `AuditProviderInterface`, bila modul tersebut terpasang.
 */
class HrdAuditService
{
    private ?string $batchId = null;

    public function __construct(private readonly AuditProviderInterface $external) {}

    /**
     * Catat perubahan sebuah entitas.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function log(
        ?User $actor,
        string $action,
        Model $entity,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?int $transactionId = null,
        ?int $employeeId = null,
    ): HrAuditLog {
        $changed = $this->changedFields($before, $after);

        $log = HrAuditLog::query()->create([
            'action' => $action,
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->getKey(),
            'employee_id' => $employeeId ?? $this->guessEmployeeId($entity),
            'actor_id' => $actor?->getKey(),
            'actor_role' => $this->primaryRole($actor),
            'before' => $before === null ? null : $this->onlyChanged($before, $changed),
            'after' => $after === null ? null : $this->onlyChanged($after, $changed),
            'changed_fields' => $changed === [] ? null : $changed,
            'reason' => $reason,
            'ip_address' => $this->ipAddress(),
            'user_agent' => substr((string) Request::userAgent(), 0, 250) ?: null,
            'transaction_id' => $transactionId,
            'batch_id' => $this->batchId,
            'occurred_at' => now(),
        ]);

        if ($this->external->isAvailable()) {
            $this->external->record($action, $entity->getMorphClass(), $entity->getKey(), $before ?? [], $after ?? [], [
                'reason' => $reason,
                'transaction_id' => $transactionId,
                'batch_id' => $this->batchId,
                'actor_id' => $actor?->getKey(),
            ]);
        }

        return $log;
    }

    /** Catat peristiwa yang tidak terikat satu baris data (mis. impor dijalankan). */
    public function logEvent(?User $actor, string $action, string $entityType, int|string|null $entityId = null, array $context = [], ?string $reason = null): HrAuditLog
    {
        return HrAuditLog::query()->create([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_id' => $actor?->getKey(),
            'actor_role' => $this->primaryRole($actor),
            'after' => $context === [] ? null : $context,
            'reason' => $reason,
            'ip_address' => $this->ipAddress(),
            'user_agent' => substr((string) Request::userAgent(), 0, 250) ?: null,
            'batch_id' => $this->batchId,
            'occurred_at' => now(),
        ]);
    }

    /** Kelompokkan seluruh perubahan berikutnya ke satu operasi. */
    public function withinBatch(string $batchId, callable $callback): mixed
    {
        $previous = $this->batchId;
        $this->batchId = $batchId;

        try {
            return $callback();
        } finally {
            $this->batchId = $previous;
        }
    }

    public function currentBatch(): ?string
    {
        return $this->batchId;
    }

    /** @return list<string> */
    public function changedFields(?array $before, ?array $after): array
    {
        if ($before === null && $after === null) {
            return [];
        }

        if ($before === null) {
            return array_keys($after ?? []);
        }

        if ($after === null) {
            return array_keys($before);
        }

        $fields = array_unique(array_merge(array_keys($before), array_keys($after)));

        return array_values(array_filter($fields, function (string $field) use ($before, $after): bool {
            $left = $before[$field] ?? null;
            $right = $after[$field] ?? null;

            return $this->normalize($left) !== $this->normalize($right);
        }));
    }

    /** @return array<string, mixed> */
    private function onlyChanged(array $values, array $changed): array
    {
        if ($changed === []) {
            return $values;
        }

        return array_intersect_key($values, array_flip($changed));
    }

    private function normalize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            ksort($value);

            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) ($value ?? '');
    }

    private function guessEmployeeId(Model $entity): ?int
    {
        $value = $entity->getAttribute('employee_id');

        return $value === null ? null : (int) $value;
    }

    private function primaryRole(?User $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $actor->getMorphClass())
            ->where('model_has_roles.model_id', $actor->getKey())
            ->orderBy('roles.id')
            ->value('roles.name');
    }

    private function ipAddress(): ?string
    {
        $ip = Request::ip();

        return is_string($ip) ? substr($ip, 0, 45) : null;
    }
}
