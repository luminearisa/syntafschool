<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Audit trail LMS (§23).
 *
 * Menyimpan **aktor, aksi, entitas, before, after, konteks, ip, user agent, dan
 * correlation id** dalam satu tabel immutable. Nilai sebelum/sesudah dihitung
 * dari `getOriginal()`/`getAttributes()` sehingga tidak ada pemanggil yang
 * "lupa" mencatat perubahan — termasuk perubahan dari job tanpa HTTP.
 *
 * Correlation id berasal dari header `X-Correlation-Id` (bila klien/ERP
 * mengirimnya) atau dibuat sekali per permintaan, sehingga satu aksi pengguna
 * yang menyentuh beberapa service tetap dapat ditelusuri sebagai satu rangkaian.
 */
class LmsAuditService
{
    private ?string $correlationId = null;

    /**
     * Catat satu perubahan.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?LmsActor $actor = null,
        ?int $courseId = null,
        array $context = [],
    ): LmsAuditLog {
        $actor ??= $this->actorFromRequest();

        return LmsAuditLog::query()->create([
            'log_name' => 'lms',
            'action' => $action,
            'entity_type' => $this->normalizeType($entityType),
            'entity_id' => $entityId,
            'course_id' => $courseId,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->primaryRole?->value,
            'before' => $this->redact($before),
            'after' => $this->redact($after),
            'context' => $context === [] ? null : $context,
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'correlation_id' => $this->correlationId(),
            'created_at' => now(),
        ]);
    }

    /**
     * Catat perubahan sebuah model (before/after otomatis).
     *
     * @param  array<int, string>  $only  batasi atribut yang dicatat
     */
    public function recordModel(
        string $action,
        Model $model,
        ?LmsActor $actor = null,
        ?int $courseId = null,
        array $only = [],
        array $context = [],
    ): LmsAuditLog {
        $before = $this->attributesOf($model, dirtyOnly: false, only: $only);
        $dirty = $this->attributesOf($model, dirtyOnly: true, only: $only);
        $original = $model->getOriginal();

        $changedBefore = [];
        foreach (array_keys($dirty) as $key) {
            $changedBefore[$key] = $original[$key] ?? null;
        }

        return $this->record(
            action: $action,
            entityType: $model::class,
            entityId: (int) $model->getKey(),
            before: $changedBefore === [] ? null : $changedBefore,
            after: $dirty === [] ? $before : $dirty,
            actor: $actor,
            courseId: $courseId,
            context: $context,
        );
    }

    /**
     * Riwayat audit sebuah entitas.
     *
     * @return list<array<string, mixed>>
     */
    public function forEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        return LmsAuditLog::query()
            ->forEntity($this->normalizeType($entityType), $entityId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (LmsAuditLog $log) => $log->toPayload())
            ->all();
    }

    /**
     * Penelusuran audit dengan penyaringan wajib yang terukur.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function search(array $filters = [], int $perPage = 25): array
    {
        $query = $this->query($filters);

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('id')
            ->limit(max(1, min(200, $perPage)))
            ->get();

        return [
            'data' => $rows->map(fn (LmsAuditLog $log) => $log->toPayload())->all(),
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'actions' => $this->actionOptions(),
            ],
        ];
    }

    /**
     * Ringkasan audit untuk dasbor kepatuhan.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        $query = LmsAuditLog::query()
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to));

        $byAction = (clone $query)
            ->select('action', DB::raw('count(*) as total'))
            ->groupBy('action')
            ->orderByDesc('total')
            ->limit(20)
            ->pluck('total', 'action')
            ->all();

        $byActor = (clone $query)
            ->select('actor_name', DB::raw('count(*) as total'))
            ->whereNotNull('actor_name')
            ->groupBy('actor_name')
            ->orderByDesc('total')
            ->limit(10)
            ->pluck('total', 'actor_name')
            ->all();

        return [
            'total' => (clone $query)->count(),
            'last_24h' => LmsAuditLog::query()->where('created_at', '>=', now()->subDay())->count(),
            'by_action' => $byAction,
            'by_actor' => $byActor,
            'users' => [
                'sistem' => LmsAuditLog::query()->whereNull('actor_id')->count(),
                'pengguna' => LmsAuditLog::query()->whereNotNull('actor_id')->count(),
            ],
            'period' => ['from' => $from, 'to' => $to],
        ];
    }

    /** Correlation id permintaan saat ini (dibuat sekali bila belum ada). */
    public function correlationId(): string
    {
        if ($this->correlationId !== null) {
            return $this->correlationId;
        }

        $request = $this->request();

        $incoming = $request?->header('X-Correlation-Id');

        return $this->correlationId = (is_string($incoming) && $incoming !== '' && strlen($incoming) <= 64)
            ? $incoming
            : (string) Str::ulid();
    }

    /** @return list<string> */
    public function actionOptions(): array
    {
        return LmsAuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }

    /** @param array<string, mixed> $filters */
    private function query(array $filters): Builder
    {
        return LmsAuditLog::query()
            ->when(filled($filters['action'] ?? null), fn (Builder $q) => $q->where('action', $filters['action']))
            ->when(filled($filters['entity_type'] ?? null), fn (Builder $q) => $q->where('entity_type', $this->normalizeType((string) $filters['entity_type'])))
            ->when(filled($filters['entity_id'] ?? null), fn (Builder $q) => $q->where('entity_id', (int) $filters['entity_id']))
            ->when(filled($filters['course_id'] ?? null), fn (Builder $q) => $q->where('course_id', (int) $filters['course_id']))
            ->when(filled($filters['actor_id'] ?? null), fn (Builder $q) => $q->where('actor_id', (int) $filters['actor_id']))
            ->when(filled($filters['correlation_id'] ?? null), fn (Builder $q) => $q->where('correlation_id', $filters['correlation_id']))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('action', 'like', $term)
                    ->orWhere('actor_name', 'like', $term)
                    ->orWhere('entity_type', 'like', $term));
            })
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->where('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->where('created_at', '<=', $filters['to']));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Model $model, bool $dirtyOnly, array $only): array
    {
        $attributes = $dirtyOnly ? $model->getDirty() : $model->getAttributes();

        if ($only !== []) {
            $attributes = array_intersect_key($attributes, array_flip($only));
        }

        // Rahasia tidak pernah masuk audit trail.
        foreach (['password', 'remember_token', 'quiz_password', 'api_token', 'secret'] as $hidden) {
            unset($attributes[$hidden]);
        }

        return $attributes;
    }

    /** @param array<string, mixed>|null $values @return array<string, mixed>|null */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (['password', 'remember_token', 'quiz_password', 'api_token', 'secret', 'token'] as $hidden) {
            if (array_key_exists($hidden, $values)) {
                $values[$hidden] = '***';
            }
        }

        return $values;
    }

    private function normalizeType(string $entityType): string
    {
        return Str::of($entityType)->replace('\\', '\\')->limit(120, '')->value();
    }

    private function actorFromRequest(): ?LmsActor
    {
        $request = $this->request();

        if ($request === null) {
            return null;
        }

        $resolved = \App\Modules\LMS\Http\Middleware\ResolveLmsActor::actor($request);

        if ($resolved !== null) {
            return $resolved;
        }

        $userId = $request->user()?->getAuthIdentifier() ?? $request->user()?->id;

        if ($userId === null) {
            return null;
        }

        return app(\App\Modules\LMS\Domain\Contracts\UserProviderInterface::class)->find((int) $userId);
    }

    private function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        try {
            return app('request');
        } catch (\Throwable) {
            return null;
        }
    }

    private function ip(): ?string
    {
        $ip = $this->request()?->ip();

        return is_string($ip) && $ip !== '' ? Str::limit($ip, 45, '') : null;
    }

    private function userAgent(): ?string
    {
        $agent = $this->request()?->userAgent();

        return is_string($agent) && $agent !== '' ? Str::limit($agent, 250, '') : null;
    }
}
