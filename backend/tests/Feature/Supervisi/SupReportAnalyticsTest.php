<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Models\SupReport;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Fase 4 — analitik, laporan wajib, ekspor CSV/XLSX/PDF, dan verifikasi dokumen.
 */
class SupReportAnalyticsTest extends SupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** @return array{0: Employee, 1: User, 2: Employee, 3: User} */
    protected function actors(): array
    {
        $head = $this->employee(['full_name' => 'Hj. Siti Rahmawati', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $head, 'kind' => 'KEPALA_SEKOLAH', 'can_finalize' => true]);
        $headUser = $this->employeeWithAccount($head, 'kepala-sekolah');

        $teacher = $this->employee(['full_name' => 'Ahmad Fauzi', 'position' => 'Guru Biologi']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');

        return [$head, $headUser, $teacher, $teacherUser];
    }

    /**
     * Kegiatan yang sudah difinalkan dengan nilai sesuai opsi yang dipilih.
     *
     * @param  list<int>  $optionIndexes  indeks opsi rubrik per indikator (0 = terendah)
     */
    protected function finalizedVisit(User $assessor, Employee $supervisee, array $optionIndexes = []): SupVisit
    {
        $version = $this->publishedInstrument()['version']->load('sections.indicators.options');

        $visitId = (int) $this->actingAsUser($assessor)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $supervisee->id,
            'instrument_version_id' => $version->id,
            'observed_at' => now()->subDays(3)->toDateTimeString(),
        ])->assertCreated()->json('data.visit.id');

        $answers = [];
        $index = 0;

        foreach ($version->indicators as $indicator) {
            $options = $indicator->options->sortBy('score')->values();
            $choice = $optionIndexes === [] ? $options->count() - 1 : $optionIndexes[$index % count($optionIndexes)];
            $index++;

            // Komentar selalu disertakan: instrumen menuntut catatan untuk skor
            // di bawah ambang, dan laporan ingin menampilkan buktinya.
            $answers[] = match ($indicator->input_type->value) {
                'SCALE' => [
                    'indicator_id' => $indicator->id,
                    'option_id' => $options[min($choice, $options->count() - 1)]->id,
                    'comment' => 'Bukti observasi kelas dicatat petugas.',
                ],
                'NUMBER' => [
                    'indicator_id' => $indicator->id,
                    'value_number' => 5 + $choice,
                    'comment' => 'Bukti angka dari dokumen pendukung.',
                ],
                'BOOLEAN' => [
                    'indicator_id' => $indicator->id,
                    'value_text' => $choice <= 0 ? 'NO' : 'YES',
                    'comment' => 'Diperiksa langsung saat observasi.',
                ],
                default => ['indicator_id' => $indicator->id, 'value_long_text' => 'Catatan observasi kelas.'],
            };
        }

        $this->actingAsUser($assessor)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $answers])
            ->assertOk();

        $this->actingAsUser($assessor)->putJson("/api/v1/supervisi/visits/{$visitId}/findings", ['findings' => [[
            'component' => 'PELAKSANAAN',
            'priority' => 'HIGH',
            'weakness' => 'Pengelolaan waktu pada kegiatan penutup',
            'cause' => 'Belum ada rutinitas refleksi',
            'recommendation' => 'Susun rutinitas penutup 5 menit',
        ]]])->assertOk();

        $this->actingAsUser($assessor)->putJson("/api/v1/supervisi/visits/{$visitId}/conferences/POST", [
            'held_at' => now()->toIso8601String(),
            'agreements' => ['Menjalankan rutinitas penutup'],
            'reflection' => 'Guru menyepakati perbaikan.',
        ])->assertOk();

        $this->actingAsUser($assessor)->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")->assertOk();

        return SupVisit::query()->findOrFail($visitId);
    }

    public function test_analitik_menghitung_cakupan_nilai_dan_indikator_lemah(): void
    {
        [$head, $headUser, $teacher] = $this->actors();

        $baik = $this->finalizedVisit($headUser, $teacher, [3, 3, 2, 3]);
        $lemah = $this->finalizedVisit($headUser, $teacher, [0, 0, 1, 0]);

        $this->assertGreaterThan((float) $lemah->final_score, (float) $baik->final_score);

        $analitik = $this->actingAsUser($headUser)
            ->getJson('/api/v1/supervisi/analytics?from='.now()->startOfYear()->toDateString().'&to='.now()->endOfYear()->toDateString())
            ->assertOk()
            ->json('data');

        // Cakupan: satu pegawai disupervisi dari seluruh pegawai aktif.
        $this->assertGreaterThanOrEqual(1, $analitik['coverage']['supervised']);
        $this->assertEquals(
            $analitik['coverage']['total_employees'],
            $analitik['coverage']['supervised'] + $analitik['coverage']['not_supervised'],
        );
        $this->assertNotNull($analitik['coverage']['percent']);

        // Kegiatan & nilai akhir.
        $this->assertEquals(2, $analitik['visits']['finalized']);
        $this->assertEquals(2, $analitik['scores']['count']);
        $this->assertNotNull($analitik['scores']['median']);
        $this->assertNotEmpty($analitik['scores']['distribution']);
        $this->assertNotEmpty($analitik['trend']);
        $this->assertNotNull($analitik['trend'][0]['period']);

        // Indikator terlemah lebih rendah dari indikator terkuat.
        $indikator = $analitik['weak_indicators'];
        $this->assertNotEmpty($indikator);
        $this->assertLessThanOrEqual(
            max(array_column($indikator, 'average_percent')),
            $indikator[0]['average_percent'],
            'Indikator terlemah harus muncul lebih dahulu.',
        );

        // Supervisor dinilai indeks kelonggarannya.
        $this->assertNotEmpty($analitik['supervisors']);
        $this->assertArrayHasKey('leniency_index', $analitik['supervisors'][0]);
        $this->assertSame('Hj. Siti Rahmawati', $analitik['supervisors'][0]['full_name']);

        // Jendela terbalik tetap dilayani (tidak error 500).
        $this->actingAsUser($headUser)
            ->getJson('/api/v1/supervisi/analytics?from=2026-12-31&to=2026-01-01')
            ->assertOk();

        $this->assertSame($teacher->id, $lemah->supervisee_employee_id);
    }

    public function test_laporan_dibangun_dan_diekspor_dalam_tiga_format(): void
    {
        [, $headUser, $teacher] = $this->actors();

        $visit = $this->finalizedVisit($headUser, $teacher, [3, 3, 2, 3]);
        $this->actingAsUser($headUser)->postJson("/api/v1/supervisi/visits/{$visit->id}/action-plan")->assertCreated();

        $katalog = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/reports/catalog')->assertOk()->json('data');
        $this->assertCount(6, $katalog['reports']);
        $this->assertSame(['CSV', 'XLSX', 'PDF', 'JSON'], $katalog['formats']);

        $pratinjau = $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports/preview', [
            'report_key' => 'hasil_supervisi',
        ])->assertOk()->json('data');

        $this->assertSame($visit->code, $pratinjau['rows'][0][0]);
        $this->assertContains('Nilai akhir', $pratinjau['headers']);
        $this->assertEquals(1, $pratinjau['summary']['visits']);

        // Laporan tindak lanjut memuat butir RTL dari temuan.
        $rtl = $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports/preview', [
            'report_key' => 'tindak_lanjut',
        ])->assertOk()->json('data');

        $this->assertNotEmpty($rtl['rows']);
        $this->assertContains($rtl['rows'][0][8], [null, 'Tepat waktu', 'Terlambat']);

        // Laporan tidak dikenal ditolak 422.
        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports/preview', [
            'report_key' => 'laporan_karangan',
        ])->assertStatus(422);

        $kode = [];

        foreach (['CSV', 'XLSX', 'PDF'] as $format) {
            $hasil = $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports', [
                'report_key' => 'hasil_supervisi',
                'format' => $format,
            ])->assertCreated()->json('data');

            $kode[$format] = $hasil['verification_code'];

            $report = SupReport::query()->findOrFail($hasil['id']);
            Storage::disk('local')->assertExists($report->path);

            $berkas = (string) Storage::disk('local')->get($report->path);
            $this->assertSame(hash('sha256', $berkas), $report->checksum);
            $this->assertSame(64, strlen((string) $report->checksum));
            $this->assertSame($format, $report->format);

            match ($format) {
                // BOM + pemisah titik koma agar rapi di Excel berlokal Indonesia.
                'CSV' => $this->assertStringStartsWith("\xEF\xBB\xBF", $berkas)
                    && $this->assertStringContainsString(';', $berkas),
                'PDF' => $this->assertStringStartsWith('%PDF-', $berkas),
                default => null,
            };
        }

        // XLSX benar-benar arsip OpenXML berisi satu lembar kerja.
        $xlsx = SupReport::query()->where('format', 'XLSX')->latest('id')->firstOrFail();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($xlsx->path)) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<sheetData>', $sheet);
        $this->assertStringContainsString($visit->code, $sheet);
        $zip->close();

        // Unduhan hanya untuk pemegang izin ekspor.
        $pdf = SupReport::query()->where('format', 'PDF')->latest('id')->firstOrFail();
        $this->actingAsUser($headUser)->get('/api/v1/supervisi/reports/'.$pdf->id.'/download')->assertOk();

        $riwayat = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/reports/history')->assertOk()->json('data.rows');
        $this->assertCount(3, $riwayat);
        $this->assertEqualsCanonicalizing(array_values($kode), collect($riwayat)->pluck('verification_code')->all());
        $this->assertStringContainsString('/verifikasi-dokumen/', (string) $riwayat[0]['verification_url']);
    }

    /**
     * Setiap jenis laporan harus dapat dibangun dan diekspor di ketiga format.
     *
     * Laporan yang hanya rapi sebagai JSON tetapi gagal saat menjadi CSV/XLSX
     * (mis. nilai enum yang tidak dapat dikonversi menjadi teks) adalah cacat
     * yang tidak terlihat sampai kepala sekolah menekan tombol unduh — karena
     * itu diuji satu per satu di sini.
     */
    public function test_seluruh_jenis_laporan_dapat_diekspor_di_semua_format(): void
    {
        [, $headUser, $teacher] = $this->actors();

        $visit = $this->finalizedVisit($headUser, $teacher, [2, 3, 1, 2]);
        $this->actingAsUser($headUser)->postJson("/api/v1/supervisi/visits/{$visit->id}/action-plan")->assertCreated();

        $service = app(\App\Modules\Supervisi\Application\Services\ReportService::class);

        foreach ($service->keys() as $key) {
            $table = $service->build($key, []);

            $this->assertNotEmpty($table['headers'], "Laporan {$key} tanpa kolom.");
            $this->assertEquals($key, $table['key']);

            $csv = $service->toCsv($table);
            $this->assertStringContainsString(';', $csv, "CSV {$key} tidak memakai pemisah titik koma.");

            $xlsx = $service->toXlsx($table);
            $this->assertStringStartsWith('PK', $xlsx, "XLSX {$key} bukan arsip ZIP.");

            $pdf = $service->toPdf($table, 'UJICOBA');
            $this->assertStringStartsWith('%PDF-', $pdf, "PDF {$key} bukan berkas PDF.");
        }
    }

    public function test_verifikasi_dokumen_publik_mendeteksi_perubahan_berkas(): void
    {
        [, $headUser, $teacher] = $this->actors();

        $this->finalizedVisit($headUser, $teacher, [2, 2, 2, 2]);

        $hasil = $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports', [
            'report_key' => 'hasil_supervisi',
            'format' => 'CSV',
        ])->assertCreated()->json('data');

        $report = SupReport::query()->findOrFail($hasil['id']);

        $sah = $this->getJson('/api/v1/supervisi/reports/verify/'.$report->verification_code)->assertOk()->json('data');
        $this->assertTrue($sah['valid']);
        $this->assertTrue($sah['checksum_matches']);
        $this->assertSame('CSV', $sah['format']);
        $this->assertNotNull($sah['generated_at']);

        // Berkas disunting pihak lain → verifikasi gagal.
        Storage::disk('local')->put($report->path, "diubah;tanpa;izin\n");

        $ditolak = $this->getJson('/api/v1/supervisi/reports/verify/'.$report->verification_code)
            ->assertStatus(404)
            ->json('data');

        $this->assertFalse($ditolak['valid']);
        $this->assertFalse($ditolak['checksum_matches']);

        // Kode tak dikenal juga 404 dan tidak membocorkan apa pun.
        $this->getJson('/api/v1/supervisi/reports/verify/TIDAKADA')
            ->assertStatus(404)
            ->assertJsonPath('data.valid', false);
    }

    public function test_peran_tanpa_izin_ekspor_tidak_dapat_menghasilkan_atau_mengunduh_dokumen(): void
    {
        [, $headUser, $teacher, $teacherUser] = $this->actors();

        $this->finalizedVisit($headUser, $teacher, [3, 3, 3, 3]);

        $reportId = (int) $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports', [
            'report_key' => 'rekap_penilaian',
            'format' => 'CSV',
        ])->assertCreated()->json('data.id');

        // Guru boleh membaca analitik… (izin report.view)
        $this->actingAsUser($teacherUser)->getJson('/api/v1/supervisi/analytics')->assertOk();

        // …tetapi tidak boleh membuat atau mengunduh dokumen (report.export).
        $this->actingAsUser($teacherUser)->postJson('/api/v1/supervisi/reports', [
            'report_key' => 'rekap_penilaian',
            'format' => 'CSV',
        ])->assertStatus(403);

        $this->actingAsUser($teacherUser)->get('/api/v1/supervisi/reports/'.$reportId.'/download')->assertStatus(403);
    }
}
