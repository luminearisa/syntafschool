<?php

namespace Tests\Feature\Clinic;

use App\Modules\Clinic\Models\ClinicHealthCertificate;
use App\Modules\Clinic\Models\ClinicNotificationLog;
use App\Modules\Clinic\Models\ClinicScreening;

/**
 * Skrining berkala, imunisasi, surat keterangan kesehatan, dan dokumen.
 *
 * Dua hal yang paling mudah salah pada modul sekolah: skrining massal yang
 * menghitung siswa dua kali karena petugas mengirim ulang formulir, dan surat
 * sehat yang sudah "terbit" tanpa ditinjau tenaga berwenang. Keduanya diuji.
 */
class ClinicScreeningCertificateTest extends ClinicTestCase
{
    public function test_program_skrining_mendaftar_seluruh_siswa_satu_kelas(): void
    {
        $this->actAs($this->clinicStaff('CLINIC_ADMIN')['user']);

        $class = $this->makeClass();
        $first = $this->makePatientWithGuardian('Skrining Satu', $class);
        $second = $this->makePatientWithGuardian('Skrining Dua', $class);

        $program = $this->postJson('/api/v1/clinic/screening-programs', [
            'code' => 'SKR-2026-01',
            'name' => 'Skrining Kesehatan Tahunan 2026',
            'type' => 'GENERAL',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'status' => 'ACTIVE',
            'notify_parent' => true,
        ])->assertCreated();

        $programId = $program->json('data.id');

        $targets = $this->getJson("/api/v1/clinic/screening-programs/{$programId}/targets?school_class_id={$class->id}")
            ->assertOk();

        $this->assertSame(2, $targets->json('data.total'));
        $this->assertSame(0, $targets->json('data.progress.screened'));

        // Catat hasil skrining untuk kedua siswa.
        foreach ([$first, $second] as $index => $world) {
            $this->postJson("/api/v1/clinic/screening-programs/{$programId}/screenings", [
                'student_id' => $world['student']->id,
                'type' => 'GENERAL',
                'screened_at' => now()->toDateString(),
                'height_cm' => $index === 0 ? 150 : 162,
                'weight_kg' => $index === 0 ? 45 : 70,
                'result' => $index === 0 ? 'NORMAL' : 'NEEDS_ATTENTION',
                'recommendation' => $index === 0 ? null : 'Rujuk konseling gizi',
                'follow_up_required' => $index === 1,
            ])->assertCreated();
        }

        // IMT dihitung server-side dan diklasifikasikan.
        $normal = ClinicScreening::query()->where('height_cm', 150)->firstOrFail();
        $this->assertSame(20.0, (float) $normal->bmi);
        $this->assertSame('NORMAL', $normal->bmi_category);

        $overweight = ClinicScreening::query()->where('height_cm', 162)->firstOrFail();
        $this->assertSame('LEBIH', $overweight->bmi_category);

        $progress = $this->getJson("/api/v1/clinic/screening-programs/{$programId}/targets")
            ->assertOk()
            ->json('data.progress');

        $this->assertSame(2, $progress['screened']);
        $this->assertSame(1, $progress['needs_follow_up']);
    }

    public function test_pengiriman_ulang_hasil_skrining_tidak_menggandakan_baris(): void
    {
        $this->actAs($this->clinicStaff('CLINIC_ADMIN')['user']);

        $programId = $this->postJson('/api/v1/clinic/screening-programs', [
            'code' => 'SKR-2026-02',
            'name' => 'Skrining Penglihatan',
            'type' => 'VISION',
        ])->assertCreated()->json('data.id');

        $world = $this->makePatientWithGuardian('Skrining Ulang');

        $payload = [
            'student_id' => $world['student']->id,
            'type' => 'VISION',
            'screened_at' => now()->toDateString(),
            'vision_left' => '5/6',
            'vision_right' => '5/5',
            'result' => 'NORMAL',
        ];

        $this->postJson("/api/v1/clinic/screening-programs/{$programId}/screenings", $payload)->assertCreated();
        $this->postJson("/api/v1/clinic/screening-programs/{$programId}/screenings", $payload)->assertCreated();

        $this->assertSame(1, ClinicScreening::query()->count(), 'Skrining yang sama tidak digandakan');

        // Perbaikan hasil: baris yang ada diperbarui, bukan ditambah.
        // array_merge (bukan operator +) agar nilai baru benar-benar menimpa
        // nilai lama pada kunci yang sama.
        $this->postJson("/api/v1/clinic/screening-programs/{$programId}/screenings", array_merge($payload, [
            'result' => 'NEEDS_ATTENTION',
            'recommendation' => 'Periksa ulang 1 bulan',
        ]))->assertCreated();

        $this->assertSame(1, ClinicScreening::query()->count());
        $this->assertSame('NEEDS_ATTENTION', ClinicScreening::query()->firstOrFail()->result);
    }

    public function test_imunisasi_dicatat_beserta_persetujuan(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);

        $world = $this->makePatientWithGuardian('Pasien Imunisasi');

        $response = $this->postJson('/api/v1/clinic/vaccinations', [
            'student_id' => $world['student']->id,
            'vaccine' => 'Td (Tetanus-difteri)',
            'dose' => 2,
            'given_at' => now()->toDateString(),
            'batch_number' => 'TD-2026-11',
            'provider' => 'Puskesmas Kecamatan',
            'location' => 'Aula sekolah',
            'consent_obtained' => true,
            'next_due_at' => now()->addYears(5)->toDateString(),
        ])->assertCreated();

        $this->assertSame('Td (Tetanus-difteri)', $response->json('data.vaccine'));
        $this->assertTrue((bool) $response->json('data.consent_obtained'));

        $list = $this->getJson('/api/v1/clinic/vaccinations?vaccine=Tetanus')->assertOk();
        $this->assertCount(1, $list->json('data'));
    }

    public function test_surat_keterangan_harus_ditinjau_sebelum_diterbitkan(): void
    {
        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($doctor['user']);

        $world = $this->makePatientWithGuardian('Pasien Surat');

        $certificate = $this->postJson('/api/v1/clinic/certificates', [
            'patient_id' => $world['patient']->id,
            'type' => 'SPORTS',
            'purpose' => 'Persyaratan lomba futsal antarsekolah',
        ])->assertCreated();

        $certificateId = $certificate->json('data.id');

        $this->assertSame('REQUESTED', $certificate->json('data.status'));
        $this->assertStringStartsWith('SK-', $certificate->json('data.certificate_number'));

        // Terbit tanpa ditinjau: ditolak.
        $this->putJson("/api/v1/clinic/certificates/{$certificateId}/issue")->assertStatus(422);

        // Ditolak dengan alasan: tidak bisa diterbitkan.
        $this->putJson("/api/v1/clinic/certificates/{$certificateId}/review", [
            'approve' => false,
            'reason' => 'Pemain belum menjalani pemeriksaan jantung.',
        ])->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->putJson("/api/v1/clinic/certificates/{$certificateId}/issue")->assertStatus(422);

        // Ditinjau ulang dan disetujui: barulah boleh terbit.
        $this->putJson("/api/v1/clinic/certificates/{$certificateId}/review", [
            'approve' => true,
            'clinical_summary' => 'Sehat, tekanan darah normal, tidak ada kelainan jantung.',
            'recommendation' => 'Boleh mengikuti aktivitas olahraga kompetitif.',
            'valid_until' => now()->addMonths(6)->toDateString(),
            'reason' => 'Pemeriksaan lanjutan sudah dilakukan.',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $issued = $this->putJson("/api/v1/clinic/certificates/{$certificateId}/issue")
            ->assertOk();

        $this->assertNotNull($issued->json('data.issued_at'));
        $this->assertNotNull($issued->json('data.verification_code'));

        // Orang tua dapat kabar surat siap.
        $this->assertSame(1, ClinicNotificationLog::query()->where('event', 'CERTIFICATE_READY')->count());
    }

    public function test_verifikasi_publik_hanya_menyatakan_keabsahan_tanpa_isi_klinis(): void
    {
        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($doctor['user']);

        $world = $this->makePatientWithGuardian('Pasien Verifikasi');

        $certificateId = $this->postJson('/api/v1/clinic/certificates', [
            'patient_id' => $world['patient']->id,
            'type' => 'RETURN_TO_SCHOOL',
            'purpose' => 'Kembali sekolah setelah rawat inap',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/clinic/certificates/{$certificateId}/review", [
            'approve' => true,
            'clinical_summary' => 'RAHASIA: riwayat demam tifoid, masih dalam pemantauan.',
            'valid_until' => now()->addMonth()->toDateString(),
        ])->assertOk();

        $code = $this->putJson("/api/v1/clinic/certificates/{$certificateId}/issue")
            ->assertOk()
            ->json('data.verification_code');

        // Pihak luar (tanpa login) memeriksa keaslian surat.
        $verify = $this->getJson("/api/v1/clinic/public/certificates/{$code}/verify")->assertOk();

        $this->assertTrue((bool) $verify->json('data.valid'));
        $this->assertSame('RETURN_TO_SCHOOL', $verify->json('data.type'));

        // Yang TIDAK boleh bocor: identitas pasien & isi klinis.
        $raw = (string) json_encode($verify->json('data'));
        $this->assertStringNotContainsString('Pasien Verifikasi', $raw);
        $this->assertStringNotContainsString('tifoid', $raw);
        $this->assertStringNotContainsString($world['patient']->medical_record_number, $raw);

        // Kode salah: 404, tetap tanpa bocoran.
        $this->getJson('/api/v1/clinic/public/certificates/KODEPALSU99/verify')->assertNotFound();
    }

    public function test_surat_yang_sudah_kedaluwarsa_dinyatakan_tidak_berlaku(): void
    {
        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($doctor['user']);

        $world = $this->makePatientWithGuardian('Pasien Surat Kedaluwarsa');

        $certificateId = $this->postJson('/api/v1/clinic/certificates', [
            'patient_id' => $world['patient']->id,
            'type' => 'SCHOOL_ACTIVITY',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/clinic/certificates/{$certificateId}/review", [
            'approve' => true,
            'valid_from' => now()->subMonths(2)->toDateString(),
            'valid_until' => now()->subMonth()->toDateString(),
        ])->assertOk();

        $code = $this->putJson("/api/v1/clinic/certificates/{$certificateId}/issue")
            ->assertOk()
            ->json('data.verification_code');

        $verify = $this->getJson("/api/v1/clinic/public/certificates/{$code}/verify")->assertOk();
        $this->assertFalse((bool) $verify->json('data.valid'), 'Surat lewat masa berlaku tidak sah');

        $this->assertFalse(ClinicHealthCertificate::query()->findOrFail($certificateId)->isValid());
    }

    public function test_unggah_dokumen_kesehatan_menolak_tipe_berkas_berbahaya(): void
    {
        $this->actAs($this->clinicStaff('CLINIC_ADMIN')['user']);

        $world = $this->makePatientWithGuardian('Pasien Dokumen');

        \Illuminate\Support\Facades\Storage::fake('clinic');

        // Berkas teks bukan PDF/gambar: ditolak sebelum menyentuh penyimpanan.
        $this->postJson('/api/v1/clinic/documents', [
            'patient_id' => $world['patient']->id,
            'type' => 'TREATMENT_NOTE',
            'title' => 'Berbahaya',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('skrip.php', 10, 'application/x-php'),
        ])->assertStatus(422);

        $this->assertSame(0, \App\Modules\Clinic\Models\ClinicDocument::query()->count());

        // PDF: diterima dan tercatat.
        $this->postJson('/api/v1/clinic/documents', [
            'patient_id' => $world['patient']->id,
            'type' => 'TREATMENT_NOTE',
            'title' => 'Hasil laboratorium',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('hasil.pdf', 40, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.visibility', 'CLINICAL');
    }

    public function test_unggahan_dokumen_baru_menjadi_versi_dan_menonaktifkan_yang_lama(): void
    {
        $this->actAs($this->clinicStaff('CLINIC_ADMIN')['user']);

        $world = $this->makePatientWithGuardian('Pasien Versi Dokumen');

        \Illuminate\Support\Facades\Storage::fake('clinic');

        $first = $this->postJson('/api/v1/clinic/documents', [
            'patient_id' => $world['patient']->id,
            'type' => 'MEDICAL_CERTIFICATE',
            'title' => 'Surat v1',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('surat-v1.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $second = $this->postJson('/api/v1/clinic/documents', [
            'patient_id' => $world['patient']->id,
            'type' => 'MEDICAL_CERTIFICATE',
            'title' => 'Surat v2',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('surat-v2.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame(1, $first->json('data.version'));
        $this->assertSame(2, $second->json('data.version'));
        $this->assertSame($first->json('data.id'), $second->json('data.replaces_id'));

        $this->assertSame(1, \App\Modules\Clinic\Models\ClinicDocument::query()->where('is_current', true)->count());
    }
}
