<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\BadgeService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\GamificationService;
use App\Modules\LMS\Domain\Enums\GamificationEventType;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsBadge;
use App\Modules\LMS\Models\LmsBadgeAward;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Badge, poin XP, dan rentetan belajar (§26–§30).
 *
 * Siswa hanya melihat capaian miliknya; guru mengelola badge untuk course yang
 * menjadi tanggung jawabnya (§44).
 */
class LmsGamificationController extends LmsController
{
    public function __construct(
        private readonly GamificationService $gamification,
        private readonly BadgeService $badges,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/my/gamification */
    public function mine(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->successResponse([
            'enabled' => $this->gamification->enabled(),
            'summary' => $this->gamification->summaryFor((int) $actor->id),
            'streak' => $this->gamification->streakFor((int) $actor->id),
            'badges' => $this->gamification->badgesFor((int) $actor->id),
            'recent' => $this->gamification->recentFor((int) $actor->id, null, 15),
            'weights' => $this->gamification->weights(),
        ]);
    }

    /** GET /lms/courses/{course}/leaderboard */
    public function leaderboard(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);

        $this->authorizeLms(
            $this->access->canManage($model, $actor) || $model->enrollmentFor($actor->id) !== null,
            'Anda tidak memiliki akses ke papan peringkat course ini.',
        );

        return $this->successResponse([
            'enabled' => $this->gamification->leaderboardEnabled($model),
            'display' => config('lms.gamification.display', 'name'),
            'rows' => $this->gamification->leaderboardForCourse($model, (int) $this->perPage($request, 20), (int) $actor->id),
        ]);
    }

    /** GET /lms/courses/{course}/gamification */
    public function courseOverview(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeBadgeManage($model);

        return $this->successResponse([
            'enabled' => $this->gamification->enabled($model),
            'badges' => $this->badges->forCourse($model),
            'rule_types' => $this->badges->ruleTypes(),
            'event_types' => GamificationEventType::values(),
        ]);
    }

    /** GET /lms/badges */
    public function catalog(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.badge.view') || $actor->can('lms.manage'), 'Anda tidak berhak melihat daftar badge.');

        return $this->successResponse([
            'badges' => $this->badges->catalog($request->boolean('active_only')),
            'rule_types' => $this->badges->ruleTypes(),
        ]);
    }

    /** POST /lms/badges */
    public function storeBadge(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.badge.manage') || $actor->can('lms.manage'), 'Anda tidak berhak membuat badge.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:190'],
            'color' => ['nullable', 'string', 'max:20'],
            'points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'course_id' => ['nullable', 'integer'],
            'rules' => ['nullable', 'array'],
            'rules.*.rule_type' => ['required_with:rules', 'string'],
            'rules.*.threshold' => ['nullable', 'numeric', 'min:0'],
            'rules.*.activity_type' => ['nullable', 'string'],
        ]);

        return $this->createdResponse($this->badges->create($data, $actor), 'Badge dibuat.');
    }

    /** PUT /lms/badges/{badge} */
    public function updateBadge(Request $request, int $badge): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.badge.manage') || $actor->can('lms.manage'), 'Anda tidak berhak mengubah badge.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:190'],
            'color' => ['nullable', 'string', 'max:20'],
            'points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse($this->badges->update($this->badge($badge), $data, $actor), 'Badge diperbarui.');
    }

    /** PUT /lms/badges/{badge}/rules */
    public function syncRules(Request $request, int $badge): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.badge.manage') || $actor->can('lms.manage'), 'Anda tidak berhak mengubah aturan badge.');

        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.rule_type' => ['required', 'string'],
            'rules.*.threshold' => ['nullable', 'numeric', 'min:0'],
            'rules.*.activity_type' => ['nullable', 'string'],
            'rules.*.competency_id' => ['nullable', 'integer'],
            'rules.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->successResponse($this->badges->syncRules($this->badge($badge), $data['rules'], $actor), 'Aturan badge disimpan.');
    }

    /** DELETE /lms/badges/{badge} */
    public function destroyBadge(Request $request, int $badge): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.badge.manage') || $actor->can('lms.manage'), 'Anda tidak berhak menghapus badge.');

        $this->badges->delete($this->badge($badge), $actor);

        return $this->successResponse(null, 'Badge dihapus.');
    }

    /** POST /lms/badges/{badge}/award */
    public function award(Request $request, int $badge): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->badge($badge);

        $this->authorizeLms($actor->can('lms.badge.award') || $actor->can('lms.manage'), 'Anda tidak berhak memberi badge.');

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
            'course_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->successResponse(
            $this->badges->awardManual($model, $data['user_ids'], $data['reason'], $actor, $data['course_id'] ?? null),
            'Badge diberikan.',
        );
    }

    /** DELETE /lms/badge-awards/{award} */
    public function revoke(Request $request, int $award): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.badge.award') || $actor->can('lms.manage'), 'Anda tidak berhak mencabut badge.');

        $model = LmsBadgeAward::query()->find($award);
        abort_if($model === null, 404, 'Pemberian badge tidak ditemukan.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->badges->revoke($model, $actor, (string) $data['reason']);

        return $this->successResponse(null, 'Badge dicabut.');
    }

    /** POST /lms/courses/{course}/badges/evaluate */
    public function evaluate(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeBadgeManage($model);

        $data = $request->validate(['enrollment_id' => ['nullable', 'integer']]);

        if (isset($data['enrollment_id'])) {
            $enrollment = LmsEnrollment::query()->findOrFail((int) $data['enrollment_id']);

            return $this->successResponse($this->badges->evaluateEnrollment($enrollment, $actor), 'Evaluasi badge selesai.');
        }

        return $this->successResponse($this->badges->evaluateCourse($model, $actor), 'Evaluasi badge course selesai.');
    }

    private function course(int $id): LmsCourse
    {
        $course = LmsCourse::query()->find($id);
        abort_if($course === null, 404, 'Course tidak ditemukan.');

        return $course;
    }

    private function badge(int $id): LmsBadge
    {
        $badge = $this->badges->find($id);

        return $badge;
    }

    private function authorizeBadgeManage(LmsCourse $course): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            ($actor->can('lms.badge.manage') || $actor->can('lms.manage')) && $this->access->canManage($course, $actor),
            'Anda tidak berhak mengelola badge course ini.',
        );
    }
}
