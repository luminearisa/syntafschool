<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Granular Competition RBAC. Adds permissions without removing other module grants. */
class CompetitionPermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'competition.view', 'competition.create', 'competition.update', 'competition.delete', 'competition.publish', 'competition.scope.all', 'competition.report', 'competition.export', 'competition.audit',
        'registration.view', 'registration.create', 'registration.manage', 'registration.review', 'registration.approve', 'registration.reject', 'registration.override', 'registration.import',
        'consent.decide', 'participant.view', 'participant.manage', 'team.manage', 'schedule.view', 'schedule.manage', 'judge.view', 'judge.assign',
        'score.view', 'score.enter', 'score.submit', 'score.review', 'score.finalize', 'score.amend', 'result.view', 'result.publish', 'result.finalize',
        'award.manage', 'certificate.generate', 'appeal.create', 'appeal.review', 'appeal.decide', 'incident.manage', 'competition.portal.student', 'competition.portal.parent', 'competition.portal.coach',
    ];

    private const ROLE_PERMISSIONS = [
        'COMPETITION_ADMIN' => '*',
        'COMPETITION_MANAGER' => [
            'competition.view', 'competition.create', 'competition.update', 'competition.publish', 'competition.report', 'competition.export', 'competition.audit', 'registration.view', 'registration.manage', 'registration.review', 'registration.approve', 'registration.reject', 'registration.override', 'participant.view', 'participant.manage', 'team.manage', 'schedule.view', 'schedule.manage', 'judge.view', 'judge.assign', 'score.view', 'score.review', 'score.finalize', 'score.amend', 'result.view', 'result.finalize', 'result.publish', 'award.manage', 'certificate.generate', 'appeal.review', 'appeal.decide', 'incident.manage',
        ],
        'COMPETITION_COORDINATOR' => [
            'competition.view', 'competition.update', 'registration.view', 'registration.review', 'participant.view', 'participant.manage', 'team.manage', 'schedule.view', 'schedule.manage', 'judge.view', 'judge.assign', 'score.view', 'result.view', 'appeal.review', 'incident.manage',
        ],
        'REGISTRATION_OFFICER' => ['competition.view', 'registration.view', 'registration.manage', 'registration.review', 'registration.approve', 'registration.reject', 'registration.override', 'consent.decide', 'participant.view'],
        'COMPETITION_JUDGE' => ['competition.view', 'judge.view', 'score.view', 'score.enter', 'score.submit', 'score.amend'],
        'HEAD_JUDGE' => ['competition.view', 'judge.view', 'score.view', 'score.enter', 'score.submit', 'score.review', 'score.finalize', 'score.amend', 'result.view'],
        'COMPETITION_REFEREE' => ['competition.view', 'schedule.view', 'schedule.manage', 'participant.view', 'participant.manage', 'score.view', 'score.review', 'incident.manage'],
        'COMPETITION_COACH' => ['competition.view', 'participant.view', 'team.manage', 'schedule.view', 'result.view', 'competition.portal.coach'],
        'AWARD_OFFICER' => ['competition.view', 'result.view', 'award.manage', 'certificate.generate'],
        'CERTIFICATE_OFFICER' => ['competition.view', 'result.view', 'certificate.generate'],
        'COMPETITION_AUDITOR' => ['competition.view', 'competition.report', 'competition.export', 'competition.audit', 'registration.view', 'participant.view', 'schedule.view', 'judge.view', 'score.view', 'result.view'],
        'ACTIVITY_ADMIN' => ['competition.view', 'competition.create', 'competition.update', 'competition.publish', 'registration.view', 'registration.manage', 'registration.review', 'registration.approve', 'registration.reject', 'participant.view', 'participant.manage', 'team.manage', 'schedule.view', 'schedule.manage', 'judge.view', 'judge.assign', 'score.view', 'score.review', 'score.finalize', 'result.view', 'result.finalize', 'result.publish', 'award.manage', 'certificate.generate'],
        'ACTIVITY_MANAGER' => ['competition.view', 'competition.create', 'competition.update', 'competition.publish', 'registration.view', 'registration.manage', 'registration.review', 'registration.approve', 'registration.reject', 'participant.view', 'participant.manage', 'team.manage', 'schedule.view', 'schedule.manage', 'judge.view', 'judge.assign', 'score.view', 'score.review', 'score.finalize', 'result.view', 'result.finalize', 'result.publish', 'award.manage', 'certificate.generate'],
        'ACTIVITY_JUDGE' => ['competition.view', 'judge.view', 'score.view', 'score.enter', 'score.submit'],
        'COACH' => ['competition.view', 'participant.view', 'team.manage', 'schedule.view', 'result.view', 'competition.portal.coach'],
        'MENTOR' => ['competition.view', 'participant.view', 'schedule.view', 'result.view', 'competition.portal.coach'],
        'siswa' => ['registration.create', 'competition.portal.student', 'appeal.create'],
        'wali-murid' => ['registration.create', 'consent.decide', 'competition.portal.parent', 'appeal.create'],
        'guru' => ['competition.view', 'participant.view', 'schedule.view', 'result.view'],
        'kepala-sekolah' => ['competition.view', 'competition.report', 'registration.view', 'participant.view', 'schedule.view', 'judge.view', 'score.view', 'result.view', 'result.publish', 'competition.audit'],
        'admin-sekolah' => ['competition.view', 'competition.create', 'competition.update', 'competition.publish', 'registration.view', 'registration.manage', 'registration.review', 'registration.approve', 'registration.reject', 'participant.view', 'participant.manage', 'team.manage', 'schedule.view', 'schedule.manage', 'judge.view', 'judge.assign', 'score.view', 'score.review', 'score.finalize', 'result.view', 'result.finalize', 'result.publish', 'award.manage', 'certificate.generate'],
        'ERP_ADMIN' => '*', 'SUPER_ADMIN' => '*', 'super-admin' => '*',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $all = Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->get();
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->givePermissionTo($permissions === '*' ? $all : array_values(array_intersect($permissions, self::PERMISSIONS)));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
