<?php

namespace App\Modules\Supervisi\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permission granular modul supervisi (`supervisi.*`).
 *
 * Seeder ini berdiri sendiri supaya modul dapat dipasang/dilepas tanpa
 * menyunting `RolePermissionSeeder`: ia membuat permission lalu membagikannya
 * ke peran ERP yang sudah ada (kepala sekolah, admin sekolah, guru, staf, …).
 */
class SupPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'supervisi.view',
        'supervisi.manage',

        // Instrumen & skala nilai
        'supervisi.instrument.view', 'supervisi.instrument.create', 'supervisi.instrument.update',
        'supervisi.instrument.delete', 'supervisi.instrument.publish', 'supervisi.instrument.import',

        // Pengaturan supervisor (registry)
        'supervisi.supervisor.view', 'supervisi.supervisor.manage',

        // Program, penugasan, jadwal
        'supervisi.program.view', 'supervisi.program.create', 'supervisi.program.update', 'supervisi.program.publish',
        'supervisi.assignment.view', 'supervisi.assignment.manage',
        'supervisi.schedule.view', 'supervisi.schedule.manage',

        // Kegiatan supervisi
        'supervisi.visit.view', 'supervisi.visit.view.all', 'supervisi.visit.create',
        'supervisi.visit.execute', 'supervisi.visit.finalize',
        'supervisi.visit.cancel', 'supervisi.visit.reopen',
        'supervisi.conference.manage', 'supervisi.evidence.manage',

        // Tindak lanjut
        'supervisi.action_plan.view', 'supervisi.action_plan.create', 'supervisi.action_plan.update',
        'supervisi.action_plan.verify',
        'supervisi.development.manage', 'supervisi.coaching.manage',

        // Penilaian kinerja
        'supervisi.appraisal.view', 'supervisi.appraisal.create', 'supervisi.appraisal.calculate',
        'supervisi.appraisal.publish', 'supervisi.pip.manage',

        // Keberatan & dokumen
        'supervisi.dispute.create', 'supervisi.dispute.resolve',
        'supervisi.report.view', 'supervisi.report.export',
        'supervisi.analytics.view', 'supervisi.audit.view',

        // Analitik, laporan, kalibrasi penilai & evaluasi program (Fase 4)
        'supervisi.calibration.view', 'supervisi.calibration.manage', 'supervisi.calibration.finalize',
        'supervisi.evaluation.view', 'supervisi.evaluation.manage', 'supervisi.evaluation.finalize',
        'supervisi.reminder.manage',
        'supervisi.settings.manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->grant('super-admin', self::PERMISSIONS);
        $this->grant('admin-sekolah', self::PERMISSIONS);
        $this->grant('kepala-unit', array_filter(self::PERMISSIONS, fn (string $p) => ! str_ends_with($p, 'settings.manage')));
        $this->grant('auditor', [
            'supervisi.view',
            'supervisi.instrument.view',
            'supervisi.supervisor.view',
            'supervisi.program.view',
            'supervisi.assignment.view',
            'supervisi.schedule.view',
            'supervisi.visit.view', 'supervisi.visit.view.all',
            'supervisi.action_plan.view',
            'supervisi.appraisal.view',
            'supervisi.report.view', 'supervisi.report.export',
            'supervisi.analytics.view', 'supervisi.audit.view',
            'supervisi.calibration.view', 'supervisi.evaluation.view',
        ]);

        // Kepala sekolah: seluruh kewenangan supervisi & penilaian, termasuk
        // pengaturan modul (memilih supervisor, skala nilai) — kecuali
        // penghapusan instrumen dan `supervisi.manage` (milik admin).
        $this->grant('kepala-sekolah', array_filter(
            self::PERMISSIONS,
            fn (string $permission) => ! in_array($permission, [
                'supervisi.manage',
                'supervisi.instrument.delete',
            ], true),
        ));

        // Wakil kepala sekolah & koordinator memakai peran `kepala-unit`;
        // guru senior yang ditunjuk sebagai mentor/peer memakai izin di bawah.
        $this->grant('guru', [
            'supervisi.view',
            'supervisi.instrument.view',
            'supervisi.visit.view',
            'supervisi.visit.create',
            'supervisi.schedule.view',
            'supervisi.visit.execute',
            'supervisi.conference.manage',
            'supervisi.evidence.manage',
            'supervisi.action_plan.view', 'supervisi.action_plan.create', 'supervisi.action_plan.update',
            'supervisi.development.manage',
            'supervisi.report.view',
            'supervisi.dispute.create',
        ]);

        $this->grant('staf', [
            'supervisi.view',
            'supervisi.instrument.view',
            'supervisi.visit.view',
            'supervisi.schedule.view',
            'supervisi.action_plan.view', 'supervisi.action_plan.update',
            'supervisi.development.manage',
            'supervisi.report.view',
            'supervisi.dispute.create',
        ]);
    }

    /** @param list<string> $permissions */
    protected function grant(string $role, array $permissions): void
    {
        $roleModel = Role::findByName($role, 'web');

        if ($roleModel === null) {
            return;
        }

        $roleModel->givePermissionTo(array_values($permissions));
    }
}
