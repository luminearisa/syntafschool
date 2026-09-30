<?php

namespace App\Modules\UKS\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin granular modul UKS dan pemetaannya ke peran ERP yang sudah ada.
 *
 * Izin mengatur *layar*; cakupan *baris* (siapa melihat data siapa) ditegakkan
 * terpisah di `UksAccessService`, sehingga misalnya orang tua yang memegang
 * `uks.visit.view` tetap hanya melihat kunjungan anaknya sendiri.
 */
class UksPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'uks.view',
        'uks.manage',
        'uks.visit.view',
        'uks.visit.view.all',
        'uks.visit.create',
        'uks.visit.manage',
        'uks.record.view',
        'uks.record.manage',
        'uks.medicine.view',
        'uks.medicine.manage',
        'uks.incident.view',
        'uks.incident.create',
        'uks.incident.manage',
        'uks.letter.view',
        'uks.letter.issue',
        'uks.consent.create',
        'uks.officer.view',
        'uks.officer.manage',
        'uks.report.view',
        'uks.report.export',
    ];

    /**
     * Izin per peran. Peran yang tidak disebut tidak mendapat izin UKS.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'super-admin' => ['*'],
        'admin-sekolah' => ['*'],
        // Kepala sekolah: melihat & melaporkan seluruh sekolah, tanpa mengelola stok.
        'kepala-sekolah' => [
            'uks.view', 'uks.visit.view', 'uks.visit.view.all', 'uks.record.view',
            'uks.incident.view', 'uks.letter.view', 'uks.officer.view',
            'uks.report.view', 'uks.report.export', 'uks.medicine.view',
        ],
        // Petugas kesehatan (peran asrama yang sudah ada) menjadi operator UKS.
        'petugas-kesehatan' => [
            'uks.view', 'uks.visit.view', 'uks.visit.view.all', 'uks.visit.create', 'uks.visit.manage',
            'uks.record.view', 'uks.record.manage', 'uks.medicine.view', 'uks.medicine.manage',
            'uks.incident.view', 'uks.incident.create', 'uks.incident.manage',
            'uks.letter.view', 'uks.letter.issue', 'uks.consent.create',
            'uks.report.view', 'uks.report.export',
        ],
        // Guru & wali kelas: mencatat rujukan dari kelas, melihat lingkup kelasnya.
        'guru' => ['uks.view', 'uks.visit.view', 'uks.visit.create', 'uks.incident.view', 'uks.incident.create', 'uks.record.view', 'uks.letter.view'],
        'wali-kelas' => ['uks.view', 'uks.visit.view', 'uks.visit.create', 'uks.incident.view', 'uks.incident.create', 'uks.record.view', 'uks.letter.view', 'uks.report.view'],
        'staf' => ['uks.view', 'uks.visit.view', 'uks.visit.create', 'uks.incident.create'],
        // Siswa: melihat riwayatnya sendiri dan suratnya.
        'siswa' => ['uks.view', 'uks.visit.view', 'uks.record.view', 'uks.letter.view'],
        // Orang tua/wali: kabar anaknya, persetujuan tindakan, dan surat.
        'wali-murid' => [
            'uks.view', 'uks.visit.view', 'uks.record.view', 'uks.letter.view',
            'uks.consent.create', 'uks.incident.view', 'uks.report.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $grants) {
            $role = Role::findOrCreate($roleName, 'web');

            $permissions = $grants === ['*']
                ? self::PERMISSIONS
                : $grants;

            $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
