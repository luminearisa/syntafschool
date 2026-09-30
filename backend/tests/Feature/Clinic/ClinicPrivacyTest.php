<?php

namespace Tests\Feature\Clinic;

use App\Modules\Clinic\Models\ClinicDocument;
use App\Modules\Clinic\Models\ClinicVisit;

/**
 * Privasi & otorisasi tingkat objek.
 *
 * Inilah uji yang paling penting bagi modul kesehatan: bukan "apakah fitur
 * jalan", melainkan "apakah data pasien tetap terlindung". Setiap uji di sini
 * mewakili kebocoran nyata yang mungkin terjadi bila pemeriksaan cakupan baris
 * dilewatkan:
 *
 * - orang tua A membuka anak B,
 * - guru membaca rekam medis siswa yang diajar,
 * - siswa membaca rekam medis temannya,
 * - petugas non-klinik menembak ID kunjungan langsung.
 */
class ClinicPrivacyTest extends ClinicTestCase
{
    public function test_orang_tua_hanya_melihat_anaknya_sendiri(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);

        $childA = $this->makePatientWithGuardian('Anak A');
        $childB = $this->makePatientWithGuardian('Anak B');

        $this->visit($childA['patient']);
        $this->visit($childB['patient']);

        // ---- Orang tua A
        $this->actAs($childA['guardian_user']);

        $children = $this->getJson('/api/v1/clinic/portal/children')->assertOk();
        $registered = $children->json('data.registered');

        $this->assertCount(1, $registered, 'Orang tua A hanya boleh melihat satu anak');
        $this->assertSame($childA['patient']->id, $registered[0]['patient_id']);

        // Anak sendiri: boleh.
        $this->getJson("/api/v1/clinic/portal/patients/{$childA['patient']->id}")->assertOk();
        $this->getJson("/api/v1/clinic/portal/patients/{$childA['patient']->id}/visits")->assertOk();

        // Anak orang lain: DITOLAK, meski ID-nya benar.
        $this->getJson("/api/v1/clinic/portal/patients/{$childB['patient']->id}")->assertForbidden();
        $this->getJson("/api/v1/clinic/portal/patients/{$childB['patient']->id}/visits")->assertForbidden();
        $this->getJson("/api/v1/clinic/patients/{$childB['patient']->id}")->assertForbidden();
    }

    public function test_guru_tidak_otomatis_mendapat_akses_rekam_medis_siswa_yang_diajar(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);

        $world = $this->makePatientWithGuardian('Siswa Diampu');
        $visit = $this->visit($world['patient']);

        // Guru (dan bahkan wali kelas) tetap bukan pemegang izin rekam medis.
        foreach (['guru', 'wali-kelas'] as $role) {
            $teacher = $this->actingAsRole($role);

            $this->getJson("/api/v1/clinic/patients/{$world['patient']->id}")->assertForbidden();
            $this->getJson("/api/v1/clinic/visits/{$visit->id}")->assertForbidden();
            $this->getJson('/api/v1/clinic/visits')->assertForbidden();

            $this->assertFalse(
                $teacher->hasPermissionTo('clinic.record.view'),
                "Peran {$role} tidak boleh memegang izin rekam medis",
            );
        }
    }

    public function test_siswa_hanya_melihat_rekam_medis_dirinya(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);

        $studentA = $this->makePatientWithGuardian('Siswa A');
        $studentB = $this->makePatientWithGuardian('Siswa B');

        $visitA = $this->visit($studentA['patient']);
        $visitB = $this->visit($studentB['patient']);

        $this->actAs($this->accountFor('siswa', $studentA['student']));

        // Dirinya sendiri: boleh lewat portal.
        $this->getJson("/api/v1/clinic/portal/patients/{$studentA['patient']->id}/visits")->assertOk();

        // Workspace petugas bukan jalur siswa: siswa memakai portal (baca-saja,
        // sudah disaring kebijakan privasi). Ini pembatasan yang disengaja.
        $this->getJson("/api/v1/clinic/visits/{$visitA->id}")->assertForbidden();

        // Temannya: ditolak.
        $this->getJson("/api/v1/clinic/portal/patients/{$studentB['patient']->id}/visits")->assertForbidden();
        $this->getJson("/api/v1/clinic/visits/{$visitB->id}")->assertForbidden();
    }

    public function test_petugas_non_klinik_tidak_bisa_menembak_id_kunjungan_langsung(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);
        $world = $this->makePatientWithGuardian('Pasien Rahasia');
        $visit = $this->visit($world['patient']);

        // Staf administrasi dengan izin melihat layar kunjungan, tetapi bukan
        // petugas klinik: cakupan barisnya kosong.
        $staff = $this->actingAsRole('staf');
        $this->getJson("/api/v1/clinic/visits/{$visit->id}")->assertForbidden();

        // Auditor hanya boleh melihat laporan, bukan rekam medis pasien.
        $auditor = $this->actingAsRole('auditor');
        $this->getJson("/api/v1/clinic/visits/{$visit->id}")->assertForbidden();
        $this->getJson("/api/v1/clinic/patients/{$world['patient']->id}")->assertForbidden();
    }

    public function test_orang_tua_tidak_bisa_mengubah_catatan_klinis(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);
        $world = $this->makePatientWithGuardian('Anak Uji');
        $visit = $this->visit($world['patient']);

        $this->actAs($world['guardian_user']);

        // Portal bersifat baca-saja: semua endpoint tulis ditolak middleware izin.
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/triage", ['chief_complaint' => 'Diubah orang tua'])
            ->assertForbidden();
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/vitals", ['temperature' => 40])
            ->assertForbidden();
        $this->postJson("/api/v1/clinic/patients/{$world['patient']->id}/allergies", ['allergen' => 'Palsu'])
            ->assertForbidden();
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/discharge", ['status' => 'HOME'])
            ->assertForbidden();

        $this->assertSame(0, $visit->vitals()->count());
        $this->assertSame(0, $visit->patient->allergies()->count());
    }

    public function test_dokumen_klinis_tidak_bisa_diunduh_orang_tua_atau_pihak_luar(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);

        $world = $this->makePatientWithGuardian('Anak Dokumen');

        // Dokumen ber-visibilitas CLINICAL: hanya untuk petugas klinik.
        $document = ClinicDocument::query()->create([
            'document_number' => 'DOC-TEST-0001',
            'patient_id' => $world['patient']->id,
            'type' => 'TREATMENT_NOTE',
            'title' => 'Catatan internal',
            'disk' => 'clinic',
            'path' => 'patients/1/clinical/catatan.pdf',
            'visibility' => 'CLINICAL',
            'is_current' => true,
            'uploaded_by' => $nurse['user']->id,
        ]);

        foreach ([$world['guardian_user'], $this->accountFor('siswa', $world['student'])] as $outsider) {
            $this->actAs($outsider);
            $this->getJson("/api/v1/clinic/documents/{$document->id}/download")->assertForbidden();
        }

        // Daftar dokumen portal pun tidak menampilkan dokumen klinis.
        $this->actAs($world['guardian_user']);
        $list = $this->getJson("/api/v1/clinic/portal/patients/{$world['patient']->id}/documents")->assertOk();
        $this->assertSame([], $list->json('data'));

        // Petugas klinik boleh (berkas tidak ada di disk, jadi 422 dari provider).
        $this->actAs($nurse['user']);
        $this->getJson("/api/v1/clinic/documents/{$document->id}/download")->assertStatus(422);
    }

    public function test_ekspor_laporan_kesehatan_hanya_untuk_pemegang_izin_ekspor(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);

        // Perawat boleh menyusun laporan, tetapi ekspor diatur izin tersendiri.
        $this->getJson('/api/v1/clinic/reports/daily')->assertOk();

        // Perawat berizin ekspor: berkas CSV benar-benar dihasilkan.
        $this->getJson('/api/v1/clinic/reports/export/daily')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Auditor hanya melihat laporan, tidak mengekspor data pasien.
        $this->actAs($this->actingAsRole('auditor'));
        $this->getJson('/api/v1/clinic/reports/export/daily')->assertForbidden();
    }

    public function test_setiap_pembukaan_rekam_medis_tercatat_di_jejak_audit(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);

        $world = $this->makePatientWithGuardian('Pasien Audit');

        $this->getJson("/api/v1/clinic/patients/{$world['patient']->id}")->assertOk();

        $this->assertDatabaseHas('clinic_audit_logs', [
            'action' => 'VIEW',
            'patient_id' => $world['patient']->id,
            'actor_id' => $nurse['user']->id,
        ]);
    }

    public function test_orang_tua_tidak_melihat_diagnosis_ketika_kebijakan_menyembunyikannya(): void
    {
        config(['clinic.privacy.parent_visible_diagnosis' => false]);

        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($doctor['user']);

        $world = $this->makePatientWithGuardian('Anak Diagnosis');
        $visit = $this->visit($world['patient']);

        $this->postJson("/api/v1/clinic/visits/{$visit->id}/diagnoses", [
            'diagnosis_code' => 'J06',
            'type' => 'PRIMARY',
        ])->assertCreated();

        $this->actAs($world['guardian_user']);
        $response = $this->getJson("/api/v1/clinic/portal/patients/{$world['patient']->id}/visits")->assertOk();

        $this->assertNull($response->json('data.0.diagnosis'), 'Kebijakan menyembunyikan diagnosis dari orang tua');
    }

    public function test_data_kesehatan_tidak_diwarisi_dari_kunjungan_anak_lain_di_daftar(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);

        $childA = $this->makePatientWithGuardian('Anak Satu');
        $childB = $this->makePatientWithGuardian('Anak Dua');
        $this->visit($childA['patient']);
        $this->visit($childB['patient']);

        $this->actAs($childA['guardian_user']);

        // Daftar kunjungan milik petugas bukan jalur orang tua.
        $this->getJson('/api/v1/clinic/visits')->assertForbidden();

        // Portal hanya menampilkan anaknya sendiri.
        $children = $this->getJson('/api/v1/clinic/portal/children')->assertOk()->json('data.registered');
        $this->assertCount(1, $children);
        $this->assertSame($childA['patient']->id, $children[0]['patient_id']);

        // Dan tidak bisa membuka kunjungan anak lain lewat portal.
        $this->getJson("/api/v1/clinic/portal/patients/{$childB['patient']->id}/visits")->assertForbidden();
    }

    public function test_kunjungan_yang_sudah_ditutup_tidak_bisa_diubah(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);

        $world = $this->makePatientWithGuardian('Pasien Tertutup');
        $visit = $this->visit($world['patient']);

        $this->putJson("/api/v1/clinic/visits/{$visit->id}/status", ['status' => 'CANCELLED'])->assertOk();

        $this->assertSame('CANCELLED', $visit->fresh()->status);

        // Kunjungan batal: catatan klinis baru ditolak (422), bukan diam-diam masuk.
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/triage", [
            'chief_complaint' => 'Mencoba menambah setelah batal',
        ])->assertStatus(422);

        $this->assertSame(0, $visit->triage()->count());
    }

    public function test_riwayat_lengkap_tidak_bisa_dihapus(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $this->actAs($nurse['user']);

        $world = $this->makePatientWithGuardian('Pasien Riwayat');
        $visit = $this->visit($world['patient']);
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/vitals", ['temperature' => 37])->assertCreated();

        // Tidak ada endpoint DELETE untuk catatan klinis mana pun.
        foreach ([
            "/api/v1/clinic/visits/{$visit->id}",
            "/api/v1/clinic/patients/{$world['patient']->id}",
            "/api/v1/clinic/vitals/{$visit->vitals()->first()->id}",
        ] as $url) {
            // 404/405 sama-sama berarti tidak ada endpoint hapus. Yang penting
            // catatan klinis tidak dapat dihilangkan dari API.
            $this->assertContains($this->deleteJson($url)->status(), [404, 405], "URL {$url} tidak boleh punya aksi hapus");
        }

        $this->assertSame(1, ClinicVisit::query()->count());
    }
}
