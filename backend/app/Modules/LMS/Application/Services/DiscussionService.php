<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ForumPostStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Infrastructure\Support\HtmlSanitizer;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsForum;
use App\Modules\LMS\Models\LmsForumDiscussion;
use App\Modules\LMS\Models\LmsForumPost;
use App\Modules\LMS\Models\LmsForumSubscription;
use App\Modules\LMS\Notifications\LmsForumPostNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Diskusi & balasan forum (§33–§34, §36).
 *
 * Kiriman siswa tampil segera bila moderasi mati, atau menunggu persetujuan
 * guru bila moderasi aktif. Sarang balasan dibatasi `max_depth` supaya tidak
 * menjadi tak berujung (§34).
 */
class DiscussionService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly ForumService $forums,
        private readonly ActivityCompletionService $completion,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * Buka diskusi baru (§33).
     *
     * @param  array<string, mixed>  $data
     */
    public function createDiscussion(
        LmsActivity $activity,
        LmsForum $forum,
        LmsActor $actor,
        array $data,
        ?LmsEnrollment $enrollment = null,
    ): LmsForumDiscussion {
        if (! $forum->forum_type?->allowsStudentDiscussion() && ! $actor->can('lms.manage') && ! $actor->isTeacher()) {
            throw ValidationException::withMessages([
                'forum' => 'Forum ini hanya dapat dibuka oleh guru.',
            ]);
        }

        $pending = $forum->requiresModeration() && ! $this->isStaff($actor);

        $discussion = $forum->discussions()->create([
            'user_id' => $actor->id,
            'student_id' => $actor->studentId,
            'title' => $data['title'],
            'content' => $this->sanitizer->clean($data['content']) ?? '',
            'status' => $pending ? ForumPostStatus::PENDING->value : ForumPostStatus::VISIBLE->value,
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
            'last_post_at' => now(),
        ]);

        if (! $pending) {
            $this->notifySubscribers($forum, $discussion, null, $actor);
        }

        $this->audit('LMS_FORUM_DISCUSSION_CREATED', $activity, [
            'discussion_id' => $discussion->id,
            'pending_moderation' => $pending,
        ]);

        return $discussion;
    }

    /**
     * Balas sebuah diskusi (§34).
     *
     * @param  array<string, mixed>  $data
     */
    public function reply(
        LmsActivity $activity,
        LmsForumDiscussion $discussion,
        LmsActor $actor,
        array $data,
        ?LmsEnrollment $enrollment = null,
    ): LmsForumPost {
        $forum = $discussion->forum;

        if ($discussion->isLocked() && ! $this->isStaff($actor)) {
            throw ValidationException::withMessages([
                'discussion' => 'Diskusi ini sudah dikunci oleh guru.',
            ]);
        }

        $parent = null;

        if (! empty($data['parent_id'])) {
            $parent = LmsForumPost::query()
                ->where('discussion_id', $discussion->id)
                ->findOrFail((int) $data['parent_id']);
        }

        $depth = $parent === null ? 1 : (int) $parent->depth + 1;

        if ($depth > ($forum?->depthLimit() ?? 3) && ! $this->isStaff($actor)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Balasan sudah terlalu dalam. Balas pada kiriman induknya.',
            ]);
        }

        $pending = ($forum?->requiresModeration() ?? false) && ! $this->isStaff($actor);

        $post = $discussion->posts()->create([
            'user_id' => $actor->id,
            'student_id' => $actor->studentId,
            'parent_id' => $parent?->id,
            'content' => $this->sanitizer->clean($data['content']) ?? '',
            'depth' => $depth,
            'status' => $pending ? ForumPostStatus::PENDING->value : ForumPostStatus::VISIBLE->value,
            'is_teacher_post' => $this->isStaff($actor),
        ]);

        if (! $pending) {
            $discussion->forceFill(['last_post_at' => now()])->save();
            $discussion->increment('posts_count');
            $this->notifySubscribers($forum, $discussion, $post, $actor);
        }

        if ($forum !== null && $enrollment !== null) {
            $this->evaluateCompletion($activity, $forum, $enrollment, $actor);
        }

        $this->audit('LMS_FORUM_POST_CREATED', $activity, [
            'discussion_id' => $discussion->id,
            'post_id' => $post->id,
            'pending_moderation' => $pending,
        ]);

        return $post->fresh();
    }

    /** Ubah kiriman sendiri (§33). */
    public function updatePost(LmsForumPost $post, LmsActor $actor, array $data): LmsForumPost
    {
        if ((int) $post->user_id !== $actor->id) {
            throw ValidationException::withMessages(['post' => 'Anda hanya dapat mengubah kiriman sendiri.']);
        }

        $post->forceFill([
            'content' => $this->sanitizer->clean($data['content']) ?? '',
            'edited_at' => now(),
        ])->save();

        return $post->fresh();
    }

    /** Hapus kiriman sendiri (soft delete, riwayat diskusi tetap utuh). */
    public function deleteOwnPost(LmsForumPost $post, LmsActor $actor): void
    {
        if ((int) $post->user_id !== $actor->id && ! $actor->can('lms.forum.moderate')) {
            throw ValidationException::withMessages(['post' => 'Anda tidak berhak menghapus kiriman ini.']);
        }

        $discussion = $post->discussion;

        if ($discussion !== null && $discussion->isLocked() && (int) $post->user_id === $actor->id) {
            throw ValidationException::withMessages(['post' => 'Diskusi sudah dikunci.']);
        }

        $post->delete();
    }

    /**
     * Pohon kiriman (nested reply, §34) untuk satu diskusi.
     *
     * @return array<string, mixed>
     */
    public function discussionView(LmsForumDiscussion $discussion, bool $includePending = false, ?int $viewerId = null): array
    {
        $posts = $discussion->posts()
            ->when(! $includePending, fn ($query) => $query->visible())
            ->with('user')
            ->orderBy('created_at')
            ->get();

        $grouped = $posts->groupBy(fn (LmsForumPost $post) => (int) ($post->parent_id ?? 0));

        return [
            'discussion' => [
                'id' => (int) $discussion->id,
                'title' => (string) $discussion->title,
                'content' => $discussion->content,
                'author' => $discussion->user?->name,
                'author_id' => (int) $discussion->user_id,
                'is_pinned' => (bool) $discussion->is_pinned,
                'is_locked' => (bool) $discussion->is_locked,
                'status' => $discussion->status?->value,
                'created_at' => $discussion->created_at?->toIso8601String(),
                'posts_count' => $posts->count(),
            ],
            'posts' => $this->nest($grouped, 0, $viewerId, $includePending),
        ];
    }

    /**
     * Susun balasan bertingkat.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, LmsForumPost>>  $grouped
     * @return list<array<string, mixed>>
     */
    protected function nest($grouped, int $parentId, ?int $viewerId, bool $includePending): array
    {
        return $grouped->get($parentId, collect())
            ->map(fn (LmsForumPost $post) => [
                'id' => (int) $post->id,
                'parent_id' => $post->parent_id === null ? null : (int) $post->parent_id,
                'depth' => (int) $post->depth,
                'content' => $post->content,
                'author' => $post->user?->name,
                'author_id' => (int) $post->user_id,
                'is_teacher_post' => (bool) $post->is_teacher_post,
                'status' => $post->status?->value,
                'is_mine' => $viewerId !== null && (int) $post->user_id === $viewerId,
                'can_edit' => $viewerId !== null && (int) $post->user_id === $viewerId,
                'created_at' => $post->created_at?->toIso8601String(),
                'edited_at' => $post->edited_at?->toIso8601String(),
                'replies' => $this->nest($grouped, (int) $post->id, $viewerId, $includePending),
            ])
            ->values()
            ->all();
    }

    /** Penyelesaian forum: jumlah kiriman memenuhi syarat (§40). */
    public function evaluateCompletion(LmsActivity $activity, LmsForum $forum, LmsEnrollment $enrollment, LmsActor $actor): void
    {
        $participation = $this->forums->participation($forum, $enrollment->user_id);

        if (! $participation['meets_requirement']) {
            return;
        }

        $grade = null;

        if ($forum->grading_enabled) {
            $target = $forum->grading_max_posts ?? $forum->required_posts;
            $grade = min(100, round($participation['posts'] / max(1, $target) * 100, 2));
        }

        $this->completion->complete($activity, $enrollment, $grade, 'forum_posted', $actor->id, [
            'posts' => $participation['posts'],
        ]);
    }

    /** Kabari pelanggan forum (§36) memakai kanal notifikasi yang sudah ada. */
    protected function notifySubscribers(?LmsForum $forum, LmsForumDiscussion $discussion, ?LmsForumPost $post, LmsActor $actor): void
    {
        if ($forum === null || ! $forum->subscription_enabled) {
            return;
        }

        $subscriptions = LmsForumSubscription::query()
            ->where('forum_id', $forum->id)
            ->when($post === null, fn ($query) => $query->where('notify_new_discussion', true))
            ->when($post !== null, fn ($query) => $query->where('notify_new_post', true))
            ->with('user')
            ->get();

        $recipients = $subscriptions
            ->pluck('user')
            ->filter(fn ($user) => $user !== null && (int) $user->getKey() !== $actor->id && method_exists($user, 'notify'))
            ->all();

        if ($recipients === []) {
            return;
        }

        Notification::send($recipients, new LmsForumPostNotification([
            'forum_id' => (int) $forum->id,
            'activity_id' => (int) $forum->activity_id,
            'discussion_id' => (int) $discussion->id,
            'discussion_title' => (string) $discussion->title,
            'post_id' => $post?->id,
            'author' => (string) $actor->name,
            'message' => $this->sanitizer->toText($post?->content ?? $discussion->content, 160),
            'url' => '/lms/activities/'.$forum->activity_id,
        ], (array) config('lms.notifications.forum_channels', ['database'])));
    }

    protected function isStaff(LmsActor $actor): bool
    {
        return $actor->isTeacher() || $actor->can('lms.manage');
    }
}
