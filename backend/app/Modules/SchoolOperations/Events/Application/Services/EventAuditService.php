<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Application\Services;

use App\Modules\SchoolOperations\Events\Domain\Support\EventContext;
use Illuminate\Support\Facades\DB;

/**
 * Jejak audit modul Event.
 *
 * Sifatnya append-only: kelas ini sengaja TIDAK menyediakan cara mengubah atau
 * menghapus baris. Audit yang dapat disunting tidak membuktikan apa pun, dan
 * justru menyembunyikan penyalahgunaan.
 *
 * Kegagalan menulis audit tidak boleh menjatuhkan alur bisnis, tetapi juga
 * tidak boleh hilang tanpa jejak — karena itu penulisannya dibungkus
 * EventContext::safe() yang mencatat kegagalan untuk ditindaklanjuti.
 */
class EventAuditService
{
    public function __construct(private readonly EventContext $context) {}

    /**
     * Catat satu tindakan.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        ?int $eventId = null,
        ?int $campusId = null,
    ): void {
        $actor = $this->context->actor();

        $this->context->safe(fn () => DB::table('event_audit_logs')->insert([
            'event_id' => $eventId,
            'actor_id' => $actor['id'] ?? $this->context->userId(),
            'actor_name' => $actor['name'] ?? null,
            'actor_role' => $actor['role'] ?? null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $old === [] ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_value' => $new === [] ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255),
            'campus_id' => $campusId,
            'created_at' => now(),
        ]));
    }

    /**
     * Catat perubahan sekumpulan kolom pada satu entitas.
     *
     * Hanya kolom yang benar-benar berubah yang disimpan; tanpa penyaringan ini
     * jejak audit akan penuh dengan kolom yang nilainya tidak pernah berubah.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordChanges(
        string $action,
        string $entityType,
        ?int $entityId,
        array $before,
        array $after,
        ?string $reason = null,
        ?int $eventId = null,
        ?int $campusId = null,
    ): void {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            if ($previous != $value) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }

        if ($old === [] && $new === []) {
            return;
        }

        $this->record($action, $entityType, $entityId, $old, $new, $reason, $eventId, $campusId);
    }
}
