<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseEvent;
use App\Modules\Bk\Models\BkReferral;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Timeline kasus — append-only.
 *
 * Timeline adalah ingatan modul BK: siapa melakukan apa, kapan, dan bagaimana
 * status berpindah. Karena itu `summary` sengaja hanya berupa **ringkasan aman**
 * yang boleh dibaca wali kelas (mis. "Sesi konseling selesai"), sedangkan isi
 * catatan konseling tetap di tabelnya sendiri.
 */
class BkTimelineService
{
    /**
     * Catat satu peristiwa pada kasus (atau referral sebelum kasus ada).
     *
     * @param  array<string, mixed>  $payload  metadata aman, bukan isi catatan
     */
    public function record(
        CaseEventType $event,
        ?User $actor = null,
        ?BkCase $case = null,
        ?BkReferral $referral = null,
        ?string $summary = null,
        ?string $statusFrom = null,
        ?string $statusTo = null,
        ?Model $related = null,
        array $payload = [],
    ): BkCaseEvent {
        return BkCaseEvent::query()->create([
            'case_id' => $case?->id,
            'referral_id' => $referral?->id,
            'event_type' => $event->value,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->roles->pluck('name')->first(),
            'summary' => $summary ?? $event->label(),
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'payload' => $payload === [] ? null : $payload,
            'occurred_at' => now(),
        ]);
    }

    /** Pindah status dengan catatan perpindahannya. */
    public function recordTransition(
        Model $subject,
        string $statusFrom,
        string $statusTo,
        ?User $actor,
        CaseEventType $event,
        ?string $summary = null,
    ): BkCaseEvent {
        return $this->record(
            $event,
            $actor,
            $subject instanceof BkCase ? $subject : null,
            $subject instanceof BkReferral ? $subject : null,
            $summary,
            $statusFrom,
            $statusTo,
            $subject,
        );
    }

    /**
     * Timeline satu kasus (termasuk peristiwa referral asalnya).
     *
     * @return Collection<int, BkCaseEvent>
     */
    public function forCase(BkCase $case): Collection
    {
        return BkCaseEvent::query()
            ->with(['actor:id,name'])
            ->where(function ($query) use ($case): void {
                $query->where('case_id', $case->id);

                if ($case->source_referral_id !== null) {
                    $query->orWhere('referral_id', $case->source_referral_id);
                }
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, BkCaseEvent> */
    public function forReferral(BkReferral $referral): Collection
    {
        return BkCaseEvent::query()
            ->with(['actor:id,name'])
            ->where('referral_id', $referral->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }
}
