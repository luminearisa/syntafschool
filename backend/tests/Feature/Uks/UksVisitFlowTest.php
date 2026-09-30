<?php

namespace Tests\Feature\Uks;

use App\Modules\UKS\Models\UksMedicine;
use App\Modules\UKS\Models\UksVisit;

/**
 * Alur inti UKS: guru merujuk siswa dari kelas, petugas menangani, memberi
 * obat (stok berkurang), orang tua menyetujui, lalu kasus ditutup.
 */
class UksVisitFlowTest extends UksTestCase
{
    public function test_alur_kunjungan_lengkap_dari_rujukan_guru_sampai_tutup(): void
    {
        $homeroom = $this->makeEmployee('Ibu Wali Kelas');
        $class = $this->makeClass($homeroom);
        $student = $this->makeStudent('Budi Santoso', $class);
        $teacher = $this->accountFor('wali-kelas', $homeroom);

        $officer = $this->makeOfficer();
        $parent = $this->makeGuardian($student);

        // Guru mencatat rujukan dari kelas.
        $this->actAs($teacher);
        $create = $this->postJson('/api/v1/uks/visits', [
            'student_id' => $student->id,
            'visit_type' => 'RUJUKAN_GURU',
            'complaint' => 'Demam sejak pagi',
            'severity' => 'SEDANG',
        ])->assertCreated();

        $visitId = $create->json('data.id');
        $this->assertNotEmpty($create->json('data.code'));
        $this->assertSame($class->id, $create->json('data.school_class_id'));
        $this->assertSame('MENUNGGU', $create->json('data.status'));

        // Petugas menyiapkan obat lalu menangani.
        $this->actAs($officer['user']);
        $medicine = $this->postJson('/api/v1/uks/medicines', [
            'code' => 'PCT500',
            'name' => 'Paracetamol 500mg',
            'unit' => 'tablet',
            'min_stock' => 5,
            'initial_stock' => 20,
        ])->assertCreated()->json('data');
        $this->assertEquals(20, $medicine['stock']);

        $this->postJson("/api/v1/uks/visits/{$visitId}/handle", [
            'treatment' => 'Kompres dan observasi',
            'advice' => 'Istirahat, minum banyak air',
            'needs_rest' => true,
            'status' => 'ISTIRAHAT',
        ])->assertOk()->assertJsonPath('data.status', 'ISTIRAHAT');

        $this->postJson("/api/v1/uks/visits/{$visitId}/medications", [
            'medicine_id' => $medicine['id'],
            'quantity' => 1,
            'dosage' => '1 tablet',
        ])->assertCreated();

        $this->assertEquals(19, UksMedicine::query()->find($medicine['id'])->stock);

        // Obat kedaluwarsa tidak boleh diberikan.
        $expired = UksMedicine::query()->create([
            'code' => 'EXP1', 'name' => 'Obat Lama', 'unit' => 'tablet',
            'stock' => 10, 'min_stock' => 0, 'expiry_date' => now()->subDay()->toDateString(), 'is_active' => true,
        ]);
        $this->postJson("/api/v1/uks/visits/{$visitId}/medications", [
            'medicine_id' => $expired->id, 'quantity' => 1,
        ])->assertStatus(422);

        // Stok tidak boleh habis minus.
        $this->postJson("/api/v1/uks/visits/{$visitId}/medications", [
            'medicine_id' => $medicine['id'], 'quantity' => 1000,
        ])->assertStatus(422);
        $this->assertEquals(19, UksMedicine::query()->find($medicine['id'])->stock);

        // Orang tua menyetujui lewat aplikasi.
        $this->actAs($parent['user']);
        $this->postJson("/api/v1/uks/visits/{$visitId}/consent", [
            'decision' => 'DISETUJUI',
            'note' => 'Silakan ditangani',
        ])->assertCreated()->assertJsonPath('data.channel', 'APLIKASI');

        // Orang tua siswa lain (di luar kelas guru ini) tidak bisa memberi persetujuan.
        $other = $this->makeGuardian($this->makeStudent('Anak Lain', $this->makeClass()));
        $this->actAs($other['user']);
        $this->postJson("/api/v1/uks/visits/{$visitId}/consent", ['decision' => 'DITOLAK'])->assertForbidden();

        // Petugas menutup kunjungan; setelah tertutup tidak bisa diubah.
        $this->actAs($officer['user']);
        $this->postJson("/api/v1/uks/visits/{$visitId}/close")->assertOk()
            ->assertJsonPath('data.status', 'SELESAI');
        $this->postJson("/api/v1/uks/visits/{$visitId}/handle", ['treatment' => 'ubah'])->assertStatus(422);

        $this->assertSame(1, UksVisit::query()->count());
    }

    public function test_kasus_berat_mengabari_orang_tua_otomatis(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Ani', $class);
        $parent = $this->makeGuardian($student);
        $officer = $this->makeOfficer();

        $this->actAs($officer['user']);
        $visitId = $this->postJson('/api/v1/uks/visits', [
            'student_id' => $student->id,
            'complaint' => 'Pingsan saat upacara',
            'severity' => 'DARURAT',
            'visit_type' => 'KECELAKAAN',
        ])->assertCreated()->json('data.id');

        $this->assertTrue((bool) UksVisit::query()->find($visitId)->parent_notified);
        $this->assertSame(1, $parent['user']->notifications()->count());

        // Rujukan juga mengabari orang tua dan mengubah status.
        $this->postJson("/api/v1/uks/visits/{$visitId}/referral", [
            'facility_name' => 'Puskesmas Kecamatan',
            'facility_type' => 'PUSKESMAS',
            'reason' => 'Perlu pemeriksaan dokter',
        ])->assertCreated();

        $this->assertSame('DIRUJUK', UksVisit::query()->find($visitId)->status->value);
        $this->assertSame(2, $parent['user']->fresh()->notifications()->count());

        // Rujukan kedua ditolak.
        $this->postJson("/api/v1/uks/visits/{$visitId}/referral", [
            'facility_name' => 'RS', 'reason' => 'lagi',
        ])->assertStatus(422);
    }

    public function test_pengguna_tanpa_izin_ditolak(): void
    {
        $this->actingAsRole('wali-murid');

        $this->postJson('/api/v1/uks/visits', [
            'student_id' => 1, 'complaint' => 'sakit',
        ])->assertForbidden();

        $this->getJson('/api/v1/uks/medicines')->assertForbidden();
    }
}
