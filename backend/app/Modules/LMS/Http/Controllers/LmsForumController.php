<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\DiscussionService;
use App\Modules\LMS\Application\Services\ForumModerationService;
use App\Modules\LMS\Application\Services\ForumService;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsForum;
use App\Modules\LMS\Models\LmsForumDiscussion;
use App\Modules\LMS\Models\LmsForumPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Forum: tampilan, diskusi, balasan, langganan, dan moderasi (§32–§36, §53).
 */
class LmsForumController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly ForumService $forums,
        private readonly DiscussionService $discussions,
        private readonly ForumModerationService $moderation,
        private readonly ActivityAvailabilityService $availability,
        private readonly CourseAccessService $access,
    ) {}

    /** Tampilan forum (daftar diskusi) untuk siswa/guru. */
    public function show(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->actor($request);
        $course = $model->course;
        $enrollment = $course?->enrollmentFor($actor->id);

        $isStaff = $course !== null && $this->access->canGrade($course, $actor);
        $state = $this->availability->studentState($model, $enrollment, $isStaff);

        if (! $isStaff && $state['locked']) {
            return $this->successResponse(['locked' => true, 'state' => $state], $state['reason_label'] ?? 'Aktivitas terkunci');
        }

        return $this->successResponse(
            $this->forums->view($model, $enrollment, includePending: $isStaff) + [
                'state' => $state,
                'locked' => ! $isStaff && $state['locked'],
                'can_moderate' => $isStaff,
            ],
            'Forum berhasil diambil',
        );
    }

    /** Buka diskusi baru (§33). */
    public function storeDiscussion(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireParticipant($request, $model);

        $data = $this->validatePayload($request, [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $forum = $this->forums->ensureConfig($model);
        $discussion = $this->discussions->createDiscussion($model, $forum, $actor, $data, $enrollment);

        return $this->createdResponse([
            'id' => (int) $discussion->id,
            'status' => $discussion->status?->value,
            'status_label' => $discussion->status?->label(),
        ], $discussion->status?->value === 'PENDING'
            ? 'Diskusi terkirim dan menunggu persetujuan guru.'
            : 'Diskusi berhasil dibuat');
    }

    /** Detail diskusi dengan balasan bertingkat (§34). */
    public function showDiscussion(Request $request, LmsForumDiscussion $discussion): JsonResponse
    {
        $actor = $this->actor($request);
        $activity = $discussion->forum?->activity;
        $course = $activity?->course;

        $isStaff = $course !== null && $this->access->canGrade($course, $actor);
        $isMember = $course?->enrollmentFor($actor->id) !== null;

        $this->authorizeLms($isStaff || $isMember || ($actor->isParent() && $this->access->parentWatchesCourse($course, $actor)));

        return $this->successResponse(
            $this->discussions->discussionView($discussion, includePending: $isStaff, viewerId: $actor->id),
            'Diskusi berhasil diambil',
        );
    }

    /** Balas diskusi (§34). */
    public function storePost(Request $request, LmsForumDiscussion $discussion): JsonResponse
    {
        $activity = $discussion->forum?->activity;
        [$actor, $enrollment] = $this->requireParticipant($request, $activity);

        $data = $this->validatePayload($request, [
            'content' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:lms_forum_posts,id'],
        ]);

        $post = $this->discussions->reply($activity, $discussion, $actor, $data, $enrollment);

        return $this->createdResponse([
            'id' => (int) $post->id,
            'status' => $post->status?->value,
            'depth' => (int) $post->depth,
        ], $post->status?->value === 'PENDING'
            ? 'Balasan terkirim dan menunggu moderasi guru.'
            : 'Balasan berhasil dikirim');
    }

    /** Ubah kiriman sendiri (§33). */
    public function updatePost(Request $request, LmsForumPost $post): JsonResponse
    {
        $data = $this->validatePayload($request, ['content' => ['required', 'string']]);

        return $this->successResponse([
            'id' => (int) $this->discussions->updatePost($post, $this->actor($request), $data)->id,
        ], 'Kiriman berhasil diperbarui');
    }

    /** Hapus kiriman sendiri (soft delete). */
    public function destroyPost(Request $request, LmsForumPost $post): JsonResponse
    {
        $this->discussions->deleteOwnPost($post, $this->actor($request));

        return $this->successResponse(null, 'Kiriman berhasil dihapus');
    }

    /** Langganan notifikasi forum (§36). */
    public function subscribe(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->actor($request);
        $forum = $this->forums->ensureConfig($model);

        $data = $this->validatePayload($request, [
            'notify_new_discussion' => ['nullable', 'boolean'],
            'notify_new_post' => ['nullable', 'boolean'],
        ]);

        $subscription = $this->forums->subscribe($forum, $actor->id, $data);

        return $this->successResponse([
            'subscribed' => true,
            'notify_new_discussion' => (bool) $subscription->notify_new_discussion,
            'notify_new_post' => (bool) $subscription->notify_new_post,
        ], 'Langganan forum disimpan');
    }

    public function unsubscribe(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $forum = $this->forums->ensureConfig($model);

        $this->forums->unsubscribe($forum, $this->actor($request)->id);

        return $this->successResponse(['subscribed' => false], 'Langganan forum dihentikan');
    }

    // ------------------------------------------------------------------ moderasi (§35)

    public function moderationQueue(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        $forum = $this->forums->ensureConfig($model);
        $queue = $this->moderation->queue($forum);

        return $this->successResponse([
            'posts' => $queue['posts']->map(fn (LmsForumPost $post) => [
                'id' => (int) $post->id,
                'content' => $post->content,
                'author' => $post->user?->name,
                'discussion_id' => (int) $post->discussion_id,
                'discussion_title' => $post->discussion?->title,
                'created_at' => $post->created_at?->toIso8601String(),
            ])->all(),
            'discussions' => $queue['discussions']->map(fn (LmsForumDiscussion $discussion) => [
                'id' => (int) $discussion->id,
                'title' => (string) $discussion->title,
                'content' => $discussion->content,
                'author' => $discussion->user?->name,
                'created_at' => $discussion->created_at?->toIso8601String(),
            ])->all(),
        ], 'Antrean moderasi berhasil diambil');
    }

    public function approve(Request $request, string $type, int $id): JsonResponse
    {
        [$activity, $item] = $this->resolveModerationTarget($request, $type, $id);

        return $this->successResponse(
            ['status' => $this->moderation->approve($activity, $item, $this->actor($request))->status?->value],
            'Kiriman disetujui dan tampil',
        );
    }

    public function hide(Request $request, string $type, int $id): JsonResponse
    {
        [$activity, $item] = $this->resolveModerationTarget($request, $type, $id);

        $note = $request->input('note');

        return $this->successResponse(
            ['status' => $this->moderation->hide($activity, $item, $this->actor($request), $note)->status?->value],
            'Kiriman disembunyikan',
        );
    }

    public function destroyModerated(Request $request, string $type, int $id): JsonResponse
    {
        [$activity, $item] = $this->resolveModerationTarget($request, $type, $id);

        $this->moderation->delete($activity, $item, $this->actor($request), $request->input('note'));

        return $this->successResponse(null, 'Kiriman dihapus');
    }

    public function pin(Request $request, LmsForumDiscussion $discussion): JsonResponse
    {
        $activity = $discussion->forum?->activity;
        $this->authorizeGrade($request, $activity);

        $updated = $this->moderation->togglePin($activity, $discussion, $this->actor($request));

        return $this->successResponse(['is_pinned' => (bool) $updated->is_pinned], $updated->is_pinned ? 'Diskusi ditempel' : 'Tempelan dilepas');
    }

    public function lock(Request $request, LmsForumDiscussion $discussion): JsonResponse
    {
        $activity = $discussion->forum?->activity;
        $this->authorizeGrade($request, $activity);

        $updated = $this->moderation->toggleLock($activity, $discussion, $this->actor($request));

        return $this->successResponse(['is_locked' => (bool) $updated->is_locked], $updated->is_locked ? 'Diskusi dikunci' : 'Diskusi dibuka');
    }

    /** Statistik forum (guru). */
    public function statistics(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        return $this->successResponse($this->forums->statistics($model), 'Statistik forum berhasil diambil');
    }

    /** @return array{0: LmsActivity, 1: LmsForumPost|LmsForumDiscussion} */
    protected function resolveModerationTarget(Request $request, string $type, int $id): array
    {
        if ($type === 'post') {
            $post = LmsForumPost::query()->findOrFail($id);
            $activity = $post->discussion?->forum?->activity;
        } else {
            $post = LmsForumDiscussion::query()->findOrFail($id);
            $activity = $post->forum?->activity;
        }

        abort_if($activity === null, 404, 'Aktivitas forum tidak ditemukan.');

        $this->authorizeGrade($request, $activity);

        return [$activity, $post];
    }

    // ------------------------------------------------------------------ alias §53

    /** GET /lms/forums/{forum}. */
    public function showByForum(Request $request, LmsForum $forum): JsonResponse
    {
        return $this->show($request, (int) $forum->activity_id);
    }

    /** POST /lms/forums/{forum}/discussions. */
    public function storeDiscussionByForum(Request $request, LmsForum $forum): JsonResponse
    {
        return $this->storeDiscussion($request, (int) $forum->activity_id);
    }

    /** Ubah konfigurasi forum (tab Settings wizard) (§32). */
    public function updateConfig(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'forum_type' => ['sometimes', 'string', 'in:GENERAL,COURSE_ANNOUNCEMENT,Q_AND_A,STANDARD'],
            'subscription_enabled' => ['sometimes', 'boolean'],
            'moderation_enabled' => ['sometimes', 'boolean'],
            'grading_enabled' => ['sometimes', 'boolean'],
            'required_posts' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'max_depth' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'grading_max_posts' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->forums->updateForActivity($model, $data);

        return $this->successResponse(
            $this->forums->configPayload($this->forums->ensureConfig($model->fresh('forum'))),
            'Konfigurasi forum berhasil disimpan',
        );
    }
}
