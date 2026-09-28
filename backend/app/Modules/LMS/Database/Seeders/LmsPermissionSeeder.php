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
        ],
        'guru' => [
            'lms.view', 'lms.category.view',
            'lms.course.view', 'lms.course.create', 'lms.course.update', 'lms.course.publish',
            'lms.content.view', 'lms.content.create', 'lms.content.update', 'lms.content.delete',
            'lms.enrollment.view', 'lms.enrollment.create', 'lms.enrollment.update',
            'lms.group.view', 'lms.group.manage',
            'lms.announcement.view', 'lms.announcement.manage',
            'lms.student.view', 'lms.report.view',
        ],
        'wali-kelas' => [
            'lms.view', 'lms.course.view', 'lms.content.view',
            'lms.enrollment.view', 'lms.group.view', 'lms.student.view', 'lms.report.view',
        ],
        'siswa' => [
            'lms.view', 'lms.course.view', 'lms.content.view', 'lms.bookmark.manage',
        ],
        'wali-murid' => [
            'lms.view', 'lms.course.view', 'lms.parent.view',
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
