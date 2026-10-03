<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Controllers;

use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionResult;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** Public, published-only competition pages; never expose private registrations/scores. */
class CompetitionPublicController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $competition = Competition::query()->where('slug', $slug)->where('default_visibility', 'PUBLIC')->firstOrFail();
        $edition = CompetitionEdition::query()->where('competition_id', $competition->id)
            ->where('public_visibility', 'PUBLIC')->whereNotNull('published_at')
            ->whereNotIn('status', ['DRAFT', 'CANCELLED', 'ARCHIVED'])->orderByDesc('year')->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'competition' => $competition->only(['id', 'code', 'name', 'slug', 'short_name', 'description', 'organizer_name']),
                'edition' => $edition->only(['id', 'year', 'name', 'theme', 'description', 'terms', 'starts_on', 'ends_on', 'registration_opens', 'registration_closes', 'status', 'public_visibility']),
                'categories' => CompetitionCategory::query()->where('edition_id', $edition->id)->where('is_active', true)->orderBy('sort_order')->get(['code', 'name', 'description', 'gender_restriction', 'min_grade_level', 'max_grade_level', 'participant_mode', 'min_team_size', 'max_team_size', 'capacity']),
                'results' => CompetitionResult::query()->where('edition_id', $edition->id)->where('is_published', true)->orderBy('category_id')->orderBy('position')->get()->map(function ($result): array {
                    $participant = $result->participant_id ? CompetitionParticipant::query()->find($result->participant_id) : null;
                    $team = $result->team_id ? CompetitionTeam::query()->find($result->team_id) : null;

                    return [
                        'category_id' => $result->category_id,
                        'participant_name' => $team?->name ?? $participant?->display_name ?? 'Peserta',
                        'final_score' => $result->final_score,
                        'position' => $result->position,
                        'qualification_status' => $result->qualification_status,
                        'status' => $result->status,
                    ];
                })->all(),
            ],
        ]);
    }
}
