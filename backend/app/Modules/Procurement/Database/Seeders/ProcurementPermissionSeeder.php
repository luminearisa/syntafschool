<?php

namespace App\Modules\Procurement\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin granular Modul Procurement & Vendor (§45).
 *
 * Dua lapis yang sengaja dipisah:
 *
 * 1. **Izin (file ini)** menentukan *layar dan aksi* yang boleh dibuka.
 * 2. **Layanan domain** menentukan *dokumen/baris* yang boleh disentuh
 *    (mis. pemohon hanya melihat PR miliknya).
 *
 * Peran baru dibuat di sini agar sekolah dapat menugaskan admin/petugas
 * pengadaan tanpa menambah peran manual.
 */
class ProcurementPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'procurement.view',
        'procurement.manage',
        'procurement.create_pr',
        'procurement.approve_pr',
        'procurement.create_rfq',
        'procurement.manage_quotation',
        'procurement.evaluate_vendor',
        'procurement.create_po',
        'procurement.approve_po',
        'procurement.issue_po',
        'procurement.receive',
        'procurement.inspect',
        'procurement.manage_invoice',
        'procurement.match_invoice',
        'procurement.send_to_accounting',
        'procurement.manage_vendor',
        'procurement.view_reports',
        'procurement.manage_settings',
        'procurement.audit.view',
    ];

    /** Izin operasional penuh bagi petugas pengadaan. */
    private const OFFICER = [
        'procurement.view', 'procurement.create_pr', 'procurement.create_rfq',
        'procurement.manage_quotation', 'procurement.evaluate_vendor', 'procurement.create_po',
        'procurement.issue_po', 'procurement.receive', 'procurement.inspect',
        'procurement.manage_invoice', 'procurement.match_invoice', 'procurement.manage_vendor',
        'procurement.view_reports',
    ];

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'super-admin' => ['*'],
        'admin-sekolah' => ['*'],

        // Admin pengadaan: mengatur master, vendor, aturan, dan seluruh alur.
        'admin-pengadaan' => ['*'],

        // Manajer pengadaan: menyetujui, menerbitkan, dan mengawasi.
        'manajer-pengadaan' => [
            'procurement.view', 'procurement.manage', 'procurement.create_pr', 'procurement.approve_pr',
            'procurement.create_rfq', 'procurement.manage_quotation', 'procurement.evaluate_vendor',
            'procurement.create_po', 'procurement.approve_po', 'procurement.issue_po',
            'procurement.manage_vendor', 'procurement.view_reports', 'procurement.audit.view',
        ],

        'petugas-pengadaan' => self::OFFICER,

        // Pemohon: membuat & mengajukan PR, melihat dokumennya.
        'pemohon-pengadaan' => ['procurement.view', 'procurement.create_pr'],

        // Verifikator anggaran: meninjau & menyetujui dari sisi anggaran.
        'verifikator-anggaran' => ['procurement.view', 'procurement.approve_pr', 'procurement.view_reports'],

        // Petugas penerimaan & inspektur mutu.
        'petugas-penerimaan' => ['procurement.view', 'procurement.receive'],
        'inspektur-mutu' => ['procurement.view', 'procurement.receive', 'procurement.inspect'],

        // Verifikator invoice & pengirim ke accounting.
        'verifikator-invoice' => [
            'procurement.view', 'procurement.manage_invoice', 'procurement.match_invoice',
            'procurement.send_to_accounting', 'procurement.view_reports',
        ],

        // Pengawas & auditor: membaca dan melaporkan tanpa mengubah.
        'kepala-sekolah' => ['procurement.view', 'procurement.view_reports', 'procurement.audit.view'],
        'kepala-unit' => ['procurement.view', 'procurement.create_pr', 'procurement.view_reports'],
        'auditor' => ['procurement.view', 'procurement.view_reports', 'procurement.audit.view'],
        'guru' => ['procurement.view', 'procurement.create_pr'],
        'pegawai' => ['procurement.view', 'procurement.create_pr'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $newRoles = [
            'admin-pengadaan', 'manajer-pengadaan', 'petugas-pengadaan', 'pemohon-pengadaan',
            'verifikator-anggaran', 'petugas-penerimaan', 'inspektur-mutu', 'verifikator-invoice',
        ];

        foreach ($newRoles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            $grants = $permissions === ['*'] ? self::PERMISSIONS : $permissions;

            foreach ($grants as $permission) {
                if (Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }
    }
}
