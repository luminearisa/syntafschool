<?php

namespace Database\Seeders;

use App\Services\Library\MasterData\LibraryMasterRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Modul yang tersedia dan aksi CRUD-nya.
     *
     * @var array<string, list<string>>
     */
    protected array $modules = [
        'master-data' => ['view', 'create', 'update', 'delete', 'import', 'export'],
        'academic' => ['view', 'create', 'update', 'delete', 'publish', 'approve', 'promote', 'grade', 'attendance'],
        'student' => ['view', 'create', 'update', 'delete'],
        'employee' => ['view', 'create', 'update', 'delete'],
        'finance' => ['view', 'create', 'update', 'delete'],
        'assessment' => ['view', 'create', 'update', 'delete'],
        // Modul Library (Step 1 — fondasi). Aksi bersarang menghasilkan nama
        // berjenjang, mis. "library.settings.view" & "library.location.create".
        'library' => [
            'view', 'create', 'update', 'delete',
            'settings.view', 'settings.update',
            'location.view', 'location.create', 'location.update', 'location.delete',
            'operating_hours.view', 'operating_hours.update',
            'closure.view', 'closure.create', 'closure.update', 'closure.delete',
            'numbering.view', 'numbering.update',
            'dashboard.view',
            'audit.view',
            // Fase 3 — katalog, koleksi, keanggotaan, kartu, OPAC.
            'catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete',
            'item.view', 'item.create', 'item.update', 'item.delete',
            'member.view', 'member.create', 'member.update', 'member.delete',
            'membership.view', 'membership.create', 'membership.update', 'membership.delete',
            'member_card.view', 'member_card.generate',
            'opac.view', 'opac.manage',
            // Fase 4 — sirkulasi, denda, reservasi, pengadaan, inventaris.
            'circulation.view', 'circulation.checkout', 'circulation.return', 'circulation.renew', 'circulation.manage',
            'fine.view', 'fine.manage',
            'reservation.view', 'reservation.create', 'reservation.manage',
            'acquisition.view', 'acquisition.create', 'acquisition.manage',
            'inventory.view', 'inventory.manage',
            // Phase 5 — laporan & ekspor.
            'report.view', 'report.export',
        ],
        'inventory' => ['view', 'create', 'update', 'delete'],
        // Modul PPDB: konfigurasi penerimaan, pendaftaran, pembayaran, seleksi.
        // Aksi bersarang menghasilkan nama berjenjang, mis. "ppdb.payment.verify".
        'ppdb' => [
            'view', 'create', 'update', 'delete', 'verify',
            'period.manage', 'wave.manage', 'program.manage', 'form.manage',
            'question.manage', 'document.manage', 'fee.manage',
            'registration.view', 'registration.manage', 'registration.verify',
            // Menetapkan lulus di atas kuota adalah keputusan kepemimpinan, bukan
            // operasional panitia — sengaja tidak diberikan ke panitia-ppdb.
            'registration.override_quota',
            'payment.view', 'payment.verify',
            'selection.manage', 'announcement.manage', 'account.manage',
            'report.view', 'report.export',
        ],
        'report' => ['view', 'export'],
        'user' => ['view', 'create', 'update', 'delete'],
        'setting' => ['view', 'update'],
        // Modul Tahfiz: setoran adalah pencatatan inti, approve untuk
        // persetujuan pendaftaran & penerbitan sertifikat.
        'tahfiz' => ['view', 'create', 'update', 'delete', 'setoran', 'approve', 'assess', 'report'],
    ];

    /** @var array<string, list<string>> */
    protected array $roles = [
        'super-admin' => ['*'],
        'admin-sekolah' => ['master-data', 'academic', 'student', 'employee', 'finance', 'assessment', 'library', 'inventory', 'ppdb', 'report', 'user', 'setting', 'tahfiz'],
        'kepala-sekolah' => ['report', 'master-data:view', 'academic:view', 'academic:approve', 'academic:publish', 'academic:promote', 'student:view', 'employee:view', 'finance:view', 'tahfiz:view', 'tahfiz:report', 'library:view', 'library:dashboard.view'],
        'bendahara' => ['finance', 'report:view'],
        // Guru Tahfiz: lihat, catat setoran, dan menilai. Tidak menghapus data.
        'guru' => ['academic:view', 'academic:grade', 'academic:attendance', 'student:view', 'assessment', 'report:view', 'tahfiz:view', 'tahfiz:create', 'tahfiz:setoran', 'tahfiz:assess'],
        // Musyrif/musyrifah: memantau seluruh halaqah (view + laporan).
        'musyrif' => ['tahfiz:view', 'tahfiz:report', 'tahfiz:create', 'tahfiz:setoran', 'tahfiz:assess'],
        'wali-kelas' => ['academic:view', 'academic:grade', 'academic:attendance', 'academic:approve', 'student:view', 'assessment', 'report:view', 'tahfiz:view', 'tahfiz:create', 'tahfiz:setoran', 'tahfiz:assess'],
        'staf' => ['master-data:view', 'student:view', 'employee:view', 'tahfiz:view', 'tahfiz:create', 'tahfiz:update'],
        // Peserta Tahfiz (termasuk siswa yang mengikuti program): lihat progress sendiri.
        'siswa' => ['academic:view', 'assessment:view', 'report:view', 'tahfiz:view'],
        'wali-murid' => ['academic:view', 'student:view', 'finance:view', 'assessment:view', 'report:view', 'tahfiz:view'],
        // Library Manager: mengelola konfigurasi operasional perpustakaan
        // (settings, lokasi, jam, penutupan, penomoran) — tanpa hak hapus.
        'library-manager' => [
            'library:view', 'library:create', 'library:update',
            'library:settings.view', 'library:settings.update',
            'library:location.view', 'library:location.create', 'library:location.update', 'library:location.delete',
            'library:operating_hours.view', 'library:operating_hours.update',
            'library:closure.view', 'library:closure.create', 'library:closure.update', 'library:closure.delete',
            'library:numbering.view', 'library:numbering.update',
            'library:dashboard.view', 'library:audit.view',
            'report:view',
        ],
        // Pustakawan: operasional harian (katalog & sirkulasi menyusul pada
        // Step berikutnya). Tidak mengubah konfigurasi maupun penomoran.
        'pustakawan' => [
            'library:view', 'library:create', 'library:update',
            'library:location.view', 'library:location.create', 'library:location.update',
            'library:operating_hours.view',
            'library:closure.view', 'library:closure.create', 'library:closure.update',
            'library:dashboard.view',
            'report:view',
        ],
    ];

    public function run(): void
    {
        // Aksi master Library dibangkitkan dari registry (satu sumber
        // kebenaran), sehingga menambah master baru otomatis menambah
        // permission-nya tanpa menyunting seeder ini.
        $this->modules['library'] = array_values(array_unique(array_merge(
            $this->modules['library'],
            $this->libraryMasterActions(),
        )));

        // Buat seluruh permission
        $allPermissions = [];
        foreach ($this->modules as $module => $actions) {
            foreach ($actions as $action) {
                $name = "{$module}.{$action}";
                $allPermissions[] = $name;
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
        }

        // Buat role & assign permission
        foreach ($this->roles as $roleName => $scopes) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            if (in_array('*', $scopes, true)) {
                $role->syncPermissions($allPermissions);
                continue;
            }

            $granted = [];
            foreach ($scopes as $scope) {
                if (str_contains($scope, ':')) {
                    [$module, $action] = explode(':', $scope);
                    $granted[] = "{$module}.{$action}";
                    continue;
                }

                foreach ($this->modules[$scope] ?? [] as $action) {
                    $granted[] = "{$scope}.{$action}";
                }
            }

            $role->syncPermissions(array_unique($granted));
        }

        $this->grantLibraryMasterPermissions();
        $this->grantLibraryModulePermissions();
        $this->grantPpdbPermissions();
    }

    /**
     * Peran operasional PPDB.
     *
     * `panitia-ppdb` = panitia penerimaan (kelola konfigurasi & pendaftaran,
     * verifikasi dokumen, jadwalkan seleksi) tanpa hak hapus.
     * Bendahara memverifikasi pembayaran; kepala sekolah hanya melihat laporan.
     */
    protected function grantPpdbPermissions(): void
    {
        $role = Role::firstOrCreate(['name' => 'panitia-ppdb', 'guard_name' => 'web']);

        $role->syncPermissions(array_map(fn (string $permission) => "ppdb.{$permission}", [
            'view',
            'period.manage', 'wave.manage', 'program.manage', 'form.manage',
            'question.manage', 'document.manage', 'fee.manage',
            'registration.view', 'registration.manage', 'registration.verify',
            'payment.view', 'payment.verify',
            'selection.manage', 'announcement.manage', 'account.manage',
            'report.view', 'report.export',
        ]));

        // Bendahara: verifikasi pembayaran PPDB.
        Role::query()->where('name', 'bendahara')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['ppdb.view', 'ppdb.payment.view', 'ppdb.payment.verify', 'ppdb.report.view']);

        // Kepala sekolah: pemantauan.
        Role::query()->where('name', 'kepala-sekolah')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['ppdb.view', 'ppdb.registration.view', 'ppdb.payment.view', 'ppdb.report.view', 'ppdb.report.export']);
    }

    /**
     * Permission modul Library tahap lanjut (Fase 3: katalog, koleksi,
     * keanggotaan, kartu, OPAC) untuk peran operasional.
     */
    protected function grantLibraryModulePermissions(): void
    {
        $grants = [
            // Library manager: mengelola, tanpa hak hapus (konsisten dengan master).
            'library-manager' => [
                'catalog.view', 'catalog.create', 'catalog.update',
                'item.view', 'item.create', 'item.update',
                'member.view', 'member.create', 'member.update',
                'membership.view', 'membership.create', 'membership.update',
                'member_card.view', 'member_card.generate',
                'opac.view', 'opac.manage',
                'circulation.view', 'circulation.checkout', 'circulation.return', 'circulation.renew', 'circulation.manage',
                'fine.view', 'fine.manage',
                'reservation.view', 'reservation.create', 'reservation.manage',
                'acquisition.view', 'acquisition.create', 'acquisition.manage',
                'inventory.view', 'inventory.manage',
                'report.view', 'report.export',
            ],
            // Pustakawan: operasional harian katalog, sirkulasi, dan anggota.
            'pustakawan' => [
                'catalog.view', 'catalog.create', 'catalog.update',
                'item.view', 'item.create', 'item.update',
                'member.view', 'member.create', 'member.update',
                'membership.view', 'membership.update',
                'member_card.view', 'member_card.generate',
                'opac.view',
                'circulation.view', 'circulation.checkout', 'circulation.return', 'circulation.renew',
                'fine.view', 'fine.manage',
                'reservation.view', 'reservation.create', 'reservation.manage',
                'acquisition.view', 'inventory.view',
                'report.view',
            ],
            // Kepala sekolah: hanya melihat (termasuk laporan).
            'kepala-sekolah' => [
                'catalog.view', 'item.view', 'member.view', 'membership.view', 'opac.view',
                'circulation.view', 'fine.view', 'reservation.view',
                'acquisition.view', 'inventory.view', 'report.view', 'report.export',
            ],
            // Siswa & wali: katalog publik (tanpa permission pun dapat diakses,
            // permission ini untuk visibilitas menu).
            'siswa' => ['opac.view'],
            'wali-murid' => ['opac.view'],
        ];

        foreach ($grants as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            $names = array_map(fn (string $permission) => "library.{$permission}", $permissions);
            $role->givePermissionTo($names);
        }
    }

    /**
     * Aksi permission master Library, mis. "master.view",
     * "collection_type.create" — dipakai agar scope modul `library`
     * (admin) otomatis mencakup seluruh master.
     *
     * @return list<string>
     */
    protected function libraryMasterActions(): array
    {
        $actions = [];

        foreach (LibraryMasterRegistry::permissionNames() as $name) {
            if (str_starts_with($name, 'library.')) {
                $actions[] = substr($name, strlen('library.'));
            }
        }

        return $actions;
    }

    /**
     * Master data Library tidak diambil dari scope modul penuh untuk semua
     * peran: pengelola perpustakaan boleh mengubah master, pustakawan boleh
     * menambah/mengubah (tanpa menghapus), kepala sekolah hanya melihat.
     */
    protected function grantLibraryMasterPermissions(): void
    {
        $levels = [
            'library-manager' => ['manage'],       // view + create + update
            'pustakawan' => ['view', 'create', 'update'],
            'kepala-sekolah' => ['view'],
        ];

        $names = LibraryMasterRegistry::permissionNames();

        foreach ($levels as $roleName => $wanted) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            $actions = in_array('manage', $wanted, true) ? ['view', 'create', 'update'] : $wanted;
            $granted = [];

            foreach ($names as $name) {
                if ($name === 'library.master.view') {
                    $granted[] = $name;

                    continue;
                }

                foreach ($actions as $action) {
                    if (str_ends_with($name, '.'.$action)) {
                        $granted[] = $name;
                    }
                }
            }

            $role->givePermissionTo(array_unique($granted));
        }
    }
}
