<?php

namespace Tests\Feature\Uks;

/**
 * Rekam kesehatan (profil, pemeriksaan, imunisasi), insiden, surat keterangan
 * beserta verifikasi publiknya, serta batas laju endpoint publik.
 */
class UksRecordLetterTest extends UksTestCase
{
    public function test_profil_pemeriksaan_dan_imunisasi(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Citra', $class);
        $officer = $this->makeOfficer();
        $this->actAs($officer['user']);

        $this->putJson("/api/v1/uks/records/{$student->id}/profile", [
            'blood_type' => 'A',
            'allergies' => 'Udang',
            'emergency_contact_phone' => '081234567890',
        ])->assertOk()->assertJsonPath('data.allergies', 'Udang');

        $screening = $this->postJson('/api/v1/uks/screenings', [
            'student_id' => $student->id,
            'screening_type' => 'UMUM',
            'height_cm' => 150,
            'weight_kg' => 45,
            'result' => 'NORMAL',
        ])->assertCreated();

        // IMT dihitung server: 45 / (1.5^2) = 20.
        $this->assertEquals(20, (float) $screening->json('data.bmi'));

        $this->postJson('/api/v1/uks/immunizations', [
            'student_id' => $student->id,
            'vaccine_name' => 'Tetanus',
            'dose_number' => 2,
            'given_at' => now()->toDateString(),
            'next_due_at' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $record = $this->getJson("/api/v1/uks/records/{$student->id}")->assertOk();
        $this->assertSame('A', $record->json('data.profile.blood_type'));
        $this->assertCount(1, $record->json('data.screenings'));
        $this->assertCount(1, $record->json('data.immunizations'));

        // Tinggi badan di luar batas ditolak.
        $this->postJson('/api/v1/uks/screenings', [
            'student_id' => $student->id, 'screening_type' => 'UMUM', 'height_cm' => 500,
        ])->assertStatus(422);
    }

    public function test_insiden_dan_surat_keterangan_terverifikasi(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Dewi', $class);
        $officer = $this->makeOfficer();
        $parent = $this->makeGuardian($student);
        $this->actAs($officer['user']);

        $incident = $this->postJson('/api/v1/uks/incidents', [
            'student_id' => $student->id,
            'location' => 'Lapangan olahraga',
            'severity' => 'SEDANG',
            'description' => 'Terpeleset saat lari, lutut lecet',
            'first_aid' => 'Luka dibersihkan dan dibalut',
        ])->assertCreated();
        $this->assertStringStartsWith('IKD/', $incident->json('data.code'));

        $this->putJson('/api/v1/uks/incidents/'.$incident->json('data.id'), [
            'status' => 'SELESAI',
            'follow_up' => 'Sudah pulih',
            'parent_notified' => true,
        ])->assertOk()->assertJsonPath('data.status', 'SELESAI');

        $letter = $this->postJson('/api/v1/uks/letters', [
            'student_id' => $student->id,
            'letter_type' => 'ISTIRAHAT',
            'date_from' => now()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
            'diagnosis_note' => 'Lecet lutut, perlu istirahat',
        ])->assertCreated();

        $code = $letter->json('data.verification_code');
        $this->assertSame(8, strlen($code));

        // Tanggal terbalik ditolak.
        $this->postJson('/api/v1/uks/letters', [
            'student_id' => $student->id,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertStatus(422);

        // Orang tua dapat mengunduh PDF surat anaknya.
        $this->actAs($parent['user']);
        $download = $this->get("/api/v1/uks/letters/{$letter->json('data.id')}/download");
        $download->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $download->streamedContent());

        // Orang tua siswa lain (di luar kelas guru ini) tidak bisa mengunduh.
        $other = $this->makeGuardian($this->makeStudent('Lain', $this->makeClass()));
        $this->actAs($other['user']);
        $this->get("/api/v1/uks/letters/{$letter->json('data.id')}/download")->assertForbidden();

        // Verifikasi publik tanpa login (permintaan tamu, tanpa token).
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/uks/letters/verify/{$code}")->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.student_name', 'Dewi');

        $this->getJson('/api/v1/uks/letters/verify/TIDAKADA')->assertNotFound();
    }

    public function test_verifikasi_publik_dibatasi_laju(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/uks/letters/verify/ABCDEFGH')->assertNotFound();
        }

        $this->getJson('/api/v1/uks/letters/verify/ABCDEFGH')->assertStatus(429);
    }

    public function test_kesehatan_data_mendeteksi_temuan(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Eka', $class);
        $officer = $this->makeOfficer();
        $this->actAs($officer['user']);

        $this->postJson('/api/v1/uks/visits', [
            'student_id' => $student->id,
            'complaint' => 'Sesak napas',
            'severity' => 'DARURAT',
        ])->assertCreated();

        // Orang tua tidak punya akun, jadi kasus darurat belum terkonfirmasi
        // dikabari — pemeriksaan kesehatan data harus menandai ini.
        $this->actingAsRole('admin-sekolah');
        $report = $this->getJson('/api/v1/uks/health')->assertOk();
        $this->assertContains($report->json('data.status'), ['warning', 'critical']);

        $checks = collect($report->json('data.checks'))->keyBy('key');
        $this->assertGreaterThan(0, $checks['severe_without_parent_notice']['count']);

        // Guru tidak boleh membuka pemeriksaan kesehatan data.
        $this->actingAsRole('guru');
        $this->getJson('/api/v1/uks/health')->assertForbidden();
    }
}
