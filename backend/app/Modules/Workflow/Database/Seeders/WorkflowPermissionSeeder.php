<?php

namespace App\Modules\Workflow\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Izin & peran Workflow & Approval Engine (§3, §10, §12).
 *
 * Dua keluarga peran, dan pemisahannya disengaja:
 *
 *  - **Peran sistem** — `ERP_ADMIN`, `WORKFLOW_ADMIN`, `WORKFLOW_MANAGER`,
 *    `AUDITOR`. Peran ini mengatur *cara kerja* engine (definisi, versi, SLA,
 *    direktori) atau membaca jejaknya. **Tidak satu pun otomatis bisa menyetujui
 *    request bisnis** (§10) — `WORKFLOW_ADMIN` bisa mengubah definisi pengadaan
 *    tanpa boleh menyetujui satu pun pengadaan.
 *  - **Peran workflow** — REQUESTER, APPROVER, REVIEWER, OBSERVER, DELEGATE,
 *    ESCALATION_APPROVER, WORKFLOW_OWNER — bukan peran RBAC, melainkan *posisi
 *    seseorang di dalam satu instance*. Engine menetapkannya saat resolver
 *    berjalan; tidak ada yang perlu "diberikan" di sini.
 *
 * Peran ERP yang sudah ada (super-admin, kepala-sekolah, bendahara, admin-sekolah)
 * diberi izin yang sesuai perannya sebagai approver bisnis, tanpa mengubah
 * kewenangan mereka di modul asalnya.
 */
class WorkflowPermissionSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    protected array $permissions = [
        'workflow.view' => ['Lihat menu & data dasar workflow'],

        // Konfigurasi — Workflow Admin.
        'workflow.definition.view' => ['Lihat definisi & versi'],
        'workflow.definition.manage' => ['Buat/ubah definisi & draf versi'],
        'workflow.version.publish' => ['Terbitkan/arsipkan versi workflow'],
        'workflow.settings.view' => ['Lihat kalender & direktori workflow'],
        'workflow.settings.manage' => ['Ubah kalender kerja'],
        'workflow.directory.manage' => ['Kelola peta organisasi workflow'],

        // Request — Requester.
        'workflow.request.create' => ['Buat request workflow'],
        'workflow.request.view' => ['Lihat request sesuai cakupan'],
        'workflow.request.submit' => ['Kirim/revisi request'],
        'workflow.request.cancel' => ['Batalkan request'],
        'workflow.request.manage' => ['Kelola request lintas pemohon'],

        // Keputusan — Approver/Reviewer.
        'workflow.approval.view' => ['Lihat penugasan persetujuan'],
        'workflow.approval.decide' => ['Berikan keputusan (setujui/tolak/kembalikan)'],
        'workflow.approval.reassign' => ['Alihkan atau tetapkan approver'],
        'workflow.approval.skip' => ['Lewati step dengan alasan'],
        'workflow.approval.override' => ['Override dengan alasan (self-approval/SoD)'],

        // Delegasi — Delegate.
        'workflow.delegation.view' => ['Lihat delegasi'],
        'workflow.delegation.manage' => ['Buat/cabut delegasi'],

        // SLA & eskalasi — Escalation Approver / Workflow Manager.
        'workflow.sla.view' => ['Lihat status SLA & eskalasi'],
        'workflow.sla.manage' => ['Jalankan sapuan SLA & atur kebijakan'],

        // Pemantauan — Workflow Owner & Auditor.
        'workflow.instance.view' => ['Lihat seluruh instance (lintas cakupan)'],
        'workflow.analytics.view' => ['Lihat analitik operasional'],
        'workflow.audit.view' => ['Lihat & verifikasi jejak audit'],
    ];

    /** @var array<string, list<string>> */
    protected array $roles = [
        'ERP_ADMIN' => ['*'],
        'WORKFLOW_ADMIN' => [
            'workflow.view',
            'workflow.definition.view', 'workflow.definition.manage', 'workflow.version.publish',
            'workflow.settings.view', 'workflow.settings.manage', 'workflow.directory.manage',
            'workflow.instance.view', 'workflow.analytics.view',
            // Catatan: tanpa workflow.approval.decide — §10.
        ],
        'WORKFLOW_MANAGER' => [
            'workflow.view',
            'workflow.instance.view', 'workflow.request.view', 'workflow.request.manage', 'workflow.request.cancel',
            'workflow.approval.view', 'workflow.approval.reassign', 'workflow.approval.skip', 'workflow.approval.override',
            'workflow.delegation.view', 'workflow.delegation.manage',
            'workflow.sla.view', 'workflow.sla.manage',
            'workflow.analytics.view',
        ],
        'AUDITOR' => [
            'workflow.view', 'workflow.instance.view', 'workflow.definition.view',
            'workflow.approval.view', 'workflow.sla.view', 'workflow.analytics.view', 'workflow.audit.view',
            'workflow.request.view', 'workflow.delegation.view',
            // Tanpa hak menulis apa pun.
        ],
    ];

    /** Peran ERP yang sudah ada → izin workflow yang pantas. */
    /** @var array<string, list<string>> */
    protected array $grants = [
        'super-admin' => ['*'],
        // Kepala sekolah: approver tingkat akhir + pemantau.
        'kepala-sekolah' => [
            'workflow.view', 'workflow.request.view', 'workflow.approval.view', 'workflow.approval.decide',
            'workflow.approval.reassign', 'workflow.delegation.view', 'workflow.delegation.manage',
            'workflow.sla.view', 'workflow.analytics.view', 'workflow.instance.view', 'workflow.audit.view',
            'workflow.definition.view',
        ],
        // Bendahara: approver keuangan (setelah pemeriksaan anggaran).
        'bendahara' => [
            'workflow.view', 'workflow.request.create', 'workflow.request.view', 'workflow.request.submit',
            'workflow.approval.view', 'workflow.approval.decide', 'workflow.delegation.view', 'workflow.delegation.manage',
        ],
        // Admin sekolah: operator request & reviewer kelengkapan dokumen.
        'admin-sekolah' => [
            'workflow.view', 'workflow.request.create', 'workflow.request.view', 'workflow.request.submit',
            'workflow.request.cancel', 'workflow.approval.view', 'workflow.approval.decide',
        ],
        // Kepala unit / wakil: mengubah draf alur unit sendiri.
        'wakil-kepala-sekolah' => [
            'workflow.view', 'workflow.definition.view', 'workflow.request.create', 'workflow.request.view',
            'workflow.request.submit', 'workflow.approval.view', 'workflow.approval.decide', 'workflow.analytics.view',
        ],
    ];

    /**
     * Peran pegawai biasa: boleh melihat & memutuskan penugasan MILIKNYA sendiri
     * (inbox disaring `approver_id`, keputusan hanya pada penugasan yang
     * ditujukan kepadanya) dan mengelola permintaannya sendiri. Sengaja TANPA
     * `workflow.request.create` — membuat request baru (termasuk atas nama
     * orang lain lewat `requester_id`) tetap hak peran administratif.
     *
     * @var list<string>
     */
    protected array $employeeRoles = [
        'guru', 'wali-kelas', 'staf', 'kepala-unit', 'kepala-asrama', 'musyrif', 'pustakawan',
        'library-manager', 'pengadaan', 'petugas-absensi', 'petugas-kedisiplinan', 'petugas-kesehatan',
        'petugas-perizinan', 'petugas-wallet', 'kasir', 'finance-officer', 'finance-manager',
        'finance-admin', 'admin-boarding', 'auditor',
    ];

    /** @var list<string> */
    protected array $employeeGrants = [
        'workflow.view', 'workflow.request.view', 'workflow.request.submit', 'workflow.request.cancel',
        'workflow.approval.view', 'workflow.approval.decide',
    ];

    public function run(): void
    {
        foreach (array_keys($this->permissions) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach ($this->roles as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($this->expand($permissions));
        }

        foreach ($this->grants as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            $role->givePermissionTo($this->expand($permissions));
        }

        foreach ($this->employeeRoles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role !== null) {
                $role->givePermissionTo($this->expand($this->employeeGrants));
            }
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    protected function expand(array $permissions): array
    {
        if (in_array('*', $permissions, true)) {
            return array_keys($this->permissions);
        }

        return $permissions;
    }
}
