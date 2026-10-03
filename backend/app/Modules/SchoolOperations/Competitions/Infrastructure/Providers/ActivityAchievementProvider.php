<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Models\User;
use App\Modules\Activity\Application\Services\ActivityAchievementService;
use App\Modules\Activity\Models\ActivityAchievement;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\AchievementProviderInterface;
use Illuminate\Support\Facades\Log;

/** Bridges finalized competition awards into the Student Activity achievement source of truth. */
class ActivityAchievementProvider implements AchievementProviderInterface
{
    public function __construct(private readonly ActivityAchievementService $achievements) {}

    public function isAvailable(): bool
    {
        return class_exists(ActivityAchievement::class);
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'Student Activity achievement module is unavailable.';
    }

    public function record(array $achievement): array
    {
        $studentId = (int) ($achievement['student_id'] ?? 0);
        $recipientReference = (string) ($achievement['external_reference'] ?? '');
        $actorId = (int) ($achievement['actor_id'] ?? 0);
        $actor = $actorId > 0 ? User::query()->find($actorId) : null;
        if ($studentId < 1 || $recipientReference === '' || $actor === null) {
            return ['created' => false, 'reason' => 'Achievement payload missing student, reference, or actor.'];
        }

        $existing = ActivityAchievement::query()
            ->where('student_id', $studentId)
            ->where('metadata->competition_award_reference', $recipientReference)
            ->first();
        if ($existing !== null) {
            return ['created' => true, 'reference' => 'activity-achievement:'.$existing->id];
        }

        $domainType = match (strtoupper((string) ($achievement['domain'] ?? 'OTHER'))) {
            'ACADEMIC', 'SCIENCE' => 'ACADEMIC',
            'SPORTS' => 'SPORT',
            'ARTS' => 'ART',
            'RELIGIOUS' => 'RELIGIOUS',
            'TECHNOLOGY' => 'TECHNOLOGY',
            'LEADERSHIP' => 'LEADERSHIP',
            'SOCIAL', 'ENTREPRENEURSHIP', 'LANGUAGE' => 'COMMUNITY',
            default => 'OTHER',
        };
        $level = match (strtoupper((string) ($achievement['level'] ?? 'SCHOOL'))) {
            'INTERNAL', 'CLASS', 'DORMITORY', 'UNIT', 'CAMPUS' => 'INTERNAL',
            'DISTRICT' => 'DISTRICT',
            'CITY' => 'CITY',
            'PROVINCE', 'REGIONAL' => 'PROVINCE',
            'NATIONAL' => 'NATIONAL',
            'INTERNATIONAL' => 'INTERNATIONAL',
            default => 'SCHOOL',
        };
        $position = (int) ($achievement['position'] ?? 0);
        $rank = match ($position) {
            1 => 'FIRST', 2 => 'SECOND', 3 => 'THIRD', 4 => 'FOURTH', default => null,
        };
        $title = trim((string) ($achievement['title'] ?? 'Competition achievement'));

        try {
            $row = $this->achievements->create([
                'student_id' => $studentId,
                'title' => $title,
                'description' => $achievement['description'] ?? null,
                'type' => $domainType,
                'ownership' => 'INDIVIDUAL',
                'level' => $level,
                'rank' => $rank,
                'awarded_date' => $achievement['awarded_at'] ?? now()->toDateString(),
                'organizer' => $achievement['organizer'] ?? null,
                'verification_status' => 'PENDING',
                'counts_for_portfolio' => true,
                'created_by' => $actor->id,
                'metadata' => [
                    'source' => 'competition_management',
                    'competition_award_reference' => $recipientReference,
                    'competition_edition_id' => $achievement['competition_edition_id'] ?? null,
                    'competition_category_id' => $achievement['category_id'] ?? null,
                    'competition_result_id' => $achievement['result_id'] ?? null,
                    'final_score' => $achievement['score'] ?? null,
                ],
            ], $actor);

            return ['created' => true, 'reference' => 'activity-achievement:'.$row->id];
        } catch (\Throwable $exception) {
            Log::warning('Competition achievement sync failed.', [
                'reference' => $recipientReference,
                'student_id' => $studentId,
                'error' => $exception->getMessage(),
            ]);

            return ['created' => false, 'reason' => 'Achievement provider rejected the record.'];
        }
    }
}
