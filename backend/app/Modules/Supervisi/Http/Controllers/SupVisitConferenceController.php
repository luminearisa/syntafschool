<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\VisitAccessService;
use App\Modules\Supervisi\Application\Services\VisitConferenceService;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Wawancara pra-observasi & pasca-observasi pada satu kegiatan. */
class SupVisitConferenceController extends SupController
{
    public function __construct(
        private readonly VisitConferenceService $conferences,
        private readonly VisitAccessService $access,
    ) {}

    public function index(Request $request, SupVisit $visit): JsonResponse
    {
        $this->access->assertCanView($this->actor($request), $visit);

        return $this->successResponse(
            $visit->conferences()->get()->map(fn ($conference) => [
                'kind' => $conference->kind,
                'held_at' => $conference->held_at?->toIso8601String(),
                'location' => $conference->location,
                'participants' => $conference->participants,
                'answers' => $conference->answers,
                'agreements' => $conference->agreements,
                'reflection' => $conference->reflection,
                'next_focus' => $conference->next_focus,
            ])->values()->all(),
            'Wawancara kegiatan supervisi',
        );
    }

    /** Catat/perbarui wawancara PRE atau POST. */
    public function record(Request $request, SupVisit $visit, string $kind): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanExecute($actor, $visit);

        $data = $request->validate([
            'held_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:120'],
            'participants' => ['nullable', 'array'],
            'participants.*.name' => ['required_with:participants', 'string', 'max:120'],
            'participants.*.role' => ['nullable', 'string', 'max:60'],
            'answers' => ['nullable', 'array'],
            'answers.*.question' => ['required_with:answers', 'string', 'max:500'],
            'answers.*.answer' => ['nullable', 'string', 'max:2000'],
            'agreements' => ['nullable', 'array'],
            'agreements.*' => ['string', 'max:500'],
            'reflection' => ['nullable', 'string', 'max:5000'],
            'next_focus' => ['nullable', 'string', 'max:300'],
        ]);

        $conference = $this->conferences->record($visit, $kind, $data, $actor);

        return $this->successResponse([
            'kind' => $conference->kind,
            'held_at' => $conference->held_at?->toIso8601String(),
            'agreements' => $conference->agreements,
        ], 'Wawancara '.strtoupper($kind).' tersimpan');
    }
}
