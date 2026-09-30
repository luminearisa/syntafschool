<?php

namespace App\Modules\Clinic\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin granular Modul Clinic / UKS.
 *
 * Dua lapis keamanan yang sengaja dipisah:
 *
 * 1. **Izin (file ini)** menentukan *layar dan aksi* yang boleh dibuka.
 * 2. **ClinicAccessService** menentukan *baris* yang boleh dilihat.
 *
 * Pemegang `clinic.visit.view` yang bukan petugas klinik (mis. orang tua) tetap
 * hanya melihat kunjungan anaknya sendiri, karena cakupan baris ditegakkan di
 * service. Karena itu peran orang tua tidak pernah diberikan `clinic.record.view.all`.
 */
class ClinicPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        // Umum
        'clinic.view',
        'clinic.manage',
        'clinic.audit.view',

        // Pasien & rekam medis
        'clinic.patient.view',
        'clinic.patient.create',
        'clinic.patient.manage',
        'clinic.record.view',
        'clinic.record.view.all',
        'clinic.record.manage',

        // Kunjungan & alur klinis
        'clinic.visit.view',
        'clinic.visit.create',
        'clinic.visit.triage',
        'clinic.visit.manage',
        'clinic.assessment.create',
        'clinic.diagnosis.create',
        'clinic.procedure.create',
        'clinic.observation.manage',

        // Obat & farmasi
        'clinic.medication.view',
        'clinic.medication.manage',
        'clinic.prescription.view',
        'clinic.prescription.create',
        'clinic.prescription.dispense',
        'clinic.administration.create',

        // Gawat darurat, cedera, rujukan
        'clinic.emergency.view',
        'clinic.emergency.manage',
        'clinic.injury.view',
        'clinic.injury.manage',
        'clinic.referral.view',
        'clinic.referral.manage',

        // Skrining & imunisasi
        'clinic.screening.view',
        'clinic.screening.manage',
        'clinic.vaccination.manage',

        // Surat & dokumen
        'clinic.certificate.view',
        'clinic.certificate.request',
        'clinic.certificate.issue',
        'clinic.document.view',
        'clinic.document.manage',

        // Laporan, antrean, portal
        'clinic.report.view',
        'clinic.report.export',
        'clinic.queue.manage',
        'clinic.portal.view',
    ];

    /**
     * Izin klinis penuh untuk dokter & perawat (dengan pembatasan sesuai
     * kewenangan; kewenangan rinci ditegakkan ClinicAccessService per peran klinis).
     */
    private const CLINICAL_FULL = [
        'clinic.view', 'clinic.patient.view', 'clinic.patient.create', 'clinic.patient.manage',
        'clinic.record.view', 'clinic.record.view.all', 'clinic.record.manage',
        'clinic.visit.view', 'clinic.visit.create', 'clinic.visit.triage', 'clinic.visit.manage',
        'clinic.assessment.create', 'clinic.diagnosis.create', 'clinic.procedure.create',
        'clinic.observation.manage', 'clinic.medication.view', 'clinic.prescription.view',
        'clinic.prescription.create', 'clinic.prescription.dispense', 'clinic.administration.create',
        'clinic.emergency.view', 'clinic.emergency.manage', 'clinic.injury.view', 'clinic.injury.manage',
        'clinic.referral.view', 'clinic.referral.manage', 'clinic.screening.view', 'clinic.screening.manage',
        'clinic.vaccination.manage', 'clinic.certificate.view', 'clinic.certificate.request',
        'clinic.certificate.issue', 'clinic.document.view', 'clinic.document.manage',
        'clinic.report.view', 'clinic.report.export', 'clinic.queue.manage',
    ];

    /**
     * Peta izin per peran ERP. Peran yang tidak disebut tidak mendapat izin
     * Clinic sama sekali.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'super-admin' => ['*'],
        'admin-sekolah' => ['*'],

        // Admin klinik: mengatur lokasi, petugas, master, laporan.
        'admin-klinik' => [
            'clinic.view', 'clinic.manage', 'clinic.audit.view', 'clinic.patient.view',
            'clinic.patient.create', 'clinic.patient.manage', 'clinic.record.view',
            'clinic.record.view.all', 'clinic.visit.view', 'clinic.visit.create', 'clinic.visit.manage',
            'clinic.medication.view', 'clinic.medication.manage', 'clinic.prescription.view',
            'clinic.prescription.dispense', 'clinic.emergency.view', 'clinic.injury.view',
            'clinic.referral.view', 'clinic.screening.view', 'clinic.screening.manage',
            'clinic.vaccination.manage', 'clinic.certificate.view', 'clinic.certificate.issue',
            'clinic.document.view', 'clinic.document.manage', 'clinic.report.view',
            'clinic.report.export', 'clinic.queue.manage',
        ],

        'dokter' => self::CLINICAL_FULL,
        'perawat' => self::CLINICAL_FULL,

        // Petugas UKS: menerima pasien, triase dasar, tindakan dasar, laporan.
        'petugas-uks' => [
            'clinic.view', 'clinic.patient.view', 'clinic.patient.create', 'clinic.record.view',
            'clinic.record.view.all', 'clinic.visit.view', 'clinic.visit.create', 'clinic.visit.triage',
            'clinic.visit.manage', 'clinic.procedure.create', 'clinic.medication.view',
            'clinic.prescription.view', 'clinic.administration.create', 'clinic.emergency.view',
            'clinic.emergency.manage', 'clinic.injury.view', 'clinic.injury.manage',
            'clinic.referral.view', 'clinic.screening.view', 'clinic.screening.manage',
            'clinic.certificate.view', 'clinic.certificate.request', 'clinic.document.view',
            'clinic.report.view', 'clinic.queue.manage',
        ],

        // Tenaga kesehatan (peran lama) tetap diakui sebagai operator UKS.
        'petugas-kesehatan' => [
            'clinic.view', 'clinic.patient.view', 'clinic.patient.create', 'clinic.record.view',
            'clinic.record.view.all', 'clinic.visit.view', 'clinic.visit.create', 'clinic.visit.triage',
            'clinic.visit.manage', 'clinic.procedure.create', 'clinic.medication.view',
            'clinic.prescription.view', 'clinic.prescription.create', 'clinic.prescription.dispense',
            'clinic.administration.create', 'clinic.emergency.view', 'clinic.emergency.manage',
            'clinic.injury.view', 'clinic.injury.manage', 'clinic.referral.view', 'clinic.referral.manage',
            'clinic.screening.view', 'clinic.screening.manage', 'clinic.vaccination.manage',
            'clinic.certificate.view', 'clinic.certificate.request', 'clinic.document.view',
            'clinic.report.view', 'clinic.queue.manage',
        ],

        // Farmasi: menyerahkan obat & mengelola master obat, tanpa diagnosis.
        'petugas-farmasi' => [
            'clinic.view', 'clinic.patient.view', 'clinic.record.view', 'clinic.record.view.all',
            'clinic.visit.view', 'clinic.medication.view', 'clinic.medication.manage',
            'clinic.prescription.view', 'clinic.prescription.dispense', 'clinic.administration.create',
            'clinic.report.view',
        ],

        // Resepsionis: mendaftarkan & mengarahkan, tanpa membuka isi klinis.
        'resepsionis-klinik' => [
            'clinic.view', 'clinic.patient.view', 'clinic.patient.create', 'clinic.patient.manage',
            'clinic.visit.view', 'clinic.visit.create', 'clinic.queue.manage', 'clinic.report.view',
        ],

        // Kepala sekolah & pengawas: melihat & melaporkan, tanpa mengubah catatan.
        'kepala-sekolah' => [
            'clinic.view', 'clinic.patient.view', 'clinic.record.view', 'clinic.record.view.all',
            'clinic.visit.view', 'clinic.medication.view', 'clinic.prescription.view',
            'clinic.emergency.view', 'clinic.injury.view', 'clinic.referral.view',
            'clinic.screening.view', 'clinic.certificate.view', 'clinic.document.view',
            'clinic.report.view', 'clinic.report.export', 'clinic.audit.view',
        ],
        'kepala-unit' => ['clinic.view', 'clinic.visit.view', 'clinic.report.view'],
        'auditor' => ['clinic.view', 'clinic.report.view', 'clinic.audit.view'],

        // Asrama: hanya melihat kunjungan siswa asrama & rujukan, tanpa isi rekam medis.
        'kepala-asrama' => ['clinic.view', 'clinic.patient.view', 'clinic.visit.view', 'clinic.injury.view', 'clinic.referral.view'],
        'musyrif' => ['clinic.view', 'clinic.patient.view', 'clinic.visit.view'],
        'wali-kamar' => ['clinic.view', 'clinic.patient.view', 'clinic.visit.view'],

        // Portal: orang tua & siswa. Cakupan barisnya hanya anak sendiri/dirinya.
        'wali-murid' => ['clinic.view', 'clinic.portal.view', 'clinic.certificate.view', 'clinic.document.view'],
        'siswa' => ['clinic.view', 'clinic.portal.view', 'clinic.certificate.view'],

        // Guru & wali kelas: TIDAK diberi akses rekam medis (keputusan privasi).
        // Bila sekolah ingin memberi izin melihat status "sedang di UKS", gunakan
        // clinic.queue.manage untuk petugas, bukan clinic.record.view untuk guru.
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Peran klinis baru yang belum ada di ERP dibuat di sini agar sekolah
        // dapat menugaskan dokter/perawat/farmasi tanpa menambah peran manual.
        foreach (['admin-klinik', 'dokter', 'perawat', 'petugas-uks', 'petugas-farmasi', 'resepsionis-klinik'] as $roleName) {
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
