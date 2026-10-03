<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Models\LaundryAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Audit modul laundry (§75, Rule 28).
 *
 * Setiap operasi sensitif — alokasi/penyesuaian jatah, perubahan harga, order,
 * berat, pembayaran, refund, transisi status, insiden, serah terima, override —
 * mencatat actor, timestamp, before/after, reason, dan reference.
 */
class LaundryAuditService
{
    public function record(
        string $action,
        ?Model $subject,
        ?User $actor = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?string $reference = null,
        ?Request $request = null,
    ): LaundryAuditLog {
        return LaundryAuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : 'laundry',
            'subject_id' => $subject?->getKey(),
            'before' => $before !== [] ? $before : null,
            'after' => $after !== [] ? $after : null,
            'reason' => $reason,
            'reference' => $reference,
            'ip_address' => $request?->ip(),
        ]);
    }

    /** Query audit untuk layar `laundry.audit` (filter subject/aksi/rentang). */
    public function query(?string $subjectType = null, ?int $subjectId = null, ?string $action = null)
    {
        return LaundryAuditLog::query()
            ->with('actor:id,name')
            ->when($subjectType, fn ($q) => $q->where('subject_type', $subjectType))
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->when($action, fn ($q) => $q->where('action', $action))
            ->latest('id');
    }
}
