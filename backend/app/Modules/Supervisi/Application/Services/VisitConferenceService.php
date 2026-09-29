<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use App\Modules\Supervisi\Models\SupConference;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Validation\ValidationException;

/**
 * Wawancara pra-observasi dan pasca-observasi.
 *
 * Instrumen bawaan menyediakan daftar pertanyaan baku (`answers`), tetapi
 * sekolah bebas memakai pertanyaannya sendiri — yang disimpan adalah pasangan
 * pertanyaan–jawaban beserta kesepakatan dan refleksi guru.
 */
class VisitConferenceService
{
    public const KINDS = ['PRE', 'POST'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VisitLifecycleService $lifecycle,
    ) {}

    /**
     * Catat/perbarui wawancara.
     *
     * @param  array<string,mixed>  $data
     */
    public function record(SupVisit $visit, string $kind, array $data, User $actor): SupConference
    {
        $kind = strtoupper($kind);

        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Jenis wawancara harus PRE atau POST.']);
        }

        $this->lifecycle->assertEditable($visit);

        $conference = SupConference::query()->updateOrCreate(
            ['visit_id' => $visit->id, 'kind' => $kind],
            [
                'held_at' => $data['held_at'] ?? now(),
                'location' => $data['location'] ?? null,
                'participants' => $data['participants'] ?? null,
                'answers' => $data['answers'] ?? null,
                'agreements' => $data['agreements'] ?? null,
                'reflection' => $data['reflection'] ?? null,
                'next_focus' => $data['next_focus'] ?? null,
                'created_by' => $actor->id,
            ],
        );

        if ($kind === 'PRE') {
            $visit->forceFill(['pre_conference_at' => $conference->held_at])->save();
            $this->advance($visit, VisitStatus::PRE_CONFERENCE, $actor);
        } else {
            $visit->forceFill(['post_conference_at' => $conference->held_at])->save();
            $this->advance($visit, VisitStatus::POST_CONFERENCE, $actor);
        }

        $this->audit->log('visit.conference_recorded', $visit, null, ['kind' => $kind], null, $actor);

        return $conference->refresh();
    }

    /** Naikkan status bila graf mengizinkan; kalau tidak, biarkan apa adanya. */
    protected function advance(SupVisit $visit, VisitStatus $to, User $actor): void
    {
        $current = $visit->refresh()->status;

        if ($current === $to || in_array($to, $current->allowedNext(), true)) {
            $this->lifecycle->transition($visit, $to, $actor);
        }
    }
}
