<?php

namespace App\Modules\LMS\Http\Controllers\Concerns;

use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Http\Request;

/**
 * Helper bersama controller aktivitas Phase 2.
 *
 * Menyatukan cara mengambil aktivitas, memastikan hak kelola/penilaian, dan
 * memastikan keikutsertaan siswa — supaya aturan otorisasi tidak tersebar dan
 * tidak bisa "hilang" di salah satu endpoint (§54).
 */
trait ResolvesActivities
{
    protected function activity(int $id): LmsActivity
    {
        $activity = LmsActivity::query()
            ->with(['assignment', 'quiz', 'forum', 'lesson', 'choice', 'survey', 'course', 'learningItem'])
            ->find($id);

        abort_if($activity === null, 404, 'Aktivitas tidak ditemukan.');

        return $activity;
    }

    /** Guru pengampu / admin course. */
    protected function authorizeManage(Request $request, LmsActivity $activity): LmsActor
    {
        $actor = $this->actor($request);
        $course = $activity->course;

        $this->authorizeLms(
            $course !== null && (
                $actor->can('lms.manage')
                || $actor->can('lms.activity.update')
                || $this->access->canManage($course, $actor)
            ),
            'Anda tidak berhak mengelola aktivitas ini.',
        );

        return $actor;
    }

    /** Guru penilai (boleh menilai, belum tentu boleh mengubah konfigurasi). */
    protected function authorizeGrade(Request $request, LmsActivity $activity): LmsActor
    {
        $actor = $this->actor($request);
        $course = $activity->course;

        $this->authorizeLms(
            $course !== null && (
                $actor->can('lms.manage')
                || $this->access->canGrade($course, $actor)
            ),
            'Anda tidak berhak menilai aktivitas ini.',
        );

        return $actor;
    }

    /**
     * Siswa yang mengerjakan: harus terdaftar aktif pada course.
     *
     * Wali murid tidak pernah sampai ke sini — ia hanya memiliki izin `view`
     * sehingga endpoint pengerjaan menolaknya lebih dulu (§48, §54).
     */
    protected function requireEnrollment(Request $request, LmsActivity $activity): array
    {
        $actor = $this->actor($request);

        /** @var LmsEnrollment|null $enrollment */
        $enrollment = $activity->course?->enrollmentFor($actor->id);

        $this->authorizeLms(
            $enrollment !== null && $enrollment->status?->grantsAccess(),
            'Anda belum terdaftar aktif pada course ini.',
        );

        return [$actor, $enrollment];
    }

    /**
     * Peserta aktivitas sosial (forum): siswa wajib terdaftar, guru/admin
     * pengampu course boleh ikut berdiskusi tanpa enrollment.
     *
     * @return array{0: LmsActor, 1: LmsEnrollment|null, 2: bool}
     */
    protected function requireParticipant(Request $request, LmsActivity $activity): array
    {
        $actor = $this->actor($request);
        $course = $activity->course;
        $enrollment = $course?->enrollmentFor($actor->id);

        $isStaff = $course !== null && (
            $actor->can('lms.manage')
            || $this->access->canGrade($course, $actor)
            || $this->access->canManage($course, $actor)
        );

        if ($enrollment === null && ! $isStaff) {
            $this->authorizeLms(false, 'Anda belum terdaftar aktif pada course ini.');
        }

        if ($enrollment !== null) {
            $this->authorizeLms($enrollment->status?->grantsAccess(), 'Keikutsertaan Anda pada course ini tidak aktif.');
        }

        return [$actor, $enrollment, $isStaff];
    }

    /** Wali murid yang memantau anaknya (§48). */
    protected function authorizeParentWatch(Request $request, LmsActivity $activity, ?int $studentId = null): LmsActor
    {
        $actor = $this->actor($request);
        $course = $activity->course;

        $this->authorizeLms(
            $course !== null && $actor->isParent() && $this->access->parentWatchesCourse($course, $actor),
            'Anda tidak memantau course ini.',
        );

        return $actor;
    }
}
