<?php

namespace App\Modules\Hrd\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin dan peran HRD Phase 2 (§102).
 *
 * Peran disusun mengikuti pemisahan tugas nyata di sekolah: penyusun payroll
 * bukan pemeriksa, pemeriksa bukan pengesah, dan pengelola data tidak otomatis
 * boleh melihat gaji atau kinerja pegawai lain.
 */
class HrdPhase2PermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        // Rekrutmen
        'hr.recruitment.view', 'hr.recruitment.manage', 'hr.recruitment.interview',
        'hr.recruitment.scorecard.view_all', 'hr.recruitment.offer.approve', 'hr.recruitment.hire',
        // Presensi & waktu
        'hr.attendance.view', 'hr.attendance.manage', 'hr.attendance.correct',
        'hr.attendance.approve', 'hr.schedule.view', 'hr.schedule.manage',
        'hr.overtime.view', 'hr.overtime.request', 'hr.overtime.approve', 'hr.overtime.verify',
        'hr.timesheet.view', 'hr.timesheet.manage', 'hr.timesheet.approve', 'hr.trip.manage',
        // Cuti
        'hr.leave.view', 'hr.leave.request', 'hr.leave.approve', 'hr.leave.policy.manage',
        'hr.leave.adjust', 'hr.holiday.manage',
        // Payroll
        'hr.payroll.view', 'hr.payroll.prepare', 'hr.payroll.review', 'hr.payroll.approve',
        'hr.payroll.finalize', 'hr.payroll.post', 'hr.payroll.adjust', 'hr.payroll.structure.manage',
        // Benefit
        'hr.benefits.view', 'hr.benefits.manage', 'hr.benefits.enroll',
        // Kinerja
        'hr.performance.view', 'hr.performance.manage', 'hr.performance.review',
        'hr.performance.calibrate', 'hr.goal.manage', 'hr.competency.manage', 'hr.pip.manage',
        // Pembelajaran
        'hr.learning.view', 'hr.learning.manage', 'hr.learning.request', 'hr.learning.approve',
        // Karier & talenta
        'hr.career.view', 'hr.career.manage', 'hr.talent.view', 'hr.talent.manage',
        'hr.recognition.give',
        // Layanan & analitik
        'hr.service.view', 'hr.service.manage', 'hr.analytics.view', 'hr.alert.view',
        'hr.rule.manage', 'hr.ai.use', 'hr.skill.view', 'hr.self.view', 'hr.team.view',
    ];

    /** @var array<string, list<string>> */
    public const ROLES = [
        'HR_ADMIN' => ['hr.recruitment.view', 'hr.recruitment.manage', 'hr.attendance.view', 'hr.attendance.manage', 'hr.leave.view', 'hr.leave.approve', 'hr.leave.policy.manage', 'hr.payroll.view', 'hr.payroll.prepare', 'hr.benefits.view', 'hr.benefits.manage', 'hr.performance.view', 'hr.performance.manage', 'hr.learning.view', 'hr.learning.manage', 'hr.career.view', 'hr.talent.view', 'hr.talent.manage', 'hr.service.view', 'hr.service.manage', 'hr.analytics.view', 'hr.alert.view', 'hr.rule.manage', 'hr.skill.view'],
        'HR_MANAGER' => ['hr.recruitment.view', 'hr.recruitment.offer.approve', 'hr.attendance.view', 'hr.leave.view', 'hr.leave.approve', 'hr.leave.adjust', 'hr.payroll.view', 'hr.payroll.approve', 'hr.performance.view', 'hr.performance.calibrate', 'hr.talent.view', 'hr.analytics.view', 'hr.alert.view', 'hr.rule.manage'],
        'HR_OFFICER' => ['hr.recruitment.view', 'hr.attendance.view', 'hr.attendance.manage', 'hr.leave.view', 'hr.learning.view', 'hr.service.view', 'hr.service.manage', 'hr.skill.view'],
        'RECRUITER' => ['hr.recruitment.view', 'hr.recruitment.manage', 'hr.recruitment.interview'],
        'PAYROLL_ADMIN' => ['hr.payroll.view', 'hr.payroll.prepare', 'hr.payroll.adjust', 'hr.payroll.structure.manage', 'hr.attendance.view', 'hr.leave.view'],
        'PAYROLL_REVIEWER' => ['hr.payroll.view', 'hr.payroll.review'],
        'PAYROLL_APPROVER' => ['hr.payroll.view', 'hr.payroll.approve'],
        'TIME_ADMIN' => ['hr.attendance.view', 'hr.attendance.manage', 'hr.attendance.correct', 'hr.schedule.view', 'hr.schedule.manage', 'hr.overtime.view', 'hr.overtime.verify', 'hr.timesheet.view', 'hr.trip.manage'],
        'LEAVE_ADMIN' => ['hr.leave.view', 'hr.leave.policy.manage', 'hr.leave.adjust', 'hr.holiday.manage'],
        'LND_ADMIN' => ['hr.learning.view', 'hr.learning.manage', 'hr.learning.approve', 'hr.skill.view'],
        'PERFORMANCE_ADMIN' => ['hr.performance.view', 'hr.performance.manage', 'hr.performance.calibrate', 'hr.goal.manage', 'hr.competency.manage', 'hr.pip.manage'],
        'TALENT_ADMIN' => ['hr.talent.view', 'hr.talent.manage', 'hr.career.view', 'hr.career.manage', 'hr.recognition.give', 'hr.skill.view'],
        'MANAGER' => ['hr.team.view', 'hr.attendance.view', 'hr.leave.request', 'hr.leave.approve', 'hr.overtime.request', 'hr.overtime.approve', 'hr.timesheet.view', 'hr.timesheet.approve', 'hr.performance.review', 'hr.goal.manage', 'hr.learning.request', 'hr.career.view', 'hr.recruitment.view'],
        'EMPLOYEE' => ['hr.self.view', 'hr.leave.request', 'hr.attendance.view', 'hr.overtime.request', 'hr.learning.request', 'hr.career.view', 'hr.benefits.enroll', 'hr.service.view'],
        'INTERVIEWER' => ['hr.recruitment.interview'],
        'TRAINER' => ['hr.learning.manage', 'hr.skill.view'],
        'MENTOR' => ['hr.career.view', 'hr.talent.view'],
        'PERFORMANCE_REVIEWER' => ['hr.performance.review', 'hr.goal.manage'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        foreach (self::ROLES as $role => $permissions) {
            $roleModel = Role::findOrCreate($role, $guard);
            $roleModel->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
