<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Models\ActivityAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Jejak audit aktivitas (§85).
 *
 * Append-only: tidak ada layanan, controller, atau perintah yang menyunting atau
 * menghapus baris di sini. Yang dicatat bukan hanya "apa berubah", tetapi juga
 * **nilai sebelum**: perpindahan anggota, perubahan peran, dan keputusan
 * penyelesaian harus dapat dipertanggungjawabkan bertahun-tahun kemudian.
 */
class ActivityAuditService
{
    public function record(AuditAction|string $action, array $context = []): ActivityAuditLog
    {
        $subject = $context['subject'] ?? null;

        return ActivityAuditLog::query()->create([
            'action' => $action instanceof AuditAction ? $action->value : $action,
            'subject_type' => $subject instanceof Model ? $subject->getMorphClass() : ($context['subject_type'] ?? null),
            'subject_id' => $subject instanceof Model ? (string) $subject->getKey() : ($context['subject_id'] ?? null),
            'actor_user_id' => $context['actor'] ?? Auth::id(),
            'actor_role' => $this->actorRole($context['actor'] ?? Auth::id()),
            'program_id' => $context['program_id'] ?? $this->infer($subject, 'program_id'),
            'student_id' => $context['student_id'] ?? $this->infer($subject, 'student_id'),
            'before' => $this->normalise($context['before'] ?? null),
            'after' => $this->normalise($context['after'] ?? null),
            'metadata' => $context['metadata'] ?? null,
            'ip' => Request::ip(),
        ]);
    }

    /** @return LengthAwarePaginator */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return ActivityAuditLog::query()
            ->with(['actor:id,name', 'program:id,name', 'student:id,full_name'])
            ->when(isset($filters['action']), fn ($query) => $query->where('action', $filters['action']))
            ->when(isset($filters['program_id']), fn ($query) => $query->where('program_id', $filters['program_id']))
            ->when(isset($filters['student_id']), fn ($query) => $query->where('student_id', $filters['student_id']))
            ->when(isset($filters['actor_user_id']), fn ($query) => $query->where('actor_user_id', $filters['actor_user_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('created_at', '<=', $filters['to']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /** Ringkasan untuk layar kesehatan modul. */
    public function summary(): array
    {
        return [
            'total' => ActivityAuditLog::query()->count(),
            'last_24h' => ActivityAuditLog::query()->where('created_at', '>=', now()->subDay())->count(),
            'by_action' => ActivityAuditLog::query()
                ->selectRaw('action, count(*) as total')
                ->groupBy('action')
                ->orderByDesc('total')
                ->limit(10)
                ->pluck('total', 'action')
                ->all(),
        ];
    }

    protected function actorRole(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return User::query()->find($userId)?->getRoleNames()->first();
    }

    protected function infer(?Model $subject, string $column): ?int
    {
        if ($subject === null) {
            return null;
        }

        $value = $subject->getAttribute($column);

        return $value === null ? null : (int) $value;
    }

    protected function normalise(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Model) {
            return $value->attributesToArray();
        }

        return (array) $value;
    }
}
