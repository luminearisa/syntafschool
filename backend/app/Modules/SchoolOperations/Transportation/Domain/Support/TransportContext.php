<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Support;

use App\Modules\SchoolOperations\Transportation\Models\{TransportationAuditLog, TransportationEvent, TransportationIdempotencyKey};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fondasi bersama modul transportasi: jejak audit, event bus, idempotensi,
 * dan pembatasan cakupan (campus/route/vehicle/driver).
 *
 * Idempotensi memakai tabel kunci unik + transaksi, sehingga dua permintaan
 * bersamaan (mis. dua scan kartu yang sama) hanya menghasilkan satu efek.
 */
class TransportContext
{
    /** Audit satu peristiwa (tanpa menyentuh tabel modul lain). */
    public function audit(string $event, mixed $auditable = null, array $context = []): void
    {
        try {
            TransportationAuditLog::query()->create([
                'event' => $event,
                'auditable_type' => is_object($auditable) ? $auditable::class : null,
                'auditable_id' => is_object($auditable) ? ($auditable->getKey() ?? null) : null,
                'user_id' => $this->actorId(),
                'actor_type' => $this->actorType(),
                'campus_id' => isset($context['campus_id']) ? (string) $context['campus_id'] : null,
                'context' => $this->sanitize($context),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit tidak boleh menggagalkan operasi lapangan; kegagalan dicatat di log aplikasi.
            \Log::warning('transportation.audit_failed', ['event' => $event]);
        }
    }

    /** Publikasikan event domain (event-driven architecture, §85). */
    public function publish(string $eventName, mixed $aggregate = null, array $payload = []): TransportationEvent
    {
        $event = TransportationEvent::query()->create([
            'event_name' => $eventName,
            'aggregate_type' => is_object($aggregate) ? $aggregate::class : null,
            'aggregate_id' => is_object($aggregate) ? ($aggregate->getKey() ?? null) : null,
            'payload' => $payload,
            'status' => 'PUBLISHED',
            'actor_id' => $this->actorId(),
            'occurred_at' => now(),
        ]);

        $this->audit($eventName, $aggregate, ['payload_keys' => array_keys($payload)]);

        return $event;
    }

    /**
     * Jalankan operasi tepat sekali untuk sebuah kunci idempotensi.
     * Bila kunci sudah diproses, hasil tersimpan dikembalikan tanpa efek baru.
     */
    public function idempotent(string $scope, ?string $key, callable $callback, array $meta = []): array
    {
        if ($key === null || $key === '') {
            return ['status' => 'EXECUTED', 'duplicate' => false, 'result' => $callback()];
        }

        // Kunci diberi awalan cakupan: kunci operasi dan kunci notifikasi yang
        // kebetulan sama tidak boleh saling menelan (§88).
        $scopedKey = strtolower($scope).':'.$key;

        $existing = TransportationIdempotencyKey::query()->where('idempotency_key', $scopedKey)->first();
        if ($existing) {
            return ['status' => 'DUPLICATE', 'duplicate' => true, 'result' => $existing->response ?? []];
        }

        try {
            $result = DB::transaction(function () use ($scope, $key, $scopedKey, $callback, $meta): array {
                TransportationIdempotencyKey::query()->create([
                    'idempotency_key' => $scopedKey,
                    'scope' => $scope,
                    'source' => $meta['source'] ?? null,
                    'external_reference' => $meta['external_reference'] ?? null,
                    'device_id' => $meta['device_id'] ?? null,
                    'processed_at' => now(),
                ]);

                return $callback();
            });
        } catch (QueryException $exception) {
            // Balapan dua permintaan: yang kalah membaca hasil pemenang.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = TransportationIdempotencyKey::query()->where('idempotency_key', $scopedKey)->first();

            return ['status' => 'CONCURRENT', 'duplicate' => true, 'result' => $winner?->response ?? []];
        }

        $stored = $this->persistResponse($scopedKey, $result);

        return ['status' => 'EXECUTED', 'duplicate' => false, 'result' => $stored];
    }

    public function withinTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function actorId(): ?int
    {
        $id = Auth::id();

        return $id !== null ? (int) $id : null;
    }

    public function actorType(): string
    {
        return Auth::check() ? 'USER' : 'SYSTEM';
    }

    /** Apakah pengguna memiliki peran tertentu (Spatie) atau termasuk konfigurasi override. */
    public function hasAnyRole(mixed $user, array $roles): bool
    {
        if (! $user) {
            return false;
        }

        foreach ($roles as $role) {
            if (method_exists($user, 'hasRole') && $user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function can(mixed $user, string $permission): bool
    {
        return (bool) ($user?->can($permission) ?? false);
    }

    /** Nomor dokumen berurutan yang aman untuk operasi paralel. */
    public function nextNumber(string $table, string $column, string $prefix, string $period = null): string
    {
        $period ??= now()->format('ymd');
        $count = (int) DB::table($table)->where($column, 'like', $prefix.'-'.$period.'%')->count();

        return sprintf('%s-%s-%04d', $prefix, $period, $count + 1);
    }

    private function persistResponse(?string $key, mixed $result): mixed
    {
        if ($key === null) {
            return $result;
        }

        $record = TransportationIdempotencyKey::query()->where('idempotency_key', $key)->first();
        if (! $record) {
            return $result;
        }

        $record->forceFill([
            'result_type' => is_object($result) ? $result::class : gettype($result),
            'result_id' => is_object($result) && method_exists($result, 'getKey') ? $result->getKey() : null,
            'response' => is_array($result) ? $result : ['value' => $result],
        ])->save();

        return $result;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE') || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'unique constraint');
    }

    /** Bersihkan konteks audit dari data pribadi berlebih (§91). */
    private function sanitize(array $context): array
    {
        foreach (['phone', 'identity_number', 'medical', 'password', 'token'] as $sensitive) {
            unset($context[$sensitive]);
        }

        return $context;
    }
}
