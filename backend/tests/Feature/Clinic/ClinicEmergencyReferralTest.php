<?php

namespace Tests\Feature\Clinic;

use App\Models\InventoryMovement;
use App\Modules\Clinic\Models\ClinicEmergency;
use App\Modules\Clinic\Models\ClinicFollowUp;
use App\Modules\Clinic\Models\ClinicInjury;
use App\Modules\Clinic\Models\ClinicNotificationLog;
use App\Modules\Clinic\Models\ClinicVisit;

/**
 * Alur respons: gawat darurat, cedera/kecelakaan, dan rujukan.
 *
 * Yang dijaga di sini adalah hal-hal yang paling sering hilang jejaknya saat
 * panik: kapan kejadiannya, siapa yang menghubungi orang tua, apa yang
 * dilakukan lebih dahulu, ke mana dirujuk, dan apa hasilnya.
 */
class ClinicEmergencyReferralTest extends ClinicTestCase
{
    public function test_gawat_darurat_dibuka_dari_siswa_langsung_dan_orang_tua_dihubungi(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);

        $world = $this->makePatientWithGuardian('Pasien Darurat');

        $response = $this->postJson('/api/v1/clinic/emergencies', [
            'student_id' => $world['student']->id,
            'severity' => 'EMERGENCY',
            'location' => 'Lapangan basket',
            'description' => 'Pingsan setelah olahraga, tidak sadar ±1 menit',
            'action_taken' => 'Posisi berbaring, longgarkan pakaian, kompres dingin',
            'stabilization' => 'Kesadaran pulih, tekanan darah stabil',
            'ambulance_required' => true,
            'hospital' => 'RSUD Kota',
            'transport_method' => 'Ambulans sekolah',
        ])->assertCreated();

        $emergencyId = $response->json('data.emergency.id');
        $visitId = $response->json('data.visit_id');

        $this->assertStringStartsWith('IN-', $response->json('data.emergency.incident_number'));
        $this->assertNotNull($visitId, 'Gawat darurat otomatis membuat kunjungan');

        $visit = ClinicVisit::query()->findOrFail($visitId);
        $this->assertTrue((bool) $visit->is_emergency);
        $this->assertSame('EMERGENCY', $visit->priority);
        $this->assertSame('IN_EXAMINATION', $visit->queue->stage, 'Gawat darurat tidak mengantre');

        // Orang tua menerima kabar mendesak (kebijakan wajib).
        $log = ClinicNotificationLog::query()->where('event', 'EMERGENCY')->first();
        $this->assertNotNull($log);
        $this->assertSame('SENT', $log->status);
        $this->assertStringContainsString('segera', mb_strtolower((string) $log->body));

        // Petugas mencatat bahwa orang tua sudah dihubungi.
        $this->putJson("/api/v1/clinic/emergencies/{$emergencyId}", [
            'parent_contacted' => true,
            'referral_required' => true,
        ])->assertOk()->assertJsonPath('data.parent_contacted', true);

        $emergency = ClinicEmergency::query()->findOrFail($emergencyId);
        $this->assertNotNull($emergency->parent_contacted_at);

        // Ditutup dengan hasil akhir.
        $this->putJson("/api/v1/clinic/emergencies/{$emergencyId}/close", [
            'outcome' => 'Dirujuk ke RSUD, orang tua menyusul; kondisi stabil.',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED');

        $this->assertNotNull($emergency->fresh()->closed_at);
    }

    public function test_penutupan_gawat_darurat_wajib_berisi_hasil(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Darurat Dua');

        $emergencyId = $this->postJson('/api/v1/clinic/emergencies', [
            'student_id' => $world['student']->id,
            'description' => 'Sesak napas berat',
        ])->assertCreated()->json('data.emergency.id');

        $this->putJson("/api/v1/clinic/emergencies/{$emergencyId}/close", ['outcome' => ''])
            ->assertStatus(422);

        $this->assertSame('OPEN', ClinicEmergency::query()->findOrFail($emergencyId)->status);
    }

    public function test_cedera_membuat_kunjungan_otomatis_dan_mengabarkan_orang_tua_bila_perlu(): void
    {
        $this->actAs($this->clinicStaff('UKS_OFFICER')['user']);

        $world = $this->makePatientWithGuardian('Pasien Cedera');

        $response = $this->postJson('/api/v1/clinic/injuries', [
            'student_id' => $world['student']->id,
            'incident_at' => now()->subMinutes(20)->toDateTimeString(),
            'location' => 'Tangga asrama putra',
            'activity' => 'Naik tangga menuju kamar',
            'witnesses' => 'Dua santri sekamar',
            'injury_type' => 'Fall',
            'body_part' => 'Pergelangan kaki kanan',
            'mechanism' => 'Terpeleset anak tangga basah',
            'severity' => 'MODERATE',
        ])->assertCreated();

        $this->assertStringStartsWith('IN-', $response->json('data.incident_number'));
        $this->assertNotNull($response->json('data.visit_id'), 'Cedera otomatis membuat kunjungan');

        // Cedera sedang: orang tua diberi kabar.
        $log = ClinicNotificationLog::query()->where('event', 'INJURY')->first();
        $this->assertNotNull($log);
        $this->assertSame('SENT', $log->status);

        // Tanpa tindakan apa pun, cedera tidak boleh dianggap selesai.
        $injuryId = $response->json('data.id');
        $this->putJson("/api/v1/clinic/injuries/{$injuryId}/close")->assertStatus(422);

        $this->putJson("/api/v1/clinic/injuries/{$injuryId}", [
            'treatment' => 'Kompres dingin, pembalutan elastis',
            'status' => 'TREATED',
            'follow_up' => 'Periksa ulang bengkak dalam 2 hari',
        ])->assertOk();

        $this->putJson("/api/v1/clinic/injuries/{$injuryId}/close", [
            'follow_up' => 'Bengkak berkurang; boleh aktivitas ringan',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_cedera_ringan_tidak_mengirim_notifikasi_berlebihan(): void
    {
        $this->actAs($this->clinicStaff('UKS_OFFICER')['user']);

        $world = $this->makePatientWithGuardian('Pasien Lecet');

        $this->postJson('/api/v1/clinic/injuries', [
            'student_id' => $world['student']->id,
            'location' => 'Kelas VII A',
            'injury_type' => 'Cut',
            'severity' => 'MINOR',
            'treatment' => 'Dibersihkan dan ditutup plester',
        ])->assertCreated();

        $this->assertSame(0, ClinicNotificationLog::query()->where('event', 'INJURY')->count());
        $this->assertSame(
            0,
            ClinicInjury::query()->where('parent_notified', true)->count(),
            'Lecet ringan cukup ditangani; orang tua tidak perlu dikhawatirkan',
        );
    }

    public function test_rujukan_wajib_memiliki_tujuan_dan_alasan(): void
    {
        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($doctor['user']);

        $world = $this->makePatientWithGuardian('Pasien Rujukan');
        $visit = $this->visit($world['patient']);

        $this->postJson("/api/v1/clinic/visits/{$visit->id}/referrals", [
            'facility' => '',
            'reason' => '',
        ])->assertStatus(422);

        $response = $this->postJson("/api/v1/clinic/visits/{$visit->id}/referrals", [
            'facility' => 'RSUD Kota',
            'facility_type' => 'HOSPITAL',
            'reason' => 'Nyeri perut hebat dengan tanda dehidrasi; perlu pemeriksaan laboratorium.',
            'diagnosis_summary' => 'Suspek gastroenteritis',
            'urgency' => 'URGENT',
            'transport' => 'Kendaraan sekolah',
        ])->assertCreated();

        $referralId = $response->json('data.id');
        $this->assertStringStartsWith('RJ-', $response->json('data.referral_number'));
        $this->assertSame('REFERRED', $response->json('data.status'));

        // Kunjungan berpindah ke status dirujuk (belum ditutup: menunggu hasil).
        $this->assertSame('REFERRED', $visit->fresh()->status);

        // Orang tua diberi kabar rujukan.
        $this->assertSame(1, ClinicNotificationLog::query()->where('event', 'REFERRAL')->count());

        // Perjalanan rujukan dicatat bertahap.
        $this->putJson("/api/v1/clinic/referrals/{$referralId}", ['status' => 'IN_TRANSIT'])->assertOk();
        $this->putJson("/api/v1/clinic/referrals/{$referralId}", ['status' => 'ARRIVED'])->assertOk();

        // Selesai tanpa hasil: ditolak.
        $this->putJson("/api/v1/clinic/referrals/{$referralId}", ['status' => 'COMPLETED'])->assertStatus(422);

        $this->putJson("/api/v1/clinic/referrals/{$referralId}", [
            'status' => 'COMPLETED',
            'outcome' => 'Rawat jalan; diberi oralit dan zinc, kontrol 3 hari.',
            'follow_up_date' => now()->addDays(3)->toDateString(),
        ])->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        // Rujukan selesai otomatis melahirkan tindak lanjut (baca hasil).
        $this->assertSame(1, ClinicFollowUp::query()->where('referral_id', $referralId)->count());
    }

    public function test_daftar_rujukan_dan_tindak_lanjut_terlambat_terlihat_petugas(): void
    {
        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($doctor['user']);

        $world = $this->makePatientWithGuardian('Pasien Tindak Lanjut');
        $visit = $this->visit($world['patient']);

        // Penjadwalan lewat UI hanya menerima tanggal hari ini atau mendatang.
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/follow-ups", [
            'due_date' => now()->toDateString(),
            'type' => 'WOUND_CARE',
            'instruction' => 'Ganti perban',
        ])->assertCreated();

        // Setelah tenggat terlewat (data lama), tindak lanjut tampil sebagai terlambat.
        ClinicFollowUp::query()->firstOrFail()->update(['due_date' => now()->subDay()->toDateString()]);

        $response = $this->getJson('/api/v1/clinic/follow-ups?overdue_only=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertTrue((bool) $response->json('data.0.is_overdue'));

        // Dijalankan perintah harian: status menjadi OVERDUE.
        $this->artisan('clinic:expire-follow-ups')->assertSuccessful();

        $this->assertSame('OVERDUE', ClinicFollowUp::query()->firstOrFail()->status);
    }

    public function test_perintah_kedaluwarsa_batch_menandai_obat_kedaluwarsa(): void
    {
        $this->actAs($this->clinicStaff('PHARMACY_OFFICER')['user']);

        $medication = $this->medication(null, null, 'Obat Kedaluwarsa Perintah');

        $batch = \App\Modules\Clinic\Models\ClinicMedicationBatch::query()->create([
            'medication_id' => $medication->id,
            'batch_number' => 'B-OLD',
            'expiry_date' => now()->subDays(3)->toDateString(),
            'quantity_received' => 10,
            'status' => 'AVAILABLE',
        ]);

        $this->artisan('clinic:expire-batches')->assertSuccessful();

        $this->assertSame('EXPIRED', $batch->fresh()->status);
    }

    public function test_kesehatan_modul_dilaporkan_tanpa_membocorkan_data_pasien(): void
    {
        $this->actAs($this->clinicStaff('CLINIC_ADMIN')['user']);

        $world = $this->makePatientWithGuardian('Pasien Rahasia Kesehatan');
        $this->visit($world['patient']);

        $response = $this->getJson('/api/v1/clinic/health')->assertOk();
        $payload = $response->json('data');

        $this->assertSame('OK', $payload['status']);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertArrayHasKey('inventory_provider', $payload['checks']);

        $raw = json_encode($payload);
        $this->assertStringNotContainsString('Pasien Rahasia Kesehatan', (string) $raw);
        $this->assertStringNotContainsString($world['patient']->medical_record_number, (string) $raw);
    }

    public function test_dashboard_menyajikan_angka_operasional_yang_benar(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);

        $world = $this->makePatientWithGuardian('Pasien Dashboard');
        $second = $this->makePatientWithGuardian('Pasien Dashboard Dua');

        $visit = $this->visit($world['patient']);
        $this->visit($second['patient'], ['visit_type' => 'EMERGENCY']);

        $this->postJson("/api/v1/clinic/visits/{$visit->id}/triage", [
            'priority' => 'SEMI_URGENT',
            'chief_complaint' => 'Demam',
        ])->assertOk();

        $response = $this->getJson('/api/v1/clinic/dashboard')->assertOk();
        $data = $response->json('data');

        $this->assertSame(2, $data['visits_today']);
        $this->assertSame(1, $data['pending_triage'], 'Kunjungan darurat sudah ditangani, satu masih menunggu');
        $this->assertSame(1, $data['emergencies_open']);
        $this->assertCount(14, $data['visits_by_day']);
        $this->assertArrayHasKey('queue_board', $data);

        // Antrean diurutkan berdasarkan prioritas: darurat lebih dahulu.
        $this->assertSame('EMERGENCY', $data['queue_board'][0]['priority']);
    }

    public function test_papan_antrean_dan_pemanggilan_pasien_berikutnya(): void
    {
        $this->actAs($this->clinicStaff('UKS_OFFICER')['user']);

        $first = $this->makePatientWithGuardian('Antrean Satu');
        $second = $this->makePatientWithGuardian('Antrean Dua');

        $this->visit($first['patient']);
        $this->visit($second['patient']);

        $board = $this->getJson('/api/v1/clinic/queue')->assertOk();
        $this->assertCount(2, $board->json('data.rows'));

        $called = $this->postJson('/api/v1/clinic/queue/call-next')->assertOk();
        $this->assertSame('Antrean Satu', $called->json('data.patient_name'), 'Urutan memanggil mengikuti urutan antrean');

        $visitId = $called->json('data.visit_id');
        $this->putJson("/api/v1/clinic/queue/{$visitId}/stage", ['stage' => 'PHARMACY'])
            ->assertOk()->assertJsonPath('data.stage', 'PHARMACY');
    }

    public function test_stok_tidak_berkurang_hanya_karena_kunjungan_dibuat(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);

        $item = $this->inventoryItem('Stok Utuh', 25);
        $world = $this->makePatientWithGuardian('Pasien Tanpa Obat');

        $this->visit($world['patient']);
        $this->postJson("/api/v1/clinic/patients/{$world['patient']->id}/conditions", [
            'category' => 'DIETARY_RESTRICTION',
            'condition' => 'Pantang makanan pedas',
            'care_instruction' => 'Sediakan lauk tanpa cabai',
            'visible_to_kitchen' => true,
        ])->assertCreated();

        $this->assertSame(25.0, (float) $item->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::query()->count());
    }
}
