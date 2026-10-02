<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Support;

use App\Modules\Hrd\Models\HrEmployee;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Konteks bersama lapisan strategis:
 *  - jejak audit & timeline (tabel yang sama dengan Phase 1/2, bukan salinan),
 *  - resolusi scope (siapa boleh melihat data siapa),
 *  - pembacaan lintas modul yang tahan gagal (provider belum terpasang),
 *  - utilitas angka supaya seluruh laporan memakai pembulatan/median yang sama.
 */
class Phase3Context
{
    public const AUDIT_TABLE = 'hr_audit_logs';

    public const EVENT_TABLE = 'hr_workforce_events';

    /**
     * Jejak audit keputusan strategis. Phase 3 menulis ke tabel audit yang sama
     * dengan Phase 1/2 sehingga satu keputusan hanya punya satu riwayat.
     */
    public function audit(
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        array $context = [],
        ?string $reason = null,
        mixed $actor = null,
        ?int $employeeId = null,
        array $before = [],
        array $after = [],
    ): void {
        if (! Schema::hasTable(self::AUDIT_TABLE)) {
            return;
        }

        $this->safe(function () use ($action, $entityType, $entityId, $context, $reason, $actor, $employeeId, $before, $after): void {
            DB::table(self::AUDIT_TABLE)->insert([
                'action' => mb_substr($action, 0, 80),
                'entity_type' => mb_substr($entityType, 0, 100),
                'entity_id' => is_numeric($entityId) ? (int) $entityId : null,
                'employee_id' => $employeeId,
                'actor_id' => $this->actorId($actor),
                'actor_role' => $this->actorRole($actor),
                'before' => $before === [] ? null : json_encode($before),
                'after' => ($after === [] && $context === []) ? null : json_encode($context === [] ? $after : $context),
                'changed_fields' => null,
                'reason' => $reason ? mb_substr($reason, 0, 400) : null,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, null);
    }

    /**
     * Timeline tenaga kerja (dipakai Employee 360 dan papan strategis).
     */
    public function event(
        string $category,
        string $eventType,
        array $data = [],
        ?int $employeeId = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        mixed $actor = null,
    ): void {
        if (! Schema::hasTable(self::EVENT_TABLE)) {
            return;
        }

        $this->safe(function () use ($category, $eventType, $data, $employeeId, $subjectType, $subjectId, $actor): void {
            DB::table(self::EVENT_TABLE)->insert([
                'employee_id' => $employeeId,
                'event_type' => mb_substr($eventType, 0, 191),
                'event_category' => mb_substr($category, 0, 191),
                'subject_type' => $subjectType,
                'subject_id' => is_numeric($subjectId) ? (int) $subjectId : null,
                'occurred_at' => now(),
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'payload' => json_encode($data),
                'actor_user_id' => $this->actorId($actor),
                'visible_to_employee' => (bool) ($data['visible_to_employee'] ?? false),
                'meta' => isset($data['meta']) ? json_encode($data['meta']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, null);
    }

    /**
     * Baca lintas modul dengan aman: kolom/tabel yang belum ada atau provider
     * yang belum terpasang tidak boleh menjatuhkan perhitungan strategis.
     */
    public function safe(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $default;
        }
    }

    public function hasTable(string $table): bool
    {
        return (bool) $this->safe(fn (): bool => Schema::hasTable($table), false);
    }

    /**
     * Nilai dari tabel lain (mis. modul Academic) bila tersedia.
     */
    public function probe(string $table, string $column, array $wheres = [], mixed $default = null): mixed
    {
        if (! $this->hasTable($table)) {
            return $default;
        }

        return $this->safe(function () use ($table, $column, $wheres, $default): mixed {
            $query = DB::table($table);
            foreach ($wheres as $key => $value) {
                is_array($value) ? $query->whereIn($key, $value) : $query->where($key, $value);
            }

            $value = $query->orderByDesc('id')->value($column);

            return $value ?? $default;
        }, $default);
    }

    public function probeCount(string $table, array $wheres = [], mixed $default = 0): int
    {
        if (! $this->hasTable($table)) {
            return (int) $default;
        }

        return (int) $this->safe(function () use ($table, $wheres): int {
            $query = DB::table($table);
            foreach ($wheres as $key => $value) {
                is_array($value) ? $query->whereIn($key, $value) : $query->where($key, $value);
            }

            return (int) $query->count();
        }, (int) $default);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config('hrd_phase3.'.$key, $default);
    }

    /* ---------------------------------------------------------------------
     | Scope & permission
     | -------------------------------------------------------------------- */

    public function employeeFor(mixed $user): ?HrEmployee
    {
        if (! $user || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return $this->safe(
            fn (): ?HrEmployee => HrEmployee::query()->where('user_id', $user->getAuthIdentifier())->first(),
            null
        );
    }

    /**
     * Scope data pemanggil. Kepala sekolah melihat sekolahnya, HR Director
     * melihat yayasan, kepala unit melihat departemennya, pegawai melihat
     * dirinya sendiri.
     *
     * @return array{type:string,employee_id:int|null,organization_id:int|null,department_id:int|null,employee_ids:array<int>}
     */
    public function scopeOf(mixed $user, bool $allowGlobal = false): array
    {
        $employee = $this->employeeFor($user);
        $globalRoles = (array) $this->config('security.global_roles', [
            'HR_DIRECTOR', 'FOUNDATION_ADMIN', 'SUPER_ADMIN', 'HR_ADMIN',
        ]);

        $roleNames = $this->safe(fn () => method_exists($user, 'getRoleNames') ? $user->getRoleNames()->all() : [], []);
        $isGlobal = $allowGlobal && ($this->can($user, 'hr.strategic.view.all') || array_intersect((array) $roleNames, $globalRoles) !== []);

        return [
            'type' => $isGlobal ? 'GLOBAL' : ($employee?->department_id ? 'DEPARTMENT' : 'SELF'),
            'employee_id' => $employee?->getKey(),
            'organization_id' => $employee?->organization_id,
            'department_id' => $employee?->department_id,
            'employee_ids' => $employee ? [$employee->getKey()] : [],
        ];
    }

    public function can(mixed $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        return (bool) $this->safe(fn (): bool => (bool) $user->can($permission), false);
    }

    /**
     * Query pegawai yang sudah dibatasi scope + filter organisasi/departemen.
     *
     * @return Builder<HrEmployee>
     */
    public function scopedEmployees(mixed $user, ?int $organizationId = null, ?int $departmentId = null, bool $allowGlobal = false): Builder
    {
        $query = HrEmployee::query();
        $scope = $this->scopeOf($user, $allowGlobal);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        if ($user !== null && $scope['type'] === 'SELF') {
            $query->whereKey($scope['employee_id'] ?? 0);
        }

        return $query;
    }

    /**
     * @return array<int, int>
     */
    public function employeeIdsFor(mixed $user, ?int $organizationId = null, ?int $departmentId = null, bool $allowGlobal = false): array
    {
        return $this->scopedEmployees($user, $organizationId, $departmentId, $allowGlobal)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /* ---------------------------------------------------------------------
     | Idempotensi & penomoran
     | -------------------------------------------------------------------- */

    /**
     * True hanya pada pemanggilan pertama untuk sebuah kunci. Dipakai agar
     * penjadwal boleh terpanggil ulang tanpa menggandakan snapshot/akrual.
     */
    public function firstTime(string $key, int $ttlSeconds = 3600): bool
    {
        return (bool) $this->safe(fn (): bool => Cache::add('hrd3:'.$key, 1, $ttlSeconds), true);
    }

    public function nextCode(string $table, string $prefix, ?string $scope = null): string
    {
        if (! $this->hasTable($table)) {
            return $prefix.'-'.now()->format('Y').'-0001';
        }

        $pattern = $scope ? $prefix.'-'.$scope.'-%' : $prefix.'-%';
        $count = (int) $this->safe(fn (): int => (int) DB::table($table)->where('code', 'like', $pattern)->count(), 0);

        return $prefix.'-'.($scope ? $scope.'-' : '').str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    /* ---------------------------------------------------------------------
     | Angka & periode (satu definisi untuk seluruh laporan)
     | -------------------------------------------------------------------- */

    public function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    public function score(mixed $value): float
    {
        return round((float) $value, 2);
    }

    public function percent(float $part, float $total): float
    {
        return $total <= 0.0 ? 0.0 : round(($part / $total) * 100, 2);
    }

    public function change(float $from, float $to): float
    {
        return $from <= 0.0 ? 0.0 : round((($to - $from) / $from) * 100, 2);
    }

    /**
     * @param  array<int, float|int>  $values
     */
    public function median(array $values): float
    {
        $values = array_values(array_filter(array_map('floatval', $values), fn (float $v): bool => $v != 0.0 || true));
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? round($values[$middle], 2)
            : round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    /**
     * @param  array<int, float|int>  $values
     */
    public function percentile(array $values, float $percentile): float
    {
        $values = array_values(array_map('floatval', $values));
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $index = (int) floor(($percentile / 100) * (count($values) - 1));

        return round($values[max(0, min($index, count($values) - 1))], 2);
    }

    /**
     * MoM/QoQ/YoY dari seri berurutan (terlama → terbaru).
     *
     * @param  array<int, float|int>  $series
     * @return array{current:float,previous:float,mom:float,qoq:float,yoy:float,trend:string}
     */
    public function trend(array $series): array
    {
        $series = array_values(array_map('floatval', $series));
        $current = $series ? (float) end($series) : 0.0;
        $previous = count($series) > 1 ? (float) $series[count($series) - 2] : 0.0;
        $quarterBack = count($series) > 3 ? (float) $series[count($series) - 4] : $previous;
        $yearBack = count($series) > 12 ? (float) $series[count($series) - 13] : $series[0] ?? 0.0;
        $mom = $this->change($previous, $current);

        return [
            'current' => $this->money($current),
            'previous' => $this->money($previous),
            'mom' => $mom,
            'qoq' => $this->change($quarterBack, $current),
            'yoy' => $this->change($yearBack, $current),
            'trend' => $mom > 0 ? 'UP' : ($mom < 0 ? 'DOWN' : 'FLAT'),
        ];
    }

    public function periodOf(CarbonInterface|DateTimeInterface|string $date, string $granularity = 'MONTHLY'): string
    {
        $carbon = Carbon::parse($date);

        return match (strtoupper($granularity)) {
            'DAILY' => $carbon->format('Y-m-d'),
            'WEEKLY' => $carbon->format('o-\WW'),
            'QUARTERLY' => $carbon->format('Y').'-Q'.$carbon->quarter,
            'ANNUAL' => $carbon->format('Y'),
            default => $carbon->format('Y-m'),
        };
    }

    public function monthsBetween(CarbonInterface|DateTimeInterface|string $from, CarbonInterface|DateTimeInterface|string $to): int
    {
        return (int) Carbon::parse($from)->diffInMonths(Carbon::parse($to));
    }

    /**
     * Rentetan periode antar dua tanggal (untuk grafik/deret tren).
     *
     * @return array<int, string>
     */
    public function periodsBetween(CarbonInterface|DateTimeInterface|string $from, CarbonInterface|DateTimeInterface|string $to, int $limit = 24): array
    {
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();
        $periods = [];
        while ($cursor->lessThanOrEqualTo($end) && count($periods) < $limit) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->copy()->addMonth();
        }

        return $periods;
    }

    public function actorId(mixed $actor): ?int
    {
        if (! $actor || ! method_exists($actor, 'getAuthIdentifier')) {
            return null;
        }

        $id = $actor->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    public function actorRole(mixed $actor): ?string
    {
        if (! $actor || ! method_exists($actor, 'getRoleNames')) {
            return null;
        }

        $roles = $this->safe(fn () => $actor->getRoleNames()->all(), []);

        return $roles === [] ? null : mb_substr((string) $roles[0], 0, 80);
    }

    public function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: null;
    }
}
