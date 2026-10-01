<?php

namespace App\Modules\Bk\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin Phase 2 — murni aditif.
 *
 * Seeder ini **tidak pernah menghapus** izin yang sudah ada; ia hanya menambah
 * izin baru dan menempelkannya ke peran. Sifat itu penting: modul lain memakai
 * peran yang sama, dan seeder modul tidak boleh menghapus kewenangan yang bukan
 * miliknya.
 *
 * Yang dijaga ketat: izin menuju isi konseling (`bk.confidential.view`) dan
 * ekspor data siswa tidak diberikan kepada guru, wali, atau siswa — bukan karena
 * tampilannya disembunyikan, tetapi karena izinnya memang tidak pernah ada.
 */
class BkPhase2PermissionSeeder extends Seeder
{
    /** @var list<string> */
    public array $permissions = [
        'bk.conference.view',
        'bk.conference.manage',
        'bk.external-referral.view',
        'bk.external-referral.create',
        'bk.external-referral.manage',
        'bk.referral-provider.view',
        'bk.referral-provider.manage',
        'bk.consent.view',
        'bk.consent.request',
        'bk.consent.grant',
        'bk.consent.revoke',
        'bk.risk.view',
        'bk.risk.manage',
        'bk.risk.export',
        'bk.early-warning.view',
        'bk.early-warning.manage',
        'bk.early-warning.run',
        'bk.development.view',
        'bk.development.manage',
        'bk.career.view',
        'bk.career.manage',
        'bk.program.view',
        'bk.program.manage',
        'bk.feedback.view',
        'bk.feedback.review',
        'bk.analytics.view',
        'bk.sla.view',
        'bk.sla.manage',
        'bk.search',
        'bk.report.export',
        'bk.case.export',
        'bk.referral.export',
        'bk.follow-up.export',
        'bk.retention.view',
        'bk.retention.manage',
        'bk.retention.approve',
        'bk.settings.view',
        'bk.settings.manage',
    ];

    /**
     * Peta peran → izin Phase 2.
     *
     * Guru dan wali sengaja tidak menerima izin apa pun di sini: kewenangan mereka
     * pada Phase 2 (mengisi umpan balik, melihat jadwal anak) sudah dijalankan
     * endpoint yang memeriksa hubungan data, bukan izin modul.
     *
     * @var array<string, list<string>>
     */
    public array $matrix = [
        'super-admin' => ['*'],
        'admin-sekolah' => ['*'],
        'BK_ADMIN' => ['*'],
        'BK_COORDINATOR' => [
            'bk.conference.view', 'bk.conference.manage',
            'bk.external-referral.view', 'bk.external-referral.create', 'bk.external-referral.manage',
            'bk.referral-provider.view', 'bk.referral-provider.manage',
            'bk.consent.view', 'bk.consent.request', 'bk.consent.grant', 'bk.consent.revoke',
            'bk.risk.view', 'bk.risk.manage', 'bk.risk.export',
            'bk.early-warning.view', 'bk.early-warning.manage', 'bk.early-warning.run',
            'bk.development.view', 'bk.development.manage',
            'bk.career.view', 'bk.career.manage',
            'bk.program.view', 'bk.program.manage',
            'bk.feedback.view', 'bk.feedback.review',
            'bk.analytics.view', 'bk.sla.view', 'bk.sla.manage', 'bk.search', 'bk.report.export',
            'bk.case.export', 'bk.referral.export', 'bk.follow-up.export',
            'bk.retention.view', 'bk.settings.view',
        ],
        'COUNSELOR' => [
            'bk.conference.view', 'bk.conference.manage',
            'bk.external-referral.view', 'bk.external-referral.create', 'bk.external-referral.manage',
            'bk.referral-provider.view',
            'bk.consent.view', 'bk.consent.request', 'bk.consent.grant',
            'bk.risk.view', 'bk.risk.manage',
            'bk.early-warning.view', 'bk.early-warning.run',
            'bk.development.view', 'bk.development.manage',
            'bk.career.view', 'bk.career.manage',
            'bk.program.view', 'bk.program.manage',
            'bk.feedback.view', 'bk.feedback.review',
            'bk.sla.view', 'bk.search',
        ],
        'wali-kelas' => ['bk.program.view', 'bk.career.view'],
        'kepala-sekolah' => [
            'bk.analytics.view', 'bk.risk.view', 'bk.sla.view', 'bk.settings.view', 'bk.retention.view',
        ],
        'auditor' => [
            'bk.analytics.view', 'bk.sla.view', 'bk.retention.view', 'bk.settings.view', 'bk.feedback.view',
        ],
        'pengasuh-asrama' => ['bk.program.view', 'bk.risk.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach ($this->matrix as $roleName => $granted) {
            $role = Role::query()->where('name', $roleName)->first();

            if ($role === null) {
                continue;
            }

            $names = $granted === ['*'] ? $this->permissions : array_values(array_intersect($granted, $this->permissions));

            // `givePermissionTo` bersifat menambah: izin Phase 1 tidak tersentuh.
            $role->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
