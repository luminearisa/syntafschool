<?php

namespace Tests\Feature\Clinic;

use App\Models\InventoryMovement;
use App\Modules\Clinic\Domain\Enums\InventoryStockResult;
use App\Modules\Clinic\Models\ClinicAuditLog;
use App\Modules\Clinic\Models\ClinicMedication;
use App\Modules\Clinic\Models\ClinicMedicationBatch;
use App\Modules\Clinic\Models\ClinicNotificationLog;
use App\Modules\Clinic\Models\ClinicVisit;

/**
 * Alur klinis end-to-end: pendaftaran → triase → tanda vital → asesmen →
 * diagnosis → tindakan → resep → penyerahan (stok Inventory berkurang) →
 * pemberian obat → pemulangan → pemberitahuan → tindak lanjut.
 *
 * Uji ini menjaga janji modul: setiap langkah tercatat, stok hanya berubah
 * lewat Inventory, dan kunjungan tidak pernah selesai tanpa pemulangan.
 */
class ClinicVisitFlowTest extends ClinicTestCase
{
    public function test_alur_lengkap_kunjungan_sampai_pemulangan(): void
    {
        $nurse = $this->clinicStaff('NURSE');
        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($nurse['user']);

        $world = $this->makePatientWithGuardian('Ahmad Fauzi');
        $item = $this->inventoryItem('Paracetamol 500 mg', 50);
        $medication = $this->medication(null, $item->id);

        // ---- 1. Pendaftaran kunjungan
        $this->postJson('/api/v1/clinic/visits', [
            'student_id' => $world['student']->id,
            'visit_type' => 'ILLNESS',
            'source' => 'TEACHER',
            'chief_complaint' => 'Demam sejak pagi',
        ])->assertCreated()
            ->assertJsonPath('data.visit.status', 'REGISTERED')
            ->assertJsonPath('data.patient_header.medical_record_number', $world['patient']->medical_record_number);

        $visit = ClinicVisit::query()->latest('id')->firstOrFail();

        $this->assertStringStartsWith('KV-', $visit->visit_number);
        $this->assertNotNull($visit->queue, 'Kunjungan harus masuk antrean');

        // ---- 2. Triase
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/triage", [
            'priority' => 'URGENT',
            'chief_complaint' => 'Demam tinggi sejak semalam',
            'pain_score' => 4,
            'consciousness' => 'ALERT',
            'general_condition' => 'FAIR',
            'duration' => '1 hari',
            'onset' => 'SUDDEN',
            'associated_symptoms' => ['batuk', 'pilek'],
        ])->assertOk()->assertJsonPath('data.priority', 'URGENT');

        $this->assertSame('TRIAGED', $visit->fresh()->status);

        // ---- 3. Tanda vital: IMT dihitung & peringatan muncul dari ambang konfigurasi
        $response = $this->postJson("/api/v1/clinic/visits/{$visit->id}/vitals", [
            'temperature' => 38.8,
            'systolic' => 110,
            'diastolic' => 70,
            'heart_rate' => 96,
            'respiratory_rate' => 20,
            'oxygen_saturation' => 98,
            'weight_kg' => 45,
            'height_cm' => 150,
        ])->assertCreated();

        // Kolom desimal dikembalikan Eloquent sebagai string ('20.00'), jadi
        // kebenarannya diuji pada nilai numeriknya.
        $this->assertSame(20.0, (float) $response->json('data.vital.bmi'));
        $response->assertJsonPath('data.vital.bmi_category', 'NORMAL');

        $warnings = $response->json('data.warnings');
        $this->assertNotEmpty($warnings, 'Demam 38,8 °C harus memunculkan peringatan');
        $this->assertStringContainsString('demam', mb_strtolower(implode(' ', $warnings)));

        $this->assertSame('IN_PROGRESS', $visit->fresh()->status);

        // ---- 4. Asesmen SOAP oleh dokter
        $this->actAs($doctor['user']);

        $this->postJson("/api/v1/clinic/visits/{$visit->id}/assessment", [
            'subjective' => 'Demam, batuk, nyeri tenggorokan.',
            'objective' => 'Suhu 38,8 °C, faring hiperemis.',
            'assessment' => 'ISPA ringan.',
            'plan' => 'Paracetamol, istirahat, kontrol 2 hari.',
        ])->assertCreated()->assertJsonPath('data.version', 1);

        // ---- 5. Diagnosis
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/diagnoses", [
            'diagnosis_code' => 'J06',
            'type' => 'PRIMARY',
        ])->assertCreated()->assertJsonPath('data.diagnosis_name', 'Infeksi saluran napas atas');

        // ---- 6. Tindakan
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/procedures", [
            'procedure' => 'Kompres hangat & edukasi hidrasi',
        ])->assertCreated();

        // ---- 7. Resep
        $rx = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [[
                'medication_id' => $medication->id,
                'dose' => '1 tablet',
                'frequency' => '3x sehari',
                'duration' => '3 hari',
                'quantity' => 6,
            ]],
        ])->assertCreated();

        $prescriptionId = $rx->json('data.prescription.id');
        $this->assertSame('ISSUED', $rx->json('data.prescription.status'));

        // ---- 8. Penyerahan obat: stok Inventory berkurang, bukan tabel Clinic
        $stockBefore = (float) $item->fresh()->quantity;

        $dispense = $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense", [
            'idempotency_key' => 'dispense-1',
        ])->assertCreated();

        $this->assertSame(
            InventoryStockResult::SUCCESS->value,
            $dispense->json('data.results.0.inventory_result'),
        );
        $this->assertSame($stockBefore - 6, (float) $item->fresh()->quantity);
        $this->assertSame('DISPENSED', $dispense->json('data.prescription_status'));
        $this->assertSame(1, InventoryMovement::query()->count());

        // ---- 9. Pemberian obat (MAR) menyimpan pelaku & waktu
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/administrations", [
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'status' => 'GIVEN',
        ])->assertCreated()->assertJsonPath('data.status', 'GIVEN');

        // ---- 10. Observasi singkat lalu diakhiri
        $observation = $this->postJson("/api/v1/clinic/visits/{$visit->id}/observations", [
            'reason' => 'Pemantauan suhu',
            'bed' => 'A1',
        ])->assertCreated();

        $observationId = $observation->json('data.id');
        $this->assertSame('OBSERVATION', $visit->fresh()->status);

        $this->postJson("/api/v1/clinic/observations/{$observationId}/records", [
            'condition' => 'IMPROVING',
            'notes' => 'Suhu turun menjadi 37,5 °C',
            'vitals' => ['temperature' => 37.5],
        ])->assertCreated();

        $this->putJson("/api/v1/clinic/observations/{$observationId}/end", [
            'status' => 'COMPLETED',
            'notes' => 'Kondisi membaik',
        ])->assertOk();

        // ---- 11. Pemulangan: kembali ke kelas, follow-up terjadwal otomatis
        $discharge = $this->postJson("/api/v1/clinic/visits/{$visit->id}/discharge", [
            'status' => 'RETURN_TO_CLASS',
            'condition' => 'IMPROVING',
            'instructions' => 'Banyak minum, istirahat cukup.',
            'follow_up_required' => true,
            'follow_up_date' => now()->addDays(2)->toDateString(),
        ])->assertCreated();

        $discharge->assertJsonPath('data.discharge.status', 'RETURN_TO_CLASS');

        $visit = $visit->fresh();
        $this->assertSame('DISCHARGED', $visit->status);
        $this->assertNotNull($visit->closed_at);
        $this->assertSame(1, $visit->followUps()->count(), 'Follow-up wajib dibuat dari pemulangan');

        // ---- 12. Pemberitahuan orang tua tercatat (tanpa detail klinis)
        $log = ClinicNotificationLog::query()->where('event', 'SENT_HOME')->first();
        $this->assertNotNull($log, 'Pemulangan harus diberitakan ke orang tua');
        $this->assertSame('SENT', $log->status);
        $this->assertStringContainsString('layanan klinik', mb_strtolower((string) $log->body));
        $this->assertStringNotContainsString('ISPA', (string) $log->body);

        // ---- 13. Jejak audit lengkap
        $actions = ClinicAuditLog::query()->pluck('action')->unique()->all();
        foreach (['CREATE', 'UPDATE', 'DISPENSE', 'ADMINISTER'] as $expected) {
            $this->assertContains($expected, $actions, "Aksi {$expected} harus tercatat di audit");
        }

        // ---- 14. Kunjungan tertutup menolak catatan baru
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/vitals", ['temperature' => 36.5])
            ->assertStatus(422);
    }

    public function test_pendaftaran_ulang_dengan_kunci_idempotensi_tidak_menggandakan_kunjungan(): void
    {
        $this->actAs($this->clinicStaff('UKS_OFFICER')['user']);
        $world = $this->makePatientWithGuardian('Siti Aminah');

        $payload = [
            'student_id' => $world['student']->id,
            'chief_complaint' => 'Sakit kepala',
            'idempotency_key' => 'retry-123',
        ];

        $first = $this->postJson('/api/v1/clinic/visits', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/clinic/visits', $payload)->assertCreated();

        $this->assertSame($first->json('data.visit.id'), $second->json('data.visit.id'));
        $this->assertSame(1, ClinicVisit::query()->count(), 'Pengiriman ulang tidak boleh membuat kunjungan ganda');
    }

    public function test_asesmen_kedua_tanpa_alasan_ditolak_dan_dengan_alasan_menjadi_versi_baru(): void
    {
        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($doctor['user']);

        $world = $this->makePatientWithGuardian('Rizki Pratama');
        $visit = $this->visit($world['patient']);

        $this->postJson("/api/v1/clinic/visits/{$visit->id}/assessment", [
            'assessment' => 'Observasi awal',
        ])->assertCreated();

        // Tanpa alasan koreksi: ditolak, catatan lama tidak hilang.
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/assessment", [
            'assessment' => 'Perbaikan',
        ])->assertStatus(422);

        $this->assertSame(1, $visit->assessments()->count());

        // Dengan alasan: versi 2 dibuat, versi 1 tetap tersimpan.
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/assessment", [
            'assessment' => 'Perbaikan',
            'reason' => 'Salah mengetik hasil pemeriksaan',
        ])->assertCreated()->assertJsonPath('data.version', 2);

        $this->assertSame(2, $visit->assessments()->count());
        $this->assertSame(1, $visit->assessments()->where('is_current', false)->count());
    }

    public function test_kunjungan_gawat_darurat_langsung_masuk_pemeriksaan_dan_prioritas_darurat(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);
        $world = $this->makePatientWithGuardian('Bayu Saputra');

        $this->postJson('/api/v1/clinic/visits', [
            'student_id' => $world['student']->id,
            'visit_type' => 'EMERGENCY',
            'source' => 'TEACHER',
            'chief_complaint' => 'Jatuh dari tangga, perdarahan',
        ])->assertCreated()->assertJsonPath('data.visit.priority', 'EMERGENCY');

        $visit = ClinicVisit::query()->latest('id')->firstOrFail();

        $this->assertSame('IN_EXAMINATION', $visit->queue->stage, 'Gawat darurat tidak menunggu antrean');
        $this->assertSame('DARURAT', $visit->queue->queue_number);
        $this->assertTrue((bool) $visit->is_emergency);
    }

    public function test_status_kunjungan_tidak_bisa_melompati_urutan(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);
        $world = $this->makePatientWithGuardian('Dewi Lestari');
        $visit = $this->visit($world['patient']);

        // REGISTERED → DISCHARGED langsung: ditolak (harus lewat penanganan).
        $this->putJson("/api/v1/clinic/visits/{$visit->id}/status", [
            'status' => 'DISCHARGED',
        ])->assertStatus(422);

        $this->assertSame('REGISTERED', $visit->fresh()->status);
    }

    public function test_workspace_menyertakan_header_pasien_dan_peringatan_alergi(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);
        $world = $this->makePatientWithGuardian('Nadia Salsabila');

        // Alergi berat dicatat lebih dahulu.
        $this->postJson("/api/v1/clinic/patients/{$world['patient']->id}/allergies", [
            'category' => 'MEDICATION',
            'allergen' => 'Amoksisilin',
            'reaction' => 'Ruam dan sesak',
            'severity' => 'SEVERE',
        ])->assertCreated();

        $visit = $this->visit($world['patient']);

        $response = $this->getJson("/api/v1/clinic/visits/{$visit->id}")->assertOk();

        $response->assertJsonPath('data.patient_header.alerts.allergy', true);

        $allergies = $response->json('data.patient_header.allergies');
        $this->assertCount(1, $allergies);
        $this->assertSame('Amoksisilin', $allergies[0]['allergen']);
        $this->assertTrue($allergies[0]['is_critical']);

        // Identitas datang dari master ERP, bukan salinan tabel Clinic.
        $this->assertSame('Nadia Salsabila', $response->json('data.patient_header.identity.name'));
    }

    public function test_riwayat_tanda_vital_dapat_dibaca_untuk_grafik(): void
    {
        $this->actAs($this->clinicStaff('NURSE')['user']);
        $world = $this->makePatientWithGuardian('Fajar Nugroho');
        $visit = $this->visit($world['patient']);

        foreach ([36.5, 37.8, 38.4] as $temperature) {
            $this->postJson("/api/v1/clinic/visits/{$visit->id}/vitals", [
                'temperature' => $temperature,
                'weight_kg' => 50,
                'height_cm' => 155,
            ])->assertCreated();
        }

        $response = $this->getJson("/api/v1/clinic/patients/{$world['patient']->id}/vitals")->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertSame(38.4, (float) $response->json('data.0.temperature'));
    }
}
