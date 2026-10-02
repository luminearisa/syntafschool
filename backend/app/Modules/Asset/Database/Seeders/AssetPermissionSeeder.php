<?php

namespace App\Modules\Asset\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hak akses Modul Asset & Sarpras.
 *
 * Pemisahan yang penting di sini bukan "siapa boleh apa", melainkan pemisahan
 * yang memang dituntut pemeriksaan: petugas yang MENGUSULKAN penghapusan tidak
 * sekaligus yang MENYETUJUINYA, dan petugas yang mencatat aset tidak otomatis
 * boleh mengubah angka keuangannya.
 */
class AssetPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'asset.admin',
        'asset.view', 'asset.view.all', 'asset.manage', 'asset.register', 'asset.export', 'asset.import',
        'asset.financial.view', 'asset.financial.manage',
        'asset.assign', 'asset.transfer.view', 'asset.transfer.request', 'asset.transfer.approve',
        'asset.loan.view', 'asset.loan.request', 'asset.loan.manage', 'asset.loan.approve',
        'asset.inspect.view', 'asset.inspect.manage',
        'asset.maintenance.view', 'asset.maintenance.request', 'asset.maintenance.manage', 'asset.maintenance.execute',
        'asset.stockopname.view', 'asset.stockopname.create', 'asset.stockopname.manage', 'asset.stockopname.approve',
        'asset.disposal.view', 'asset.disposal.request', 'asset.disposal.approve', 'asset.disposal.execute',
        'asset.report.view', 'asset.dashboard.view',
        'facility.view', 'facility.manage', 'facility.book', 'facility.booking.approve', 'facility.close',
        'location.view', 'location.manage',
    ];

    /** @var array<string, list<string>> */
    public const ROLES = [
        'ASSET_ADMIN' => ['*'],
        'SARPRAS_MANAGER' => [
            'asset.view', 'asset.view.all', 'asset.assign', 'asset.transfer.view', 'asset.transfer.approve',
            'asset.inspect.view', 'asset.inspect.manage', 'asset.maintenance.view', 'asset.maintenance.request',
            'asset.maintenance.manage', 'asset.report.view', 'asset.dashboard.view', 'asset.disposal.view',
            'facility.view', 'facility.manage', 'facility.book', 'facility.booking.approve', 'facility.close',
            'location.view', 'location.manage',
        ],
        'ASSET_OFFICER' => [
            'asset.view', 'asset.register', 'asset.manage', 'asset.assign', 'asset.view.all',
            'asset.inspect.view', 'asset.inspect.manage', 'asset.stockopname.view', 'asset.stockopname.create',
            'asset.stockopname.manage', 'asset.transfer.view', 'asset.transfer.request', 'asset.loan.view',
            'asset.report.view', 'asset.dashboard.view', 'location.view', 'facility.view',
        ],
        'INVENTORY_OFFICER' => ['asset.view', 'asset.maintenance.view', 'asset.report.view'],
        'MAINTENANCE_MANAGER' => [
            'asset.view', 'asset.maintenance.view', 'asset.maintenance.request', 'asset.maintenance.manage',
            'asset.maintenance.execute', 'asset.inspect.view', 'asset.inspect.manage', 'asset.report.view',
            'asset.dashboard.view', 'facility.view', 'facility.close', 'location.view',
        ],
        'TECHNICIAN' => ['asset.view', 'asset.maintenance.view', 'asset.maintenance.execute', 'asset.inspect.view'],
        'FACILITY_MANAGER' => [
            'facility.view', 'facility.manage', 'facility.book', 'facility.booking.approve', 'facility.close',
            'location.view', 'location.manage', 'asset.view', 'asset.report.view', 'asset.dashboard.view',
            'asset.maintenance.request', 'asset.inspect.view',
        ],
        'DEPARTMENT_MANAGER' => [
            'asset.view', 'asset.loan.view', 'asset.loan.request', 'asset.transfer.view', 'asset.transfer.request',
            'asset.maintenance.view', 'asset.maintenance.request', 'asset.disposal.request', 'asset.report.view',
            'facility.view', 'facility.book', 'location.view',
        ],
        'ASSET_CUSTODIAN' => [
            'asset.view', 'asset.maintenance.request', 'asset.loan.view', 'asset.loan.request',
            'asset.transfer.view', 'facility.book',
        ],
        'FINANCE' => ['asset.view', 'asset.financial.view', 'asset.report.view', 'asset.dashboard.view', 'asset.disposal.approve'],
        'PROCUREMENT' => ['asset.view', 'asset.report.view'],
        'AUDITOR' => ['asset.view', 'asset.view.all', 'asset.financial.view', 'asset.report.view', 'asset.dashboard.view'],
        'ERP_ADMIN' => ['asset.view', 'asset.admin'],
    ];

    /**
     * Peran pegawai biasa (guru, staf, …) mendapat hak "warga sekolah": melapor
     * kerusakan dan memesan fasilitas. Tanpa `asset.view` — daftar aset tetap
     * hanya untuk peran sarpras; aset ditemukan lewat pindai label QR publik.
     * Daftar booking sudah disaring pemilik di controller (bukan pengelola →
     * hanya miliknya).
     *
     * @var list<string>
     */
    public const EMPLOYEE_ROLES = [
        'guru', 'wali-kelas', 'staf', 'kepala-unit', 'kepala-asrama', 'musyrif', 'pustakawan',
        'petugas-absensi', 'petugas-kedisiplinan', 'petugas-kesehatan', 'petugas-perizinan',
    ];

    /** @var list<string> */
    public const EMPLOYEE_GRANTS = ['asset.maintenance.request', 'facility.view', 'facility.book', 'location.view'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role => $permissions) {
            $model = Role::findOrCreate($role, 'web');

            $model->givePermissionTo($permissions === ['*'] ? self::PERMISSIONS : $permissions);
        }

        foreach (self::EMPLOYEE_ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            $role?->givePermissionTo(self::EMPLOYEE_GRANTS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
