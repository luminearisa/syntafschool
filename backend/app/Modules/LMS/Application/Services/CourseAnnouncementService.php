<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Infrastructure\Support\HtmlSanitizer;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseAnnouncement;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Notifications\LmsCourseAnnouncementNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Notification;

/** Pengumuman dalam course (§24). */
class CourseAnnouncementService
{
    use LogsLmsAudit;

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /** @return HasMany<LmsCourseAnnouncement, LmsCourse> */
    public function forCourse(LmsCourse $course, bool $onlyPublished = true): HasMany
    {
        return $course->announcements()
            ->when($onlyPublished, fn (Builder $q) => $q->published())
            ->ordered();
    }

    public function create(LmsCourse $course, array $data, LmsActor $actor): LmsCourseAnnouncement
    {
        $announcement = $course->announcements()->create([
            'title' => $data['title'],
            'content' => $this->sanitizer->clean($data['content']) ?? '',
            'pinned' => (bool) ($data['pinned'] ?? false),
            'published_at' => ($data['publish'] ?? true) ? ($data['published_at'] ?? now()) : null,
            'created_by' => $actor->id,
        ]);

        if ($announcement->published_at !== null) {
            $this->notifyParticipants($announcement, $course);
        }

        return $announcement;
    }

    public function update(LmsCourseAnnouncement $announcement, array $data): LmsCourseAnnouncement
    {
        $wasPublished = $announcement->published_at !== null;

        if (array_key_exists('content', $data)) {
            $data['content'] = $this->sanitizer->clean($data['content']) ?? '';
        }

        if (array_key_exists('publish', $data)) {
            $data['published_at'] = $data['publish'] ? ($announcement->published_at ?? now()) : null;
            unset($data['publish']);
        }

        $announcement->fill($data)->save();

        if (! $wasPublished && $announcement->published_at !== null) {
            $this->notifyParticipants($announcement, $announcement->course);
        }

        return $announcement->fresh();
    }

    public function togglePin(LmsCourseAnnouncement $announcement): LmsCourseAnnouncement
    {
        $announcement->forceFill(['pinned' => ! $announcement->pinned])->save();

        return $announcement->fresh();
    }

    public function delete(LmsCourseAnnouncement $announcement): void
    {
        $announcement->delete();
    }

    /**
     * Kirim pemberitahuan ke seluruh peserta aktif course (guru & siswa).
     * Wali murid melihat pengumuman di portal anaknya (§24) tanpa notifikasi
     * tersendiri supaya tidak ada ledakan notifikasi per anak.
     */
    public function notifyParticipants(LmsCourseAnnouncement $announcement, ?LmsCourse $course = null): int
    {
        $course ??= $announcement->course;

        if ($course === null) {
            return 0;
        }

        $payload = [
            'course_id' => (int) $course->id,
            'course_name' => (string) $course->name,
            'announcement_id' => (int) $announcement->id,
            'title' => (string) $announcement->title,
            'message' => $this->sanitizer->toText($announcement->content, 160),
            'url' => '/lms/courses/'.$course->id,
        ];

        $sent = 0;

        LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->with('user')
            ->chunkById(100, function ($enrollments) use ($payload, &$sent): void {
                $notifiables = $enrollments
                    ->pluck('user')
                    ->filter(fn ($user) => $user !== null && method_exists($user, 'notify'))
                    ->all();

                if ($notifiables !== []) {
                    Notification::send($notifiables, new LmsCourseAnnouncementNotification(
                        $payload,
                        (array) config('lms.notifications.announcement_channels', ['database']),
                    ));
                    $sent += count($notifiables);
                }
            });

        $this->audit('LMS_ANNOUNCEMENT_PUBLISHED', $announcement, ['recipients' => $sent]);

        return $sent;
    }
}
