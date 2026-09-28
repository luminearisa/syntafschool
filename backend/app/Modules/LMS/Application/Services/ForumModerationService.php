<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ForumPostStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsForum;
use App\Modules\LMS\Models\LmsForumDiscussion;
use App\Modules\LMS\Models\LmsForumPost;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Moderasi forum (§35).
 *
 * Guru dapat menyetujui, menyembunyikan, mengunci, menempel (pin), dan
 * menghapus kiriman; siswa hanya melihat kiriman berstatus VISIBLE.
 */
class ForumModerationService
{
    use LogsLmsAudit;

    public function approve(LmsActivity $activity, LmsForumPost|LmsForumDiscussion $item, LmsActor $actor): LmsForumPost|LmsForumDiscussion
    {
        $item->forceFill([
            'status' => ForumPostStatus::VISIBLE->value,
            'moderated_by' => $actor->id,
            'moderated_at' => now(),
        ])->save();

        if ($item instanceof LmsForumPost) {
            $discussion = $item->discussion;

            if ($discussion !== null) {
                $discussion->forceFill(['last_post_at' => now()])->save();
                $discussion->increment('posts_count');
            }
        }

        $this->audit('LMS_FORUM_APPROVED', $activity, ['item_id' => $item->id, 'type' => $item::class]);

        return $item->fresh();
    }

    public function hide(LmsActivity $activity, LmsForumPost|LmsForumDiscussion $item, LmsActor $actor, ?string $note = null): LmsForumPost|LmsForumDiscussion
    {
        $item->forceFill([
            'status' => ForumPostStatus::HIDDEN->value,
            'moderated_by' => $actor->id,
            'moderated_at' => now(),
        ]);

        if ($item instanceof LmsForumPost) {
            $item->moderation_note = $note;
        }

        $item->save();

        $this->audit('LMS_FORUM_HIDDEN', $activity, ['item_id' => $item->id, 'note' => $note]);

        return $item->fresh();
    }

    public function delete(LmsActivity $activity, LmsForumPost|LmsForumDiscussion $item, LmsActor $actor, ?string $note = null): void
    {
        $item->forceFill([
            'status' => ForumPostStatus::DELETED->value,
            'moderated_by' => $actor->id,
            'moderated_at' => now(),
        ])->save();

        $item->delete();

        $this->audit('LMS_FORUM_DELETED', $activity, ['item_id' => $item->id, 'note' => $note]);
    }

    public function togglePin(LmsActivity $activity, LmsForumDiscussion $discussion, LmsActor $actor): LmsForumDiscussion
    {
        $discussion->forceFill([
            'is_pinned' => ! $discussion->is_pinned,
            'moderated_by' => $actor->id,
            'moderated_at' => now(),
        ])->save();

        $this->audit($discussion->is_pinned ? 'LMS_FORUM_PINNED' : 'LMS_FORUM_UNPINNED', $activity, [
            'discussion_id' => $discussion->id,
        ]);

        return $discussion->fresh();
    }

    public function toggleLock(LmsActivity $activity, LmsForumDiscussion $discussion, LmsActor $actor): LmsForumDiscussion
    {
        $discussion->forceFill([
            'is_locked' => ! $discussion->is_locked,
            'moderated_by' => $actor->id,
            'moderated_at' => now(),
        ])->save();

        $this->audit($discussion->is_locked ? 'LMS_FORUM_LOCKED' : 'LMS_FORUM_UNLOCKED', $activity, [
            'discussion_id' => $discussion->id,
        ]);

        return $discussion->fresh();
    }

    /**
     * Antrian moderasi: kiriman & diskusi berstatus PENDING (§35).
     *
     * @return array{posts: Collection<int, LmsForumPost>, discussions: Collection<int, LmsForumDiscussion>}
     */
    public function queue(LmsForum $forum): array
    {
        $posts = LmsForumPost::query()
            ->whereHas('discussion', fn ($query) => $query->where('forum_id', $forum->id))
            ->where('status', ForumPostStatus::PENDING->value)
            ->with(['user', 'discussion'])
            ->orderBy('created_at')
            ->get();

        $discussions = LmsForumDiscussion::query()
            ->where('forum_id', $forum->id)
            ->where('status', ForumPostStatus::PENDING->value)
            ->with('user')
            ->orderBy('created_at')
            ->get();

        return ['posts' => $posts, 'discussions' => $discussions];
    }

    public function assertModeratable(LmsActivity $activity, LmsActor $actor, CourseAccessService $access): void
    {
        $course = $activity->course;

        if ($course !== null && ! $access->canGrade($course, $actor) && ! $actor->can('lms.forum.moderate')) {
            throw ValidationException::withMessages([
                'forum' => 'Anda tidak berhak memoderasi forum ini.',
            ]);
        }
    }
}
