<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Support;

use App\Modules\SchoolOperations\Events\Models\EventDomainEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Titik tolak modul Event: jejak audit, event bus, idempotensi, pembatasan
 * cakupan, dan pembangkit kode dokumen.
 *
 * Seluruh operasi sampingan dibungkus {@see self::safe()} sehingga kegagalan
 * pencatatan audit atau penerbitan event TIDAK menggagalkan transaksi bisnis —
 * tetapi juga tidak boleh hilang diam-diam, karena itu kegagalannya dilaporkan
 * lewat `report()`.
 */
class EventContext
{
    /** Penampung kegagalan operasi sampingan pada satu permintaan. */
    protected array $failures = [];

    /** Aktor saat ini (id, nama, peran). */
    public function actor(): array
    {
        $user = Auth::user();

        return [
            'id' => $user?->id,
            'name' => $user?->name ?? 'sistem',
            'role' => $user?->getRoleNames()->first() ?? ($user ? 'user' : 'system'),
        ];
    }

    public function userId(): ?int
    {
        return Auth::id();
    }

    /**
     * Menjalankan operasi sampingan yang boleh gagal.
     *
     * Kegagalan dicatat agar dapat diperiksa pada pengujian, bukan ditelan
     * tanpa jejak. Inilah yang membuat bug "audit selalu gagal tersimpan"
     * terlihat pada smoke test.
     */
    public function safe(callable $operation, mixed $fallback = null): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->failures[] = [
                'message' => $e->getMessage(),
                'type' => $e::class,
                'at' => now()->toDateTimeString(),
            ];

            if (config('events.report_side_effect_failures', true)) {
                report($e);
            }

            return $fallback;
        }
    }

    /** Daftar kegagalan operasi sampingan (dipakai pengujian & diagnostik). */
    public function failures(): array
    {
        return $this->failures;
    }

    public function flushFailures(): void
    {
        $this->failures = [];
    }

    // ── Audit (§94) ─────────────────────────────────────────────────────────

    /**
     * Mencatat satu jejak audit.
     *
     * @param  string  $action  mis. 'event.created'
     * @param  object|string|null  $entity  model atau nama entitas
     */
    public function audit(
        string $action,
        object|string|null $entity = null,
        array $newValue = [],
        ?array $oldValue = null,
        ?string $reason = null,
        ?int $eventId = null,
    ): void {
        $this->safe(function () use ($action, $entity, $newValue, $oldValue, $reason, $eventId): void {
            $actor = $this->actor();
            $request = request();

            $entityType = is_string($entity) ? $entity : ($entity ? $entity::class : null);
            $entityId = is_object($entity) ? ($entity->id ?? null) : null;
            $resolvedEventId = $eventId
                ?? (is_object($entity) && isset($entity->event_id) ? (int) $entity->event_id : null)
                ?? (is_object($entity) && isset($entity->id) && $entity instanceof \App\Modules\SchoolOperations\Events\Models\Event ? (int) $entity->id : null);

            $payload = [
                'event_id' => $resolvedEventId,
                'actor_id' => $actor['id'],
                'actor_name' => $actor['name'],
                'actor_role' => $actor['role'],
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_value' => $oldValue === null ? null : json_encode($oldValue),
                'new_value' => $newValue === [] ? null : json_encode($newValue),
                'reason' => $reason,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255) : null,
                'campus_id' => is_object($entity) ? ($entity->campus_id ?? null) : null,
                'created_at' => now(),
            ];

            // Tabel bawaan ditulis lewat model; nama tabel yang ditimpa lewat
            // konfigurasi tidak boleh diperlakukan sebagai nama kelas.
            if ($this->auditTable() === 'event_audit_logs') {
                \App\Modules\SchoolOperations\Events\Models\EventAuditLog::query()->create($payload);
            } else {
                DB::table($this->auditTable())->insert($payload);
            }
        });
    }

    protected function auditTable(): string
    {
        return (string) config('events.tables.audit', 'event_audit_logs');
    }

    // ── Event bus internal (§96) ────────────────────────────────────────────

    /**
     * Menerbitkan peristiwa domain internal.
     *
     * @param  array<int, string>  $channels  konsumen yang seharusnya bereaksi,
     *                                        mis. ['calendar', 'notification'].
     */
    public function publish(
        string $eventName,
        object|string|null $aggregate = null,
        array $payload = [],
        array $channels = [],
        ?int $eventId = null,
    ): void {
        $this->safe(function () use ($eventName, $aggregate, $payload, $channels, $eventId): void {
            $aggregateType = is_string($aggregate) ? $aggregate : ($aggregate ? $aggregate::class : null);
            $aggregateId = is_object($aggregate) ? ($aggregate->id ?? null) : null;

            EventDomainEvent::query()->create([
                'event_id' => $eventId
                    ?? (is_object($aggregate) && isset($aggregate->event_id) ? (int) $aggregate->event_id : null)
                    ?? (is_object($aggregate) && $aggregate instanceof \App\Modules\SchoolOperations\Events\Models\Event ? (int) $aggregate->id : null),
                'event_name' => $eventName,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload' => json_encode($payload),
                'channels' => $channels === [] ? null : json_encode($channels),
                'published' => true,
                'published_at' => now(),
                'created_at' => now(),
            ]);

            // Penerusan ke konsumen nyata (kalender, notifikasi, keuangan, ...)
            // dilakukan oleh layanan orkestrasi, bukan di sini, agar urutan dan
            // kompensasinya dapat diuji secara terpisah.
        });
    }

    // ── Idempotensi ─────────────────────────────────────────────────────────

    /**
     * Menjalankan operasi tepat sekali untuk sebuah kunci.
     *
     * Dipakai pada pemindaian QR ganda dan sinkronisasi perangkat luring agar
     * satu kejadian tidak menghasilkan dua catatan.
     */
    public function idempotent(string $key, string $scope, callable $operation, array $meta = []): mixed
    {
        $existing = $this->safe(fn () => DB::table('event_idempotency_keys')
            ->where('idempotency_key', $key)->first());

        if ($existing) {
            return ['written' => false, 'reason' => 'DUPLICATE', 'key' => $key];
        }

        $result = $operation();

        $this->safe(fn () => DB::table('event_idempotency_keys')->insert([
            'idempotency_key' => $key,
            'scope' => $scope,
            'source' => $meta['source'] ?? 'MANUAL',
            'external_reference' => $meta['external_reference'] ?? null,
            'device_id' => $meta['device_id'] ?? null,
            'processed_at' => now(),
            'created_at' => now(),
        ]));

        return $result;
    }

    /** Kunci idempotensi yang stabil untuk sebuah operasi. */
    public function idempotencyKey(string ...$parts): string
    {
        return implode('|', array_map(static fn ($p): string => (string) $p, $parts));
    }

    // ── Kode dokumen ────────────────────────────────────────────────────────

    /**
     * Membangkitkan kode berurutan yang dapat dibaca manusia.
     *
     * Memakai tabel sumber sebagai penghitung agar tidak bergantung pada
     * penghitung global yang bisa bentrok antar jenis dokumen.
     */
    public function generateCode(string $prefix, ?string $table = null, string $column = 'code'): string
    {
        $year = now()->format('Y');
        $base = strtoupper($prefix).'-'.$year;

        if ($table === null) {
            return $base.'-'.strtoupper(Str::random(6));
        }

        $last = $this->safe(fn () => DB::table($table)
            ->where($column, 'like', $base.'-%')
            ->orderByDesc('id')
            ->value($column));

        $sequence = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return $base.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /** Token acak untuk QR/badge (dipakai bersama tanda tangan di layanan). */
    public function randomToken(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    // ── Cakupan akses (Rule 15, §34 privasi) ────────────────────────────────

    /**
     * Membatasi query pada cakupan yang boleh dilihat aktor.
     *
     * Kewenangan ditentukan di {@see \App\Modules\SchoolOperations\Events\Application\Services\EventScopeService};
     * di sini hanya disediakan penyaring siap pakai agar tidak ada layanan
     * yang lupa menerapkannya.
     */
    public function scopeQuery(mixed $query, string $column = 'campus_id', ?array $allowedIds = null): mixed
    {
        if ($allowedIds === null || $allowedIds === []) {
            return $query;
        }

        return $query->whereIn($column, $allowedIds);
    }

    public function campusIds(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        return $this->safe(function () use ($user): array {
            if (method_exists($user, 'campusIds')) {
                return (array) $user->campusIds();
            }
            if (isset($user->campus_id)) {
                return [(int) $user->campus_id];
            }

            return [];
        }, []) ?? [];
    }

    // ── Waktu ───────────────────────────────────────────────────────────────

    /**
     * Membandingkan kolom tanggal dengan benar.
     *
     * Kolom ber-cast `date` disimpan sebagai datetime oleh driver, sehingga
     * `whereBetween` memotong hari terakhir. Selalu pakai penyaring ini.
     */
    public function applyDateRange(mixed $query, string $column, string $from, string $to): mixed
    {
        return $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
    }

    /** Apakah dua rentang waktu bertumpang tindih. */
    public function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }
}
