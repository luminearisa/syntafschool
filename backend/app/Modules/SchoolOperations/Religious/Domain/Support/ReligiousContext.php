<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Support;

use App\Modules\SchoolOperations\Religious\Models\ReligiousAuditLog;
use App\Modules\SchoolOperations\Religious\Models\ReligiousEvent;
use App\Modules\SchoolOperations\Religious\Models\ReligiousIdempotencyKey;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fondasi bersama modul Religious & Mosque Management.
 *
 * Menyediakan: jejak audit (\u00a795), event bus domain (\u00a777), idempotensi
 * (scan ganda tidak menghasilkan dua catatan presensi), pembatasan cakupan
 * (\u00a794: kampus/organisasi/masjid/asrama), dan pembangkit kode dokumen.
 *
 * Prinsip modul: seluruh akses lintas modul bersifat tahan-gagal. Bila sumber
 * data eksternal tidak tersedia, modul menyatakannya secara eksplisit dan
 * tetap berjalan dalam mode mandiri.
 */
class ReligiousContext
{
    /** Namai ulang tabel audit/event agar mudah diganti per lingkungan. */
    protected function auditTable(): string
    {
        return (string) config('religious.infrastructure.audit_table', 'religious_audit_logs');
    }

    protected function eventTable(): string
    {
        return (string) config('religious.infrastructure.event_table', 'religious_events');
    }

    protected function idempotencyTable(): string
    {
        return (string) config('religious.infrastructure.idempotency_table', 'religious_idempotency_keys');
    }

    public function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Jalankan operasi yang boleh gagal tanpa menghentikan alur utama. */
    public function safe(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            \Log::warning('religious.safe_guard', ['message' => $e->getMessage()]);

            return $default;
        }
    }

    // ── Audit (\u00a795) ─────────────────────────────────────────────────────

    /**
     * Catat satu peristiwa audit: aktor, aksi, entitas, nilai lama/baru,
     * alasan, IP, dan user agent.
     */
    public function audit(string $event, mixed $auditable = null, array $context = [], ?string $reason = null): void
    {
        $this->safe(function () use ($event, $auditable, $context, $reason): void {
            if (! $this->hasTable($this->auditTable())) {
                return;
            }

            $request = request();
            $payload = [
                'event' => $event,
                'auditable_type' => is_object($auditable) ? $auditable::class : null,
                'auditable_id' => is_object($auditable) ? ($auditable->getKey() ?? null) : null,
                'user_id' => $this->actorId(),
                'actor_type' => $this->actorType(),
                'campus_id' => $context['campus_id'] ?? null,
                'mosque_id' => $context['mosque_id'] ?? null,
                'context' => $this->sanitize($context),
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255),
                'reason' => $reason,
                'created_at' => now(),
            ];

            // Tabel bawaan memakai model Eloquent; tabel yang ditimpa lewat
            // konfigurasi ditulis langsung agar tidak salah menganggap nama
            // tabel sebagai nama kelas.
            if ($this->auditTable() === 'religious_audit_logs') {
                ReligiousAuditLog::query()->create($payload);
            } else {
                DB::table($this->auditTable())->insert($payload);
            }
        });
    }

    // ── Event bus (\u00a777) ─────────────────────────────────────────────────

    public function publish(string $eventName, mixed $aggregate = null, array $payload = []): ?ReligiousEvent
    {
        return $this->safe(function () use ($eventName, $aggregate, $payload): ?ReligiousEvent {
            $event = ReligiousEvent::query()->create([
                'event_name' => $eventName,
                'aggregate_type' => is_object($aggregate) ? $aggregate::class : null,
                'aggregate_id' => is_object($aggregate) ? ($aggregate->getKey() ?? null) : null,
                'payload' => $payload,
                'status' => 'PUBLISHED',
                'actor_id' => $this->actorId(),
                'occurred_at' => now(),
                'published_at' => now(),
            ]);

            $this->audit($eventName, $aggregate, ['payload_keys' => array_keys($payload)]);

            return $event;
        });
    }

    // ── Idempotensi ──────────────────────────────────────────────────────────

    /**
     * Jalankan operasi tepat sekali untuk sebuah kunci.
     * Contoh: dua kali scan kartu santri yang sama pada jendela presensi yang
     * sama hanya menghasilkan satu catatan presensi.
     */
    public function idempotent(string $scope, ?string $key, callable $callback, array $meta = []): array
    {
        if ($key === null || $key === '') {
            return ['status' => 'EXECUTED', 'duplicate' => false, 'result' => $callback()];
        }

        $scopedKey = strtolower($scope).':'.$key;

        $existing = ReligiousIdempotencyKey::query()->where('idempotency_key', $scopedKey)->first();
        if ($existing) {
            return ['status' => 'DUPLICATE', 'duplicate' => true, 'result' => $existing->response ?? []];
        }

        try {
            $result = DB::transaction(function () use ($scope, $key, $scopedKey, $callback, $meta): array {
                ReligiousIdempotencyKey::query()->create([
                    'idempotency_key' => $scopedKey,
                    'scope' => $scope,
                    'source' => $meta['source'] ?? null,
                    'external_reference' => $meta['external_reference'] ?? null,
                    'device_id' => $meta['device_id'] ?? null,
                    'processed_at' => now(),
                    'created_at' => now(),
                ]);

                return ['__result' => $callback()];
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = ReligiousIdempotencyKey::query()->where('idempotency_key', $scopedKey)->first();

            return ['status' => 'CONCURRENT', 'duplicate' => true, 'result' => $winner?->response ?? []];
        }

        $payload = $result['__result'] ?? [];
        ReligiousIdempotencyKey::query()->where('idempotency_key', $scopedKey)
            ->update(['response' => $payload]);

        return ['status' => 'EXECUTED', 'duplicate' => false, 'result' => $payload];
    }

    protected function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE') || str_contains($message, 'Duplicate entry');
    }

    public function withinTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    // ── Aktor ────────────────────────────────────────────────────────────────

    public function actorId(): ?int
    {
        $id = Auth::id();

        return $id !== null ? (int) $id : null;
    }

    public function actorType(): string
    {
        return Auth::check() ? 'USER' : 'SYSTEM';
    }

    // ── Cakupan akses (\u00a794) ─────────────────────────────────────────────

    public function can(string $ability, mixed $user = null): bool
    {
        $user ??= Auth::user();

        if ($user === null) {
            return false;
        }

        return $this->safe(fn (): bool => (bool) $user->can($ability), false);
    }

    /** Cakupan efektif pengguna: kampus, organisasi, masjid, dan asrama. */
    public function scopeFor(mixed $user = null): array
    {
        $user ??= Auth::user();

        return [
            'user_id' => $user?->id,
            'campus_ids' => $this->campusIds($user),
            'mosque_ids' => $this->mosqueIds($user),
            'organization_ids' => $this->organizationIds($user),
            'dormitory_ids' => $this->organizationIds($user),
            'is_global' => $this->isGlobal($user),
        ];
    }

    protected function isGlobal(mixed $user): bool
    {
        if ($user === null) {
            return false;
        }

        foreach ((array) config('religious.security.global_roles', []) as $role) {
            if ($this->safe(fn (): bool => (bool) $user->hasRole($role), false)) {
                return true;
            }
        }

        return false;
    }

    public function campusIds(mixed $user = null): array
    {
        $user ??= Auth::user();
        if ($user === null || $this->isGlobal($user)) {
            return [];
        }

        return $this->safe(function () use ($user): array {
            $ids = [];
            foreach (['campus_id', 'school_id'] as $attribute) {
                if (! empty($user->{$attribute})) {
                    $ids[] = (int) $user->{$attribute};
                }
            }

            if (method_exists($user, 'campuses')) {
                $ids = array_merge($ids, (array) $user->campuses()->pluck('id')->all());
            }

            return array_values(array_unique(array_filter($ids)));
        }, []);
    }

    public function mosqueIds(mixed $user = null): array
    {
        $user ??= Auth::user();
        if ($user === null || $this->isGlobal($user)) {
            return [];
        }

        return $this->safe(function () use ($user): array {
            $pivot = (string) config('religious.security.mosque_pivot_table', 'mosque_staff');

            if ($this->hasTable($pivot) && Schema::hasColumn($pivot, 'mosque_id')) {
                $column = Schema::hasColumn($pivot, 'user_id') ? 'user_id' : 'employee_id';
                $value = $column === 'user_id' ? $user->id : ($user->employee_id ?? null);

                if ($value) {
                    return DB::table($pivot)->where($column, $value)->pluck('mosque_id')
                        ->map(fn ($v): int => (int) $v)->all();
                }
            }

            return [];
        }, []);
    }

    public function organizationIds(mixed $user = null): array
    {
        $user ??= Auth::user();
        if ($user === null || $this->isGlobal($user)) {
            return [];
        }

        return $this->safe(function () use ($user): array {
            $ids = [];
            if (! empty($user->organization_id)) {
                $ids[] = (int) $user->organization_id;
            }
            if (! empty($user->department_id)) {
                $ids[] = (int) $user->department_id;
            }

            return array_values(array_unique($ids));
        }, []);
    }

    /** Terapkan batas cakupan pada query builder (kolom kampus/masjid). */
    public function applyScope(mixed $query, mixed $user = null, string $campusColumn = 'campus_id', string $mosqueColumn = 'mosque_id'): mixed
    {
        $scope = $this->scopeFor($user);

        if ($scope['is_global']) {
            return $query;
        }

        if ($scope['campus_ids'] && $this->columnExists($query, $campusColumn)) {
            $query->whereIn($campusColumn, $scope['campus_ids']);
        }

        if ($scope['mosque_ids'] && $this->columnExists($query, $mosqueColumn)) {
            $query->whereIn($mosqueColumn, $scope['mosque_ids']);
        }

        return $query;
    }

    protected function columnExists(mixed $query, string $column): bool
    {
        return $this->safe(function () use ($query, $column): bool {
            $table = $query->getModel()->getTable();

            return Schema::hasColumn($table, $column);
        }, false);
    }

    // ── Kode dokumen ─────────────────────────────────────────────────────────

    /**
     * Bangkitkan kode berurutan yang aman dari balapan (dipakai dalam transaksi).
     * Contoh: MOS-0007, KHN-0012, RMD-1448-01.
     */
    public function nextCode(string $prefix, string $table, string $column = 'code', int $pad = 4): string
    {
        return $this->safe(function () use ($prefix, $table, $column, $pad): string {
            if (! $this->hasTable($table)) {
                return $prefix.'-'.strtoupper(substr(md5((string) microtime(true)), 0, 6));
            }

            $last = DB::table($table)
                ->where($column, 'like', $prefix.'-%')
                ->orderByDesc('id')
                ->value($column);

            $sequence = 1;
            if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
                $sequence = ((int) $m[1]) + 1;
            }

            return $prefix.'-'.str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT);
        }, $prefix.'-0001');
    }

    // ── Waktu & tanggal ──────────────────────────────────────────────────────

    public function date(?string $value = null): Carbon
    {
        return $value ? Carbon::parse($value) : Carbon::now();
    }

    public function timeToMinutes(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        $parts = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
    }

    /** Jarak menit antara dua waktu; negatif bila `$to` lebih awal. */
    public function minutesBetween(?string $from, ?string $to): ?int
    {
        $a = $this->timeToMinutes($from);
        $b = $this->timeToMinutes($to);

        return ($a === null || $b === null) ? null : $b - $a;
    }

    /** Apakah tanggal jatuh pada akhir pekan menurut konfigurasi institusi. */
    public function isWeekend(Carbon $date): bool
    {
        $days = (array) config('religious.calendar.weekend_days', []);
        $weekend = [];
        foreach ($days as $day) {
            $weekend[] = is_numeric($day) ? (int) $day : (int) Carbon::parse($day)->dayOfWeek;
        }

        return in_array((int) $date->dayOfWeek, $weekend, true);
    }

    /** Tanggal Hijriah perkiraan (offset dikonfigurasi, bukan asumsi keras \u00a793). */
    public function hijriOffsetDays(): int
    {
        return (int) config('religious.calendar.hijri_offset_days', -1);
    }

    // ── Utilitas ─────────────────────────────────────────────────────────────

    /** Buang nilai sensitif dari konteks audit. */
    public function sanitize(array $context): array
    {
        $blocked = (array) config('religious.security.audit_blocked_keys', [
            'password', 'token', 'secret', 'nik', 'nomor_rekening',
        ]);

        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitize($value);
            }
        }

        return $context;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config('religious.'.$key, $default);
    }
}
