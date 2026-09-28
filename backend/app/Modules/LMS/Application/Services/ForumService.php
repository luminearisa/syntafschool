<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\ActivityConfiguratorInterface;
use App\Modules\LMS\Domain\Enums\ForumType;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsForum;
use App\Modules\LMS\Models\LmsForumDiscussion;
use App\Modules\LMS\Models\LmsForumPost;
use App\Modules\LMS\Models\LmsForumSubscription;

/**
 * Forum diskusi (§32, §36).
 *
 * Moderasi, langganan, dan penghitungan partisipasi diletakkan di sini;
 * penulisan kiriman ada di `DiscussionService`, moderasi di
 * `ForumModerationService`.
 */
class ForumService implements ActivityConfiguratorInterface
{
    use LogsLmsAudit;

    public function createForActivity(LmsActivity $activity, array $data): void
    {
        LmsForum::create([
            'activity_id' => $activity->id,
            'forum_type' => $data['forum_type'] ?? ForumType::GENERAL->value,
            'subscription_enabled' => $data['subscription_enabled'] ?? true,
            'moderation_enabled' => $data['moderation_enabled'] ?? false,
            'grading_enabled' => $data['grading_enabled'] ?? false,
            'required_posts' => $data['required_posts'] ?? 1,
            'max_depth' => $data['max_depth'] ?? 3,
            'grading_max_posts' => $data['grading_max_posts'] ?? null,
            'settings' => $data['forum_settings'] ?? null,
        ]);
    }

    public function updateForActivity(LmsActivity $activity, array $data): void
    {
        $forum = $this->config($activity);

        if ($forum === null) {
            return;
        }

        $forum->fill(collect($data)->only([
            'forum_type', 'subscription_enabled', 'moderation_enabled', 'grading_enabled',
            'required_posts', 'max_depth', 'grading_max_posts',
        ])->all());

        if (array_key_exists('forum_settings', $data)) {
            $forum->settings = $data['forum_settings'];
        }

        $forum->save();
    }

    public function duplicateForActivity(LmsActivity $source, LmsActivity $copy): void
    {
        $forum = $this->config($source);

        if ($forum === null) {
            return;
        }

        // Diskusi tidak disalin (§44).
        $this->createForActivity($copy, array_merge($forum->toArray(), ['forum_settings' => $forum->settings]));
    }

    public function config(LmsActivity $activity): ?LmsForum
    {
        return $activity->forum ?? $activity->load('forum')->forum;
    }

    public function ensureConfig(LmsActivity $activity): LmsForum
    {
        $forum = $this->config($activity);

        if ($forum === null) {
            $this->createForActivity($activity, []);
            $forum = $this->config($activity->fresh('forum'));
        }

        return $forum;
    }

    /**
     * Tampilan forum untuk siswa/guru (§32–§33).
     *
     * @return array<string, mixed>
     */
    public function view(LmsActivity $activity, ?LmsEnrollment $enrollment, bool $includePending = false): array
    {
        $forum = $this->ensureConfig($activity);
        $userId = $enrollment?->user_id;

        $discussions = $forum->discussions()
            ->when(! $includePending, fn ($query) => $query->visible())
            ->withCount(['posts' => fn ($query) => $includePending ? $query : $query->visible()])
            ->with('user')
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_post_at')
            ->orderByDesc('id')
            ->get();

        $subscription = $userId === null
            ? null
            : LmsForumSubscription::query()->where('forum_id', $forum->id)->where('user_id', $userId)->first();

        return [
            'forum' => $this->configPayload($forum),
            'discussions' => $discussions->map(fn (LmsForumDiscussion $discussion) => [
                'id' => (int) $discussion->id,
                'title' => (string) $discussion->title,
                'content' => $discussion->content,
                'author' => $discussion->user?->name,
                'author_id' => (int) $discussion->user_id,
                'is_pinned' => (bool) $discussion->is_pinned,
                'is_locked' => (bool) $discussion->is_locked,
                'status' => $discussion->status?->value,
                'posts_count' => (int) $discussion->posts_count,
                'last_post_at' => $discussion->last_post_at?->toIso8601String(),
                'created_at' => $discussion->created_at?->toIso8601String(),
            ])->all(),
            'subscription' => $subscription === null ? null : [
                'subscribed' => true,
                'notify_new_discussion' => (bool) $subscription->notify_new_discussion,
                'notify_new_post' => (bool) $subscription->notify_new_post,
            ],
            'participation' => $userId === null ? null : $this->participation($forum, $userId),
            'can_start_discussion' => $forum->forum_type?->allowsStudentDiscussion() ?? true,
        ];
    }

    /** @return array<string, mixed> */
    public function configPayload(LmsForum $forum): array
    {
        return [
            'id' => (int) $forum->id,
            'forum_type' => $forum->forum_type?->value,
            'forum_type_label' => $forum->forum_type?->label(),
            'subscription_enabled' => (bool) $forum->subscription_enabled,
            'moderation_enabled' => (bool) $forum->moderation_enabled,
            'grading_enabled' => (bool) $forum->grading_enabled,
            'required_posts' => (int) $forum->required_posts,
            'max_depth' => $forum->depthLimit(),
            'grading_max_posts' => $forum->grading_max_posts === null ? null : (int) $forum->grading_max_posts,
            'settings' => $forum->settings,
        ];
    }

    /** Jumlah kiriman siswa pada forum (bahan penyelesaian §40 & penilaian). */
    public function participation(LmsForum $forum, int $userId): array
    {
        $posts = LmsForumPost::query()
            ->whereHas('discussion', fn ($query) => $query->where('forum_id', $forum->id))
            ->where('user_id', $userId)
            ->whereIn('status', ['VISIBLE', 'PENDING'])
            ->count();

        $discussions = LmsForumDiscussion::query()
            ->where('forum_id', $forum->id)
            ->where('user_id', $userId)
            ->count();

        // Kontribusi = diskusi yang dibuka + balasan yang ditulis. Keduanya
        // dihitung sebagai partisipasi, seperti kebiasaan forum pembelajaran.
        $contributions = $posts + $discussions;

        return [
            'posts' => $posts,
            'discussions' => $discussions,
            'contributions' => $contributions,
            'required_posts' => (int) $forum->required_posts,
            'meets_requirement' => $contributions >= (int) $forum->required_posts,
        ];
    }

    /** Statistik forum untuk guru (§50). */
    public function statistics(LmsActivity $activity): array
    {
        $forum = $this->ensureConfig($activity);

        $posts = LmsForumPost::query()->whereHas('discussion', fn ($query) => $query->where('forum_id', $forum->id));
        $discussions = LmsForumDiscussion::query()->where('forum_id', $forum->id);

        $participants = LmsEnrollment::query()
            ->where('course_id', $activity->course_id)
            ->where('role', 'STUDENT')
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->count();

        $participating = (clone $posts)->distinct()->count('user_id');

        return [
            'discussions' => (clone $discussions)->count(),
            'posts' => (clone $posts)->count(),
            'pending_moderation' => (clone $posts)->where('status', 'PENDING')->count()
                + (clone $discussions)->where('status', 'PENDING')->count(),
            'participants' => $participants,
            'participating' => $participating,
            'participation_rate' => $participants > 0 ? round($participating / $participants * 100, 2) : null,
            'last_post_at' => (clone $posts)->max('created_at'),
        ];
    }

    /** Jumlah kiriman yang menunggu moderasi (§50). */
    public function pendingModerationCount(LmsActivity $activity): int
    {
        $forum = $this->ensureConfig($activity);

        $posts = LmsForumPost::query()
            ->whereHas('discussion', fn ($query) => $query->where('forum_id', $forum->id))
            ->where('status', 'PENDING')
            ->count();

        $discussions = LmsForumDiscussion::query()
            ->where('forum_id', $forum->id)
            ->where('status', 'PENDING')
            ->count();

        return $posts + $discussions;
    }

    /** Langganan notifikasi forum (§36). */
    public function subscribe(LmsForum $forum, int $userId, array $options = []): LmsForumSubscription
    {
        return LmsForumSubscription::updateOrCreate(
            ['forum_id' => $forum->id, 'user_id' => $userId],
            [
                'notify_new_discussion' => $options['notify_new_discussion'] ?? true,
                'notify_new_post' => $options['notify_new_post'] ?? true,
            ],
        );
    }

    public function unsubscribe(LmsForum $forum, int $userId): void
    {
        LmsForumSubscription::query()->where('forum_id', $forum->id)->where('user_id', $userId)->delete();
    }

    public function isSubscribed(LmsForum $forum, int $userId): bool
    {
        return LmsForumSubscription::query()->where('forum_id', $forum->id)->where('user_id', $userId)->exists();
    }
}
