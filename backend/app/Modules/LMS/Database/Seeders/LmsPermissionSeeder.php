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
        ],
        'wali-kelas' => [
            'lms.view', 'lms.course.view', 'lms.content.view',
            'lms.enrollment.view', 'lms.group.view', 'lms.student.view', 'lms.report.view',
            'lms.activity.view', 'lms.assignment.view', 'lms.quiz.view', 'lms.forum.view',
            'lms.lesson.view', 'lms.choice.view', 'lms.survey.view',
            'lms.submission.view', 'lms.grade.view',
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
        ],
        'wali-murid' => [
            'lms.view', 'lms.course.view', 'lms.parent.view', 'lms.student.view',
            // Wali hanya membaca — tidak pernah submit, menjawab, atau menilai (§48).
            'lms.activity.view', 'lms.assignment.view', 'lms.quiz.view', 'lms.forum.view',
            'lms.lesson.view', 'lms.choice.view', 'lms.survey.view', 'lms.grade.view',
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
