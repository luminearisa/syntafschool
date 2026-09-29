<?php

namespace App\Modules\LMS\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permission granular modul LMS (§35).
 *
 * Seeder modul ini berdiri sendiri supaya LMS dapat dipasang/dilepas tanpa
 * menyunting `RolePermissionSeeder` milik ERP: ia membuat `lms.*` lalu
 * membagikannya ke peran ERP yang sudah ada (guru, siswa, wali-murid, dll).
 */
class LmsPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const ACTIONS = [
        'view', 'manage',
        'category.view', 'category.create', 'category.update', 'category.delete',
        'course.view', 'course.create', 'course.update', 'course.delete', 'course.publish',
        'enrollment.view', 'enrollment.create', 'enrollment.update', 'enrollment.delete',
        'content.view', 'content.create', 'content.update', 'content.delete',
        'group.view', 'group.manage',
        'announcement.view', 'announcement.manage',
        'bookmark.manage',
        'teacher.manage', 'student.view', 'parent.view',
        'report.view', 'audit.view', 'settings.manage',

        // Phase 2 — aktivitas pembelajaran, tugas, kuis, forum, lesson, polling, survei.
        'activity.view', 'activity.create', 'activity.update', 'activity.delete', 'activity.publish', 'activity.preview',
        'assignment.view', 'assignment.create', 'assignment.update', 'assignment.delete', 'assignment.grade', 'assignment.submit',
        'quiz.view', 'quiz.create', 'quiz.update', 'quiz.delete', 'quiz.attempt', 'quiz.grade',
        'question.view', 'question.create', 'question.update', 'question.delete', 'questionbank.manage',
        'forum.view', 'forum.create', 'forum.reply', 'forum.moderate',
        'lesson.view', 'lesson.create', 'lesson.update', 'lesson.delete',
        'choice.view', 'choice.create', 'choice.respond',
        'survey.view', 'survey.create', 'survey.respond',
        'grade.view', 'grade.manage', 'submission.view', 'submission.grade', 'submission.upload',

        // Phase 3 — gradebook, rubric, learning outcome, competency, banding nilai.
        'gradebook.view', 'gradebook.manage',
        'grade.edit', 'grade.release', 'grade.lock', 'grade.correct',
        'grade.review', 'grade.approve', 'grade.sync',
        'grade.view_own', 'grade.request_review',
        'grade.view_child', 'grade.view_child_feedback',
        'rubric.create', 'rubric.manage', 'rubric.assess',
        'outcome.manage', 'outcome.assess',
        'competency.manage', 'competency.assess',

        // Phase 4 — learning path, prasyarat, drip, konten interaktif, kelas langsung,
        // sertifikat, badge, gamifikasi, ulasan, template/salin/arsip, pesan.
        'learning_path.view', 'learning_path.manage',
        'drip.manage', 'prerequisite.manage',
        'interactive.view', 'interactive.create', 'interactive.update', 'interactive.delete',
        'interactive.publish', 'interactive.respond',
        'live.view', 'live.manage', 'live.attendance', 'live.join', 'live.recording.manage',
        'certificate.view', 'certificate.manage', 'certificate.issue', 'certificate.revoke',
        'certificate.template.manage', 'certificate.view_own', 'certificate.view_child',
        'certificate.download_own',
        'badge.view', 'badge.manage', 'badge.award', 'badge.view_own', 'badge.view_child',
        'gamification.view', 'gamification.manage', 'gamification.view_own', 'leaderboard.view',
        'review.view', 'review.create', 'review.moderate',
        'feedback.submit', 'feedback.view',
        'template.view', 'template.manage',
        'course.copy', 'course.archive',
        'engagement.view', 'engagement.view_own', 'engagement.view_child',
        'message.send', 'message.view',
    ];

    /**
     * Peran ERP → daftar izin LMS.
     *
     * Pembagiannya mengikuti §3/§35: guru hanya mengelola course-nya sendiri
     * (kepemilikan diperiksa `CourseAccessService`), siswa tidak pernah diberi
     * izin tulis materi, dan wali murid hanya membaca data anaknya.
     *
     * @var array<string, list<string>>
     */
    protected array $grants = [
        // Administrator tidak diberi izin spesifik: ia memakai `lms.manage`.
        'admin-sekolah' => ['*'],
        'kepala-sekolah' => [
            'lms.view', 'lms.category.view', 'lms.course.view', 'lms.content.view',
            'lms.enrollment.view', 'lms.group.view', 'lms.student.view', 'lms.parent.view',
            'lms.report.view', 'lms.audit.view',
            'lms.activity.view', 'lms.assignment.view', 'lms.quiz.view', 'lms.question.view',
            'lms.forum.view', 'lms.lesson.view', 'lms.choice.view', 'lms.survey.view',
            'lms.submission.view', 'lms.grade.view',
            'lms.gradebook.view', 'lms.grade.view_own',
            'lms.rubric.create', 'lms.outcome.manage', 'lms.competency.manage',
            // Phase 4 — pengawas membaca seluruh capaian, boleh menerbitkan sertifikat.
            'lms.learning_path.view', 'lms.interactive.view', 'lms.live.view',
            'lms.certificate.view', 'lms.certificate.issue', 'lms.certificate.template.manage',
            'lms.badge.view', 'lms.gamification.view', 'lms.leaderboard.view',
            'lms.review.view', 'lms.review.moderate', 'lms.feedback.view',
            'lms.template.view', 'lms.engagement.view', 'lms.message.view', 'lms.message.send',
        ],
        'guru' => [
            'lms.view', 'lms.category.view',
            'lms.course.view', 'lms.course.create', 'lms.course.update', 'lms.course.publish',
            'lms.content.view', 'lms.content.create', 'lms.content.update', 'lms.content.delete',
            'lms.enrollment.view', 'lms.enrollment.create', 'lms.enrollment.update',
            'lms.group.view', 'lms.group.manage',
            'lms.announcement.view', 'lms.announcement.manage',
            'lms.student.view', 'lms.report.view',
            'lms.activity.view', 'lms.activity.create', 'lms.activity.update', 'lms.activity.delete',
            'lms.activity.publish', 'lms.activity.preview',
            'lms.assignment.view', 'lms.assignment.create', 'lms.assignment.update', 'lms.assignment.delete', 'lms.assignment.grade',
            'lms.quiz.view', 'lms.quiz.create', 'lms.quiz.update', 'lms.quiz.delete', 'lms.quiz.grade',
            'lms.question.view', 'lms.question.create', 'lms.question.update', 'lms.question.delete', 'lms.questionbank.manage',
            'lms.forum.view', 'lms.forum.create', 'lms.forum.reply', 'lms.forum.moderate',
            'lms.lesson.view', 'lms.lesson.create', 'lms.lesson.update', 'lms.lesson.delete',
            'lms.choice.view', 'lms.choice.create',
            'lms.survey.view', 'lms.survey.create',
            'lms.grade.view', 'lms.grade.manage', 'lms.submission.view', 'lms.submission.grade',
            // Phase 3 — guru pemilik course mengelola gradebook & menilai.
            'lms.gradebook.view', 'lms.gradebook.manage',
            'lms.grade.edit', 'lms.grade.release', 'lms.grade.review', 'lms.grade.approve',
            'lms.rubric.create', 'lms.rubric.manage', 'lms.rubric.assess',
            'lms.outcome.manage', 'lms.outcome.assess',
            'lms.competency.manage', 'lms.competency.assess',
            // Phase 4 — guru mengelola jalur belajar, kelas langsung, sertifikat,
            // badge, dan template course-nya sendiri.
            'lms.learning_path.view', 'lms.learning_path.manage',
            'lms.drip.manage', 'lms.prerequisite.manage',
            'lms.interactive.view', 'lms.interactive.create', 'lms.interactive.update',
            'lms.interactive.delete', 'lms.interactive.publish',
            'lms.live.view', 'lms.live.manage', 'lms.live.attendance', 'lms.live.recording.manage',
            'lms.certificate.view', 'lms.certificate.issue', 'lms.certificate.revoke',
            'lms.certificate.template.manage',
            'lms.badge.view', 'lms.badge.manage', 'lms.badge.award',
            'lms.gamification.view', 'lms.gamification.manage', 'lms.leaderboard.view',
            'lms.review.view', 'lms.review.moderate', 'lms.feedback.view',
            'lms.template.view', 'lms.template.manage', 'lms.course.copy', 'lms.course.archive',
            'lms.engagement.view', 'lms.message.view', 'lms.message.send',
        ],
        'wali-kelas' => [
            'lms.view', 'lms.course.view', 'lms.content.view',
            'lms.enrollment.view', 'lms.group.view', 'lms.student.view', 'lms.report.view',
            'lms.activity.view', 'lms.assignment.view', 'lms.quiz.view', 'lms.forum.view',
            'lms.lesson.view', 'lms.choice.view', 'lms.survey.view',
            'lms.submission.view', 'lms.grade.view',
            'lms.gradebook.view', 'lms.grade.review', 'lms.grade.approve',
            'lms.outcome.manage', 'lms.competency.manage',
            // Phase 4 — wali kelas memantau capaian & aktivitas, tanpa menyunting materi.
            'lms.learning_path.view', 'lms.interactive.view', 'lms.live.view',
            'lms.certificate.view', 'lms.badge.view', 'lms.gamification.view', 'lms.leaderboard.view',
            'lms.review.view', 'lms.review.moderate', 'lms.feedback.view',
            'lms.template.view', 'lms.engagement.view', 'lms.message.view', 'lms.message.send',
        ],
        'siswa' => [
            'lms.view', 'lms.course.view', 'lms.content.view', 'lms.bookmark.manage',
            // Siswa hanya boleh mengerjakan & melihat miliknya sendiri (§54).
            'lms.activity.view', 'lms.assignment.view', 'lms.assignment.submit',
            'lms.quiz.view', 'lms.quiz.attempt',
            'lms.forum.view', 'lms.forum.reply',
            'lms.lesson.view', 'lms.choice.view', 'lms.choice.respond',
            'lms.survey.view', 'lms.survey.respond',
            'lms.grade.view', 'lms.submission.view', 'lms.submission.upload',
            // Phase 3 — siswa melihat nilainya sendiri & dapat mengajukan banding.
            'lms.grade.view_own', 'lms.grade.request_review',
            // Phase 4 — siswa belajar lewat konten interaktif, mengikuti kelas
            // langsung, dan hanya melihat capaian miliknya sendiri (§54).
            'lms.learning_path.view',
            'lms.interactive.view', 'lms.interactive.respond',
            'lms.live.view', 'lms.live.join',
            'lms.certificate.view_own', 'lms.certificate.download_own',
            'lms.badge.view_own', 'lms.gamification.view_own', 'lms.leaderboard.view',
            'lms.review.create', 'lms.feedback.submit',
            'lms.engagement.view_own', 'lms.message.view', 'lms.message.send',
        ],
        'wali-murid' => [
            'lms.view', 'lms.course.view', 'lms.parent.view', 'lms.student.view',
            // Wali hanya membaca — tidak pernah submit, menjawab, atau menilai (§48).
            'lms.activity.view', 'lms.assignment.view', 'lms.quiz.view', 'lms.forum.view',
            'lms.lesson.view', 'lms.choice.view', 'lms.survey.view', 'lms.grade.view',
            // Phase 3 — wali hanya membaca nilai & umpan balik anak yang sah.
            'lms.grade.view_child', 'lms.grade.view_child_feedback',
            // Phase 4 — wali membaca capaian anak (sertifikat, badge, kehadiran
            // kelas langsung) tanpa kemampuan mengubah apa pun.
            'lms.learning_path.view', 'lms.live.view',
            'lms.certificate.view_child', 'lms.badge.view_child', 'lms.engagement.view_child',
            'lms.message.view',
        ],
    ];

    public function run(): void
    {
        $permissions = array_map(fn (string $action) => "lms.{$action}", self::ACTIONS);

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $superAdmin = Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->first();
        $superAdmin?->givePermissionTo($permissions);

        foreach ($this->grants as $roleName => $granted) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            if (in_array('*', $granted, true)) {
                $role->givePermissionTo($permissions);

                continue;
            }

            $role->givePermissionTo($granted);
        }
    }
}
