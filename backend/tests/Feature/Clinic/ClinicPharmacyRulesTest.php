<?php

namespace Tests\Feature\Clinic;

use App\Models\InventoryMovement;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\InventoryStockResult;
use App\Modules\Clinic\Models\ClinicDispensing;
use App\Modules\Clinic\Models\ClinicMedicationAdministration;
use App\Modules\Clinic\Models\ClinicMedicationBatch;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan farmasi yang tidak boleh dilanggar.
 *
 * Empat janji keselamatan pasien diuji di sini:
 * 1. peringatan alergi muncul dan tidak bisa dilewati tanpa alasan tertulis;
 * 2. obat kedaluwarsa tidak pernah diberikan;
 * 3. stok tidak pernah negatif dan hanya berubah lewat Inventory;
 * 4. pengiriman ulang tidak menggandakan penyerahan obat.
 */
class ClinicPharmacyRulesTest extends ClinicTestCase
{
    public function test_resep_dengan_benturan_alergi_berat_ditolak_tanpa_alasan_klinis(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Alergi');
        $visit = $this->visit($world['patient']);

        $this->postJson("/api/v1/clinic/patients/{$world['patient']->id}/allergies", [
            'category' => 'MEDICATION',
            'allergen' => 'Amoksisilin',
            'reaction' => 'Ruam dan sesak napas',
            'severity' => 'SEVERE',
        ])->assertCreated();

        $response = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [[
                'medication_name' => 'Amoksisilin 500 mg',
                'dose' => '1 kapsul',
                'quantity' => 6,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'alergi',
            mb_strtolower((string) $response->json('message').json_encode($response->json('errors'))),
        );
        $this->assertSame(0, $visit->prescriptions()->count());

        // Dengan alasan klinis tertulis: dilanjutkan, dan peringatannya tersimpan.
        $withReason = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [[
                'medication_name' => 'Amoksisilin 500 mg',
                'dose' => '1 kapsul',
                'quantity' => 6,
            ]],
            'allergy_override' => true,
            'override_reason' => 'Riwayat alergi pasien diragukan; obat diperlukan dan sudah dijelaskan ke orang tua.',
        ])->assertCreated();

        $this->assertTrue((bool) $withReason->json('data.prescription.allergy_override'));
        $this->assertNotEmpty($withReason->json('data.prescription.allergy_alerts'));
        $this->assertSame(
            'Riwayat alergi pasien diragukan; obat diperlukan dan sudah dijelaskan ke orang tua.',
            $withReason->json('data.prescription.override_reason'),
        );
    }

    public function test_override_alergi_tanpa_alasan_tetap_ditolak(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Alergi Dua');
        $visit = $this->visit($world['patient']);

        $this->postJson("/api/v1/clinic/patients/{$world['patient']->id}/allergies", [
            'category' => 'MEDICATION',
            'allergen' => 'Paracetamol',
            'severity' => 'SEVERE',
        ])->assertCreated();

        $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_name' => 'Paracetamol 500 mg', 'quantity' => 4]],
            'allergy_override' => true,
        ])->assertStatus(422);

        $this->assertSame(0, $visit->prescriptions()->count());
    }

    public function test_pratinjau_alergi_menandai_benturan_sebelum_resep_dibuat(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Pratinjau');
        $medication = $this->medication(null, null, 'Ibuprofen');

        $this->postJson("/api/v1/clinic/patients/{$world['patient']->id}/allergies", [
            'category' => 'MEDICATION',
            'allergen' => 'Ibuprofen',
            'severity' => 'MODERATE',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/clinic/pharmacy/allergy-check', [
            'patient_id' => $world['patient']->id,
            'medication_ids' => [$medication->id],
        ])->assertOk();

        $this->assertCount(1, $response->json('data.warnings'));
        $this->assertFalse($response->json('data.has_critical'));
    }

    public function test_obat_kedaluwarsa_tidak_boleh_diserahkan(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Kedaluwarsa');
        $item = $this->inventoryItem('Sirup Demam', 20);
        $medication = $this->medication(null, $item->id, 'Sirup Demam');
        $visit = $this->visit($world['patient']);

        ClinicMedicationBatch::query()->create([
            'medication_id' => $medication->id,
            'batch_number' => 'B-EXPIRED',
            'expiry_date' => now()->subDay()->toDateString(),
            'quantity_received' => 20,
            'status' => 'AVAILABLE',
        ]);

        $prescriptionId = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_id' => $medication->id, 'quantity' => 5]],
        ])->assertCreated()->json('data.prescription.id');

        $response = $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense")
            ->assertCreated();

        $this->assertSame(
            InventoryStockResult::EXPIRED->value,
            $response->json('data.results.0.inventory_result'),
        );

        // Stok fisik tidak tersentuh sama sekali.
        $this->assertSame(20.0, (float) $item->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame('REJECTED', $response->json('data.dispensing.status'));
    }

    public function test_stok_kurang_tidak_membuat_saldo_negatif(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Stok Kurang');
        $item = $this->inventoryItem('Obat Terbatas', 3);
        $medication = $this->medication(null, $item->id, 'Obat Terbatas');
        $visit = $this->visit($world['patient']);

        $prescriptionId = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_id' => $medication->id, 'quantity' => 10]],
        ])->assertCreated()->json('data.prescription.id');

        $response = $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense")
            ->assertCreated();

        $this->assertSame(
            InventoryStockResult::INSUFFICIENT_STOCK->value,
            $response->json('data.results.0.inventory_result'),
        );
        $this->assertSame(3.0, (float) $item->fresh()->quantity, 'Stok tidak boleh negatif');
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_obat_tanpa_pemetaan_inventory_dilaporkan_bukan_dianggap_tersedia(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Tanpa Item');
        $medication = $this->medication(null, null, 'Obat Titipan');
        $visit = $this->visit($world['patient']);

        $prescriptionId = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_id' => $medication->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.prescription.id');

        $response = $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense")
            ->assertCreated();

        $this->assertSame(
            InventoryStockResult::NOT_AVAILABLE->value,
            $response->json('data.results.0.inventory_result'),
        );

        // Resep tidak dianggap terserahkan, jadi masih dapat dicoba ulang
        // setelah obat dipetakan ke item Inventory.
        $this->assertSame('ISSUED', $response->json('data.prescription_status'));
    }

    public function test_pengiriman_ulang_penyerahan_obat_tidak_mengurangi_stok_dua_kali(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Idempoten');
        $item = $this->inventoryItem('Paracetamol Sirup', 10);
        $medication = $this->medication(null, $item->id, 'Paracetamol Sirup');
        $visit = $this->visit($world['patient']);

        $prescriptionId = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_id' => $medication->id, 'quantity' => 4]],
        ])->assertCreated()->json('data.prescription.id');

        $payload = ['idempotency_key' => 'dispense-retry-1'];

        $first = $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense", $payload)->assertCreated();
        $second = $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense", $payload)->assertCreated();

        $this->assertSame($first->json('data.dispensing.id'), $second->json('data.dispensing.id'));
        $this->assertSame(6.0, (float) $item->fresh()->quantity, 'Stok hanya berkurang sekali');
        $this->assertSame(1, ClinicDispensing::query()->count());
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_fefo_memilih_batch_yang_paling_dekat_kedaluwarsa(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien FEFO');
        $item = $this->inventoryItem('Amoksisilin Kapsul', 30);
        $medication = $this->medication(null, $item->id, 'Amoksisilin Kapsul');

        $far = ClinicMedicationBatch::query()->create([
            'medication_id' => $medication->id,
            'batch_number' => 'B-JAUH',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 15,
            'status' => 'AVAILABLE',
        ]);

        $near = ClinicMedicationBatch::query()->create([
            'medication_id' => $medication->id,
            'batch_number' => 'B-DEKAT',
            'expiry_date' => now()->addMonth()->toDateString(),
            'quantity_received' => 15,
            'status' => 'AVAILABLE',
        ]);

        $visit = $this->visit($world['patient']);
        $prescriptionId = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_id' => $medication->id, 'quantity' => 5]],
        ])->assertCreated()->json('data.prescription.id');

        $response = $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense")->assertCreated();

        $this->assertSame($near->batch_number, $response->json('data.results.0.batch_number'));

        $dispensed = ClinicDispensing::query()->firstOrFail()->items()->firstOrFail();
        $this->assertSame($near->id, $dispensed->batch_id);
        $this->assertNotSame($far->id, $dispensed->batch_id);
    }

    public function test_resep_yang_sudah_diserahkan_penuh_tidak_bisa_diserahkan_lagi(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Selesai');
        $item = $this->inventoryItem('Vitamin C', 50);
        $medication = $this->medication(null, $item->id, 'Vitamin C');
        $visit = $this->visit($world['patient']);

        $prescriptionId = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_id' => $medication->id, 'quantity' => 3]],
        ])->assertCreated()->json('data.prescription.id');

        $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense")->assertCreated();

        $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense")->assertStatus(422);
        $this->assertSame(47.0, (float) $item->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_penyerahan_sebagian_diikuti_status_partial(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien Parsial');
        $item = $this->inventoryItem('Obat Batuk', 20);
        $medication = $this->medication(null, $item->id, 'Obat Batuk');
        $visit = $this->visit($world['patient']);

        $prescription = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_id' => $medication->id, 'quantity' => 10]],
            'notes' => 'Dua kali penyerahan',
        ])->assertCreated();

        $prescriptionId = $prescription->json('data.prescription.id');
        $itemId = $prescription->json('data.prescription.items.0.id');

        $response = $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense", [
            'items' => [['prescription_item_id' => $itemId, 'quantity' => 4]],
        ])->assertCreated();

        $this->assertSame('PARTIALLY_DISPENSED', $response->json('data.prescription_status'));
        $this->assertSame(16.0, (float) $item->fresh()->quantity);

        // Melebihi sisa resep: ditolak.
        $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense", [
            'items' => [['prescription_item_id' => $itemId, 'quantity' => 20]],
        ])->assertStatus(422);
    }

    public function test_koreksi_pemberian_obat_tidak_menghapus_riwayat(): void
    {
        $this->actAs($this->clinicStaff('DOCTOR')['user']);

        $world = $this->makePatientWithGuardian('Pasien MAR');
        $medication = $this->medication(null, null, 'Obat MAR');
        $visit = $this->visit($world['patient']);

        $administrationId = $this->postJson("/api/v1/clinic/visits/{$visit->id}/administrations", [
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'status' => 'GIVEN',
        ])->assertCreated()->json('data.id');

        $corrected = $this->putJson("/api/v1/clinic/administrations/{$administrationId}", [
            'status' => 'HELD',
            'notes' => 'Pasien menolak minum obat',
            'reason' => 'Salah mencatat status pemberian',
        ])->assertOk()->assertJsonPath('data.version', 2);

        // Baris lama tetap ada (hanya ditandai bukan berlaku), bukan dihapus.
        $this->assertSame(2, ClinicMedicationAdministration::query()->count());
        $this->assertSame(1, ClinicMedicationAdministration::query()->where('is_current', false)->count());
        $this->assertSame(
            $administrationId,
            $corrected->json('data.corrected_from_id'),
            'Versi baru harus merujuk baris yang dikoreksi',
        );

        // Amendment menyimpan nilai sebelum & sesudah beserta alasannya.
        $amendment = \App\Modules\Clinic\Models\ClinicRecordAmendment::query()
            ->where('record_id', $corrected->json('data.id'))
            ->where('version', 2)
            ->firstOrFail();

        $this->assertSame('Salah mencatat status pemberian', $amendment->reason);
        $this->assertSame('GIVEN', $amendment->previous_value['status']);
        $this->assertSame('HELD', $amendment->new_value['status']);
    }

    public function test_tabel_obat_tidak_menyimpan_saldo_stok(): void
    {
        $this->actAs($this->clinicStaff('PHARMACY_OFFICER')['user']);

        // Klinik tidak punya buku stok: saldo hanya ada di `inventory_items`.
        $this->assertFalse(
            Schema::hasColumn('clinic_medications', 'stock'),
            'Tabel obat tidak boleh memiliki kolom saldo stok',
        );
        $this->assertTrue(Schema::hasColumn('inventory_items', 'quantity'));

        $this->postJson('/api/v1/clinic/medications', [
            'code' => 'UJI-1',
            'name' => 'Obat Uji Baru',
            'stock' => 999,
        ])->assertCreated();

        $created = \App\Modules\Clinic\Models\ClinicMedication::query()->where('code', 'UJI-1')->firstOrFail();
        $this->assertNull($created->inventory_item_id, 'Obat belum dipetakan ke item Inventory');
        $this->assertNull($created->getAttribute('stock'));
    }

    public function test_petugas_non_farmasi_tidak_bisa_menyerahkan_obat(): void
    {
        $doctor = $this->clinicStaff('DOCTOR');
        $this->actAs($doctor['user']);

        $world = $this->makePatientWithGuardian('Pasien Wewenang');
        $item = $this->inventoryItem('Obat Wewenang', 10);
        $medication = $this->medication(null, $item->id, 'Obat Wewenang');
        $visit = $this->visit($world['patient']);

        $prescriptionId = $this->postJson("/api/v1/clinic/visits/{$visit->id}/prescriptions", [
            'items' => [['medication_id' => $medication->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.prescription.id');

        // Resepsionis boleh melihat resep, tetapi tidak boleh menyerahkan obat.
        $this->actAs($this->clinicStaff('CLINIC_RECEPTIONIST')['user']);
        $this->postJson("/api/v1/clinic/prescriptions/{$prescriptionId}/dispense")->assertForbidden();

        $this->assertSame(10.0, (float) $item->fresh()->quantity);
    }

    public function test_petugas_induksi_klinik_tidak_bisa_menegakkan_diagnosis(): void
    {
        $this->actAs($this->clinicStaff('UKS_OFFICER')['user']);

        $world = $this->makePatientWithGuardian('Pasien Diagnosis Wewenang');
        $visit = $this->visit($world['patient']);

        // Petugas UKS mencatat tindakan, tetapi diagnosis adalah wewenang medis.
        $this->postJson("/api/v1/clinic/visits/{$visit->id}/diagnoses", [
            'diagnosis_code' => 'J06',
            'type' => 'PRIMARY',
        ])->assertForbidden();

        $this->assertSame(0, $visit->diagnoses()->count());
    }
}
