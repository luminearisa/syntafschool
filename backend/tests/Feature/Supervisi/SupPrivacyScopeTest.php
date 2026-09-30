<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Models\SupSetting;

/**
 * Fase 5 — privasi hasil supervisi (blueprint §1.2 & §11.6).
 *
 * "guru melihat detail miliknya; rekan sejawat hanya melihat ringkasan bila
 * sekolah mengaktifkan." Izin `supervisi.report.view` dimiliki guru dan staf
 * **untuk membaca hasilnya sendiri** — bukan nilai rekan sejawat. Tes ini
 * menegakkan batas itu pada analitik, laporan, dan riwayat dokumen.
 */
class SupPrivacyScopeTest extends SupTestCase
{
    /** @return array{0: Employee, 1: User, 2: Employee, 3: User, 4: Employee, 5: User} */
    protected function actors(): array
    {
        $head = $this->employee(['full_name' => 'Hj. Siti Rahmawati', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $head, 'kind' => 'KEPALA_SEKOLAH', 'can_finalize' => true]);
        $headUser = $this->employeeWithAccount($head, 'kepala-sekolah');

        $guru = $this->employee(['full_name' => 'Ahmad Fauzi', 'position' => 'Guru Biologi', 'is_teacher' => true]);
        $guruUser = $this->employeeWithAccount($guru, 'guru');

        $lain = $this->employee(['full_name' => 'Siti Aminah', 'position' => 'Guru Matematika', 'is_teacher' => true]);
        $lainUser = $this->employeeWithAccount($lain, 'guru');

        return [$head, $headUser, $guru, $guruUser, $lain, $lainUser];
    }

    /** Kegiatan yang sudah difinalkan untuk satu pegawai. */
    protected function finalizedVisit(User $assessor, Employee $supervisee, int $optionIndex = 3): array
    {
        $version = $this->publishedInstrument()['version']->load('sections.indicators.options');

        $visitId = (int) $this->actingAsUser($assessor)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $supervisee->id,
            'instrument_version_id' => $version->id,
        ])->assertCreated()->json('data.visit.id');

        $answers = [];
        foreach ($version->indicators as $indicator) {
            $options = $indicator->options->sortBy('score')->values();
            $choice = min($optionIndex, $options->count() - 1);

            $answers[] = match ($indicator->input_type->value) {
                'SCALE' => ['indicator_id' => $indicator->id, 'option_id' => $options[$choice]->id, 'comment' => 'Bukti observasi.'],
                'NUMBER' => ['indicator_id' => $indicator->id, 'value_number' => 5 + $choice, 'comment' => 'Bukti angka.'],
                'BOOLEAN' => ['indicator_id' => $indicator->id, 'value_text' => $choice > 0 ? 'YES' : 'NO', 'comment' => 'Diperiksa.'],
                default => ['indicator_id' => $indicator->id, 'value_long_text' => 'Catatan.'],
            };
        }

        $this->actingAsUser($assessor)->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $answers])->assertOk();

        $this->actingAsUser($assessor)->putJson("/api/v1/supervisi/visits/{$visitId}/findings", ['findings' => [[
            'component' => 'PELAKSANAAN',
            'priority' => 'HIGH',
            'weakness' => 'Rutinitas penutup belum ada',
            'cause' => 'Belum terbiasa',
            'recommendation' => 'Susun rutinitas penutup',
        ]]])->assertOk();

        $this->actingAsUser($assessor)->putJson("/api/v1/supervisi/visits/{$visitId}/conferences/POST", [
            'held_at' => now()->toIso8601String(),
            'agreements' => ['Menjalankan rutinitas penutup'],
            'reflection' => 'Disepakati.',
        ])->assertOk();

        $this->actingAsUser($assessor)->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")->assertOk();

        return ['id' => $visitId];
    }

    public function test_guru_hanya_melihat_hasil_supervisi_miliknya(): void
    {
        [$head, $headUser, $guru, $guruUser, $lain] = $this->actors();

        $this->finalizedVisit($headUser, $guru, 3);
        $this->finalizedVisit($headUser, $lain, 3);

        // Analitik: cakupan "diri", tanpa nama rekan sejawat.
        $analitik = $this->actingAsUser($guruUser)->getJson('/api/v1/supervisi/analytics')->assertOk()->json('data');

        $this->assertSame('DIRI', $analitik['scope']['level']);
        $this->assertStringContainsString('privasi', (string) $analitik['scope']['note']);
        $this->assertSame([], $analitik['coverage']['unsupervised']);
        $this->assertSame([], $analitik['appraisals']['needs_pip_rows']);
        $this->assertSame(1, $analitik['visits']['finalized']);
        $this->assertSame(1, $analitik['coverage']['supervised']);
        $this->assertNotNull($analitik['scores']['average']);

        // Nama atasan sendiri boleh tampil; nama rekan sejawat tidak.
        $nama = json_encode($analitik, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Hj. Siti Rahmawati', $nama);
        $this->assertStringNotContainsString('Siti Aminah', $nama);

        // Ringkasan agregat sekolah tidak ikut bila sekolah belum mengizinkan.
        $this->assertSame([], $analitik['weak_indicators']);
        $this->assertSame([], $analitik['sections']);

        // Laporan personal: hanya baris dirinya.
        $hasil = $this->actingAsUser($guruUser)->postJson('/api/v1/supervisi/reports/preview', [
            'report_key' => 'hasil_supervisi',
        ])->assertOk()->json('data');

        $this->assertEquals(1, $hasil['summary']['visits']);
        $this->assertCount(1, $hasil['rows']);
        $this->assertContains($guru->full_name, $hasil['rows'][0]);
        $this->assertNotContains('Siti Aminah', $hasil['rows'][0]);
        $this->assertSame('Data pegawai yang bersangkutan saja', $hasil['summary']['cakupan_data']);

        // Laporan agregat sekolah ditolak untuk guru.
        $this->actingAsUser($guruUser)->postJson('/api/v1/supervisi/reports/preview', [
            'report_key' => 'program_supervisi',
        ])->assertStatus(403);

        // Katalog laporan tidak menawarkan laporan agregat.
        $katalog = $this->actingAsUser($guruUser)->getJson('/api/v1/supervisi/reports/catalog')->assertOk()->json('data');
        $this->assertSame(['hasil_supervisi', 'tindak_lanjut', 'rekap_penilaian'], collect($katalog['reports'])->pluck('key')->all());
        $this->assertEqualsCanonicalizing(['program_supervisi', 'analitik_indikator', 'kalibrasi_penilai'], $katalog['restricted']);

        // Kepala sekolah tetap melihat seluruh sekolah.
        $sekolah = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/analytics')->assertOk()->json('data');
        $this->assertSame('SEKOLAH', $sekolah['scope']['level']);
        $this->assertSame(2, $sekolah['visits']['finalized']);
        $this->assertNotEmpty($sekolah['weak_indicators']);

        $semua = $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports/preview', [
            'report_key' => 'hasil_supervisi',
        ])->assertOk()->json('data');
        $this->assertCount(2, $semua['rows']);
    }

    public function test_riwayat_dokumen_dan_ekspor_mengikuti_cakupan(): void
    {
        [$head, $headUser, $guru, $guruUser, $lain] = $this->actors();

        $this->finalizedVisit($headUser, $guru);
        $this->finalizedVisit($headUser, $lain);

        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports', [
            'report_key' => 'hasil_supervisi',
            'format' => 'CSV',
        ])->assertCreated();

        // Guru hanya melihat riwayat dokumen miliknya (belum ada).
        $riwayatGuru = $this->actingAsUser($guruUser)->getJson('/api/v1/supervisi/reports/history')->assertOk()->json('data');
        $this->assertCount(0, $riwayatGuru['rows']);
        $this->assertSame('DIRI', $riwayatGuru['scope']['level']);

        // Kepala sekolah melihat dokumen yang ia hasilkan.
        $riwayatKepala = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/reports/history')->assertOk()->json('data');
        $this->assertCount(1, $riwayatKepala['rows']);
    }

    public function test_sekolah_dapat_mengizinkan_ringkasan_agregat_bagi_rekan_sejawat(): void
    {
        [$head, $headUser, $guru, $guruUser, $lain] = $this->actors();

        $this->finalizedVisit($headUser, $guru, 3);
        $this->finalizedVisit($headUser, $lain, 1);

        SupSetting::query()->create([
            'key' => 'peer_summary_enabled',
            'value' => '1',
            'cast' => 'bool',
        ]);

        $analitik = $this->actingAsUser($guruUser)->getJson('/api/v1/supervisi/analytics')->assertOk()->json('data');

        // Ringkasan agregat terbuka…
        $this->assertNotEmpty($analitik['weak_indicators']);
        $this->assertNotEmpty($analitik['scores']['distribution']);

        // …tetapi identitas rekan sejawat tetap tertutup.
        $this->assertSame([], $analitik['coverage']['unsupervised']);
        $this->assertSame([], $analitik['appraisals']['needs_pip_rows']);
        $this->assertStringNotContainsString('Siti Aminah', json_encode($analitik, JSON_UNESCAPED_UNICODE));

        // Laporan agregat pun boleh dibuka (tetap tanpa nama pegawai).
        $katalog = $this->actingAsUser($guruUser)->getJson('/api/v1/supervisi/reports/catalog')->assertOk()->json('data');
        $this->assertSame([], $katalog['restricted']);
        $this->assertCount(6, $katalog['reports']);
    }

    public function test_akun_pegawai_tidak_bisa_menghasilkan_dokumen_seluruh_sekolah(): void
    {
        [$head, $headUser, $guru, $guruUser, $lain] = $this->actors();

        $this->finalizedVisit($headUser, $guru);
        $this->finalizedVisit($headUser, $lain);

        // Guru tidak memegang `report.export`, jadi dilarang pada lapisan izin.
        $this->actingAsUser($guruUser)->postJson('/api/v1/supervisi/reports', [
            'report_key' => 'hasil_supervisi',
            'format' => 'CSV',
        ])->assertStatus(403);

        // Kepala sekolah (pemegang ekspor) tetap dapat menghasilkan dokumen.
        $dokumen = $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports', [
            'report_key' => 'hasil_supervisi',
            'format' => 'CSV',
        ])->assertCreated()->json('data');

        $this->assertSame(2, $dokumen['rows_count']);
    }
}
