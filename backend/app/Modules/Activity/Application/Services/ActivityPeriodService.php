<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\PeriodStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Models\ActivityPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Periode aktivitas (§18): tahun ajaran, semester, term, atau musim lomba.
 *
 * Hanya satu periode boleh menjadi "berjalan" — itulah periode yang dipakai
 * pendaftaran baru, dan penandaan ini dilakukan dalam satu transaksi agar tidak
 * ada dua periode berjalan bersamaan.
 */
class ActivityPeriodService
{
    public function __construct(private readonly ActivityAuditService $audit) {}

    public function create(array $attributes, ?User $actor = null): ActivityPeriod
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $period = ActivityPeriod::query()->create([
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'type' => $attributes['type'] ?? 'SEMESTER',
                'academic_year_id' => $attributes['academic_year_id'] ?? null,
                'semester_id' => $attributes['semester_id'] ?? null,
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'],
                'registration_opens' => $attributes['registration_opens'] ?? null,
                'registration_closes' => $attributes['registration_closes'] ?? null,
                'status' => $attributes['status'] ?? PeriodStatus::ACTIVE->value,
                'is_current' => (bool) ($attributes['is_current'] ?? false),
                'notes' => $attributes['notes'] ?? null,
            ]);

            if ($period->is_current) {
                $this->markCurrent($period, $actor);
            }

            $this->audit->record('period.created', ['subject' => $period, 'after' => $period, 'actor' => $actor?->id]);

            return $period->refresh();
        });
    }

    public function markCurrent(ActivityPeriod $period, ?User $actor = null): ActivityPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            ActivityPeriod::query()
                ->whereKeyNot($period->id)
                ->where('is_current', true)
                ->update(['is_current' => false, 'updated_at' => now()]);

            $period->forceFill(['is_current' => true])->save();

            $this->audit->record('period.current_changed', [
                'subject' => $period,
                'after' => ['is_current' => true],
                'actor' => $actor?->id,
            ]);

            return $period;
        });
    }

    public function current(): ?ActivityPeriod
    {
        return ActivityPeriod::query()
            ->where('is_current', true)
            ->first()
            ?? ActivityPeriod::query()->orderByDesc('start_date')->first();
    }

    /** Periode yang pendaftarannya sedang dibuka. */
    public function openForRegistration(): ?ActivityPeriod
    {
        $today = now()->toDateString();

        return ActivityPeriod::query()
            ->where('status', PeriodStatus::ACTIVE->value)
            ->where(fn ($query) => $query
                ->whereNull('registration_opens')
                ->orWhere('registration_opens', '<=', $today))
            ->where(fn ($query) => $query
                ->whereNull('registration_closes')
                ->orWhere('registration_closes', '>=', $today))
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->first();
    }

    public function close(ActivityPeriod $period, ?User $actor = null): ActivityPeriod
    {
        if ($period->memberships()->whereIn('status', ['ACTIVE', 'SUSPENDED'])->exists()) {
            throw ActivityConflictException::make('Periode masih memiliki anggota aktif — selesaikan atau pindahkan mereka lebih dahulu.');
        }

        $before = $period->attributesToArray();
        $period->forceFill(['status' => PeriodStatus::CLOSED->value, 'is_current' => false])->save();

        $this->audit->record('period.closed', ['subject' => $period, 'before' => $before, 'after' => $period, 'actor' => $actor?->id]);

        return $period;
    }
}
