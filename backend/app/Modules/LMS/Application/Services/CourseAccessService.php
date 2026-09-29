<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Domain\Support\AccessDecision;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;

/**
 * Satu-satunya pintu keputusan akses (§20):
 *
 *   Authenticate → Check Enrollment → Check Visibility → Check Availability
 *   → Check Access Rule → Grant Access
 *
 * Controller WAJIB melewati service ini sebelum menyajikan isi materi maupun
 * sebelum menandai item tuntas — termasuk untuk guru, karena guru pun hanya
 * boleh mengubah course yang menjadi tanggung jawabnya (§35).
 */
class CourseAccessService
{
    public function __construct(
        private readonly CourseCompletionService $completions,
        private readonly StudentProviderInterface $students,
        private readonly DripContentService $drip,
        private readonly LearningPathService $learningPaths,
        private readonly CoursePrerequisiteService $prerequisites,
    ) {}

    public function decide(LmsCourse $course, ?LmsActor $actor, ?LmsLearningItem $item = null, ?LmsCourseSection $section = null): AccessDecision
    {
        $section ??= $item?->section;

        // 1) Guest / publik.
        if ($actor === null) {
            return $this->decideForGuest($course, $item);
        }

        // 2) Pengelola (admin atau guru pemilik course) selalu boleh.
        if ($this->canManage($course, $actor)) {
            return AccessDecision::allow();
        }

        // 3) Wali murid: boleh melihat ringkasan belajar anaknya, bukan mengerjakan.
        if ($actor->isParent() && $this->parentWatchesCourse($course, $actor)) {
            return $this->decideAvailability($course, $section, $item, AccessDecision::allow(), readOnly: true);
        }

        $enrollment = $course->enrollmentFor($actor->id);

        // 4) Keanggotaan course.
        if ($enrollment === null) {
            if ($course->visibility?->isPubliclyVisible() && $course->isPublished()) {
                return $this->decideForGuest($course, $item);
            }

            return AccessDecision::deny('not_enrolled', 'Anda belum terdaftar pada course ini.');
        }

        if (! $enrollment->status?->grantsAccess()) {
            return AccessDecision::deny(
                'enrollment_'.$enrollment->status?->value,
                match ($enrollment->status) {
                    EnrollmentStatus::SUSPENDED => 'Akses Anda ditangguhkan. Hubungi guru atau admin LMS.',
                    EnrollmentStatus::PENDING => 'Pendaftaran Anda masih menunggu persetujuan.',
                    EnrollmentStatus::CANCELLED => 'Keikutsertaan Anda pada course ini sudah dibatalkan.',
                    default => 'Anda tidak memiliki akses ke course ini.',
                },
            );
        }

        // 5) Status terbit course tetap berlaku bagi peserta baru.
        if (! $course->isPublished()) {
            return AccessDecision::deny('not_published', 'Course ini belum diterbitkan.');
        }

        // 6) Prasyarat antar-course (§18). Guru/admin sudah dilewatkan di atas,
        //    dan prasyarat yang belum tuntas tidak menghapus data belajar apa pun —
        //    siswa tetap dapat melihat ringkasannya, hanya belum bisa masuk.
        if (! $this->prerequisites->statusFor($course, $actor)['satisfied']) {
            $status = $this->prerequisites->statusFor($course, $actor);
            $first = $status['missing'][0] ?? null;

            return AccessDecision::deny(
                'course_prerequisite',
                $first === null
                    ? 'Course prasyarat belum tuntas.'
                    : 'Selesaikan dulu course "'.$first['name'].'" sebelum mengikuti course ini.',
                null,
                $first['course_id'] ?? null,
            );
        }

        return $this->decideAvailability($course, $section, $item, AccessDecision::allow(), readOnly: false, enrollment: $enrollment);
    }

    /** Guru/admin yang bertanggung jawab atas course (atau pemegang `lms.manage`). */
    public function canManage(LmsCourse $course, LmsActor $actor): bool
    {
        if ($actor->can('lms.manage')) {
            return true;
        }

        if ($course->created_by !== null && (int) $course->created_by === $actor->id) {
            return true;
        }

        if ($course->teacher_id !== null && $actor->employeeId !== null && (int) $course->teacher_id === $actor->employeeId) {
            return true;
        }

        return $course->teachers()->where('user_id', $actor->id)->exists();
    }

    /** Boleh mengubah isi materi? (kelola + bukan sekadar wali). */
    public function canEditContent(LmsCourse $course, LmsActor $actor): bool
    {
        if (! $actor->canAny(['lms.content.create', 'lms.content.update', 'lms.content.delete'])) {
            return false;
        }

        return $this->canManage($course, $actor);
    }

    public function canGrade(LmsCourse $course, LmsActor $actor): bool
    {
        return $this->canManage($course, $actor) && $actor->canAny(['lms.course.update', 'lms.enrollment.update']);
    }

    /** Apakah pelaku menjadi pengajar (bukan peserta) di course ini? */
    public function isTeacherOf(LmsCourse $course, LmsActor $actor): bool
    {
        $enrollment = $course->enrollmentFor($actor->id);

        return $enrollment !== null
            && in_array($enrollment->role, [EnrollmentRole::TEACHER, EnrollmentRole::ASSISTANT], true);
    }

    /** Wali yang memantau course tempat anaknya terdaftar (§28). */
    public function parentWatchesCourse(LmsCourse $course, LmsActor $actor): bool
    {
        if (! $actor->isParent()) {
            return false;
        }

        foreach ($this->students->childrenOf($actor) as $child) {
            if ($child->userId === null) {
                continue;
            }

            if ($course->enrollments()->where('user_id', $child->userId)->exists()) {
                return true;
            }
        }

        return false;
    }

    protected function decideForGuest(LmsCourse $course, ?LmsLearningItem $item): AccessDecision
    {
        if (! $course->visibility?->isPubliclyVisible()) {
            return AccessDecision::deny('course_not_public', 'Course ini bukan course publik.');
        }

        if (! $course->isPublished()) {
            return AccessDecision::deny('not_published', 'Course ini belum diterbitkan.');
        }

        if ($item === null) {
            return AccessDecision::allow();
        }

        if (! config('lms.guest.preview_items', true) || ! $item->is_preview) {
            return AccessDecision::deny('enrollment_required', 'Materi ini hanya untuk peserta terdaftar.');
        }

        return AccessDecision::allow();
    }

    protected function decideAvailability(
        LmsCourse $course,
        ?LmsCourseSection $section,
        ?LmsLearningItem $item,
        AccessDecision $decision,
        bool $readOnly,
        ?LmsEnrollment $enrollment = null,
    ): AccessDecision {
        $now = now();

        if ($course->start_date !== null && $course->start_date->isFuture()) {
            return AccessDecision::deny('course_not_started', 'Course dimulai pada '.$course->start_date->format('d M Y').'.');
        }

        if ($course->end_date !== null && $course->end_date->isPast()) {
            return AccessDecision::deny('course_ended', 'Course sudah berakhir pada '.$course->end_date->format('d M Y').'.');
        }

        if ($section !== null) {
            if ($section->visibility === ContentVisibility::HIDDEN && $enrollment !== null) {
                return AccessDecision::deny('section_hidden', 'Bagian ini sedang disembunyikan guru.');
            }

            $sectionDecision = $this->checkWindow(
                $section->available_from,
                $section->available_until,
                'Bagian ini',
            );

            if (! $sectionDecision->allowed) {
                return $sectionDecision;
            }

            // Phase 4 (§4): pelepasan bertahap pada tingkat bagian.
            $drip = $this->drip->evaluate($course, $enrollment, $section, null);

            if (! $drip->allowed) {
                return $drip;
            }

            // Phase 4 (§2): learning path berurutan mengunci modul berikutnya.
            if ($enrollment !== null && config('lms.learning_path.enforce_sequential', true)) {
                $found = $this->learningPaths->moduleForSection($course, $section);

                if ($found !== null) {
                    $gate = $this->learningPaths->gate($found['path'], $found['module'], $course, $enrollment);

                    if (! $gate->allowed) {
                        return $gate;
                    }
                }
            }
        }

        if ($item !== null) {
            if (! $item->isVisible() && $enrollment !== null) {
                return AccessDecision::deny('item_hidden', 'Materi ini sedang disembunyikan guru.');
            }

            $rules = $item->availabilityRules();
            $windowDecision = $this->checkWindow(
                $rules['available_from'] ?? null,
                $rules['available_until'] ?? null,
                'Materi ini',
            );

            if (! $windowDecision->allowed) {
                return $windowDecision;
            }

            // Phase 4 (§4): drip pada tingkat materi.
            if ($readOnly || $enrollment === null) {
                return $decision;
            }

            $itemDrip = $this->drip->evaluate($course, $enrollment, $section, $item);

            if (! $itemDrip->allowed) {
                return $itemDrip;
            }

            // Prasyarat materi & aktivitas (§3, §21) — satu mesin: completion.
            $prerequisite = $this->completions->satisfiesPrerequisites($enrollment, $item);

            if (! $prerequisite->allowed) {
                return $prerequisite;
            }
        }

        return $decision;
    }

    protected function checkWindow(mixed $from, mixed $until, string $label): AccessDecision
    {
        $now = now();

        if ($from !== null && $now->lt(\Illuminate\Support\Carbon::parse($from))) {
            return AccessDecision::deny('not_yet_available', $label.' dibuka pada '.\Illuminate\Support\Carbon::parse($from)->format('d M Y H:i').'.');
        }

        if ($until !== null && $now->gt(\Illuminate\Support\Carbon::parse($until))) {
            return AccessDecision::deny('availability_ended', $label.' sudah ditutup pada '.\Illuminate\Support\Carbon::parse($until)->format('d M Y H:i').'.');
        }

        return AccessDecision::allow();
    }
}
