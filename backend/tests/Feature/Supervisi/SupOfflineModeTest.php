<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Models\SupCalibrationScore;
use App\Modules\Supervisi\Models\SupCalibrationSession;
use App\Modules\Supervisi\Models\SupReport;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitAnswer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fase 5 — mode luring (jawaban dari kelas tanpa sinyal) & formulir cetak.
 */
class SupOfflineModeTest extends SupTestCase
{
    /** @return array{0: Employee, 1: User, 2: Employee, 3: array{version:object, indicators:array<int,int>, options:array<int,array<int,int>>}} */
    protected function setupVisit(): array
    {
        $head = $this->employee(['full_name' => 'Hj. Siti Rahmawati', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $head, 'kind' => 'KEPALA_SEKOLAH', 'can_finalize' => true]);
        $headUser = $this->employeeWithAccount($head, 'kepala-sekolah');

        $teacher = $this->employee(['full_name' => 'Ahmad Fauzi', 'position' => 'Guru Biologi']);
        $this->employeeWithAccount($teacher, 'guru');

        $version = $this->publishedInstrument()['version']->load('sections.indicators.options');

        $visitId = (int) $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $version->id,
        ])->assertCreated()->json('data.visit.id');

        $indicators = [];
        $options = [];

        foreach ($version->indicators as $indicator) {
            $indicators[$indicator->id] = $indicator->input_type->value;
            $options[$indicator->id] = $indicator->options->sortBy('sort_order')->pluck('id')->all();
        }

        return [$head, $headUser, $teacher, ['visit_id' => $visitId, 'indicators' => $indicators, 'options' => $options]];
    }

    public function test_sinkronisasi_luring_idempoten_dan_tahan_sebagian(): void
    {
        [, $headUser, , $setup] = $this->setupVisit();

        $visitId = $setup['visit_id'];
        $ids = array_keys($setup['indicators']);
        $scaleId = null;
        $numberId = null;
        $booleanId = null;

        foreach ($setup['indicators'] as $id => $type) {
            match ($type) {
                'SCALE' => $scaleId ??= $id,
                'NUMBER' => $numberId ??= $id,
                'BOOLEAN' => $booleanId ??= $id,
                default => null,
            };
        }

        $uuid = (string) Str::uuid();

        $antrean = [
            // Dikirim sebagai kode huruf, seperti yang ditulis di formulir cetak.
            ['indicator_id' => $scaleId, 'option_code' => 'D', 'comment' => 'Bukti dari kelas.', 'client_uuid' => $uuid, 'captured_at' => now()->subHour()->toIso8601String()],
            ['indicator_id' => $numberId, 'value_number' => 8, 'comment' => 'Pencatatan manual.', 'client_uuid' => (string) Str::uuid(), 'captured_at' => now()->subHour()->toIso8601String()],
            ['indicator_id' => $booleanId, 'value_text' => 'yes', 'comment' => 'Diperiksa.', 'client_uuid' => (string) Str::uuid(), 'captured_at' => now()->subHour()->toIso8601String()],
            // Indikator asing → ditolak, tidak membatalkan sisanya.
            ['indicator_id' => 999999, 'option_code' => 'A', 'client_uuid' => (string) Str::uuid()],
            // Nilai kosong → ditolak dengan alasan yang dapat dibaca.
            ['indicator_id' => $scaleId === $ids[0] ? $ids[1] : $ids[0], 'option_code' => '', 'client_uuid' => (string) Str::uuid()],
        ];

        $hasil = $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/answers/sync", ['device' => 'Tablet Ruang 3', 'answers' => $antrean])
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $hasil['applied']);
        $this->assertCount(2, $hasil['rejected']);
        $this->assertSame([], $hasil['skipped']);
        $this->assertSame([], $hasil['conflicts']);
        $this->assertSame('Tablet Ruang 3', $hasil['device']);
        $this->assertFalse($hasil['visit']['is_locked']);
        $this->assertSame(3, $hasil['visit']['answers_count']);

        // Huruf "D" menjadi opsi keempat (skor tertinggi).
        $answer = SupVisitAnswer::query()->where('visit_id', $visitId)->where('indicator_id', $scaleId)->firstOrFail();
        $this->assertEquals($setup['options'][$scaleId][3], $answer->option_id);
        $this->assertSame($uuid, $answer->client_uuid);

        // Kiriman kedua (jaringan putus lalu tersambung lagi): tidak ada ganda.
        $ulang = $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/answers/sync", ['answers' => $antrean])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $ulang['applied']);
        $this->assertCount(3, $ulang['skipped']);
        $this->assertCount(2, $ulang['rejected']);
        // Tiga jawaban valid tersimpan sekali saja — dua baris rusak tidak ikut.
        $this->assertSame(3, SupVisitAnswer::query()->where('visit_id', $visitId)->count());
    }

    public function test_sinkronisasi_luring_melaporkan_konflik_tanpa_menimpa_server(): void
    {
        [, $headUser, , $setup] = $this->setupVisit();

        $visitId = $setup['visit_id'];
        $scaleId = (int) collect($setup['indicators'])->search('SCALE');
        $options = $setup['options'][$scaleId];

        // Nilai sudah diisi di aplikasi (daring) setelah perangkat merekam…
        $this->actingAsUser($headUser)->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => [[
            'indicator_id' => $scaleId,
            'option_id' => $options[3],
            'comment' => 'Diisi langsung di aplikasi.',
        ]]])->assertOk();

        // …sementara perangkat membawa nilai lama (huruf A).
        $hasil = $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/answers/sync", ['answers' => [[
                'indicator_id' => $scaleId,
                'option_code' => 'A',
                'comment' => 'Hasil catatan kelas.',
                'client_uuid' => (string) Str::uuid(),
                'captured_at' => now()->subDay()->toIso8601String(),
            ]]])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $hasil['applied']);
        $this->assertCount(1, $hasil['conflicts']);
        $this->assertSame($scaleId, $hasil['conflicts'][0]['indicator_id']);
        $this->assertStringContainsString('server dipertahankan', $hasil['conflicts'][0]['reason']);

        // Server tidak ditimpa: nilai tetap yang diisi di aplikasi.
        $answer = SupVisitAnswer::query()->where('visit_id', $visitId)->where('indicator_id', $scaleId)->firstOrFail();
        $this->assertEquals($options[3], $answer->option_id);

        // Perangkat boleh mengirim ulang setelah supervisor memilih nilai final.
        $putusan = $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/answers/sync", ['answers' => [[
                'indicator_id' => $scaleId,
                'option_code' => 'C',
                'comment' => 'Disepakati ulang setelah diskusi.',
                'client_uuid' => (string) Str::uuid(),
                'captured_at' => now()->addMinute()->toIso8601String(),
            ]]])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $putusan['applied']);
        $this->assertEquals($options[2], SupVisitAnswer::query()->where('visit_id', $visitId)->where('indicator_id', $scaleId)->value('option_id'));
    }

    public function test_kegiatan_yang_sudah_final_menolak_sinkronisasi_luring(): void
    {
        [, $headUser, , $setup] = $this->setupVisit();

        $visitId = $setup['visit_id'];
        $scaleId = (int) collect($setup['indicators'])->search('SCALE');

        SupVisit::query()->whereKey($visitId)->update(['finalized_at' => now(), 'status' => 'COMPLETED']);

        $this->actingAsUser($headUser)->postJson("/api/v1/supervisi/visits/{$visitId}/answers/sync", ['answers' => [[
            'indicator_id' => $scaleId,
            'option_code' => 'A',
        ]]])->assertStatus(422);
    }

    public function test_formulir_kosong_dapat_dicetak_dari_versi_instrumen_kegiatan(): void
    {
        [, $headUser, , $setup] = $this->setupVisit();

        $respons = $this->actingAsUser($headUser)
            ->get("/api/v1/supervisi/visits/{$setup['visit_id']}/blank-form")
            ->assertOk();

        $berkas = (string) $respons->getContent();

        $this->assertStringStartsWith('%PDF-', $berkas);
        $this->assertStringContainsString('formulir-luring-', (string) $respons->headers->get('content-disposition'));

        // Guru lain (bukan pelaksana/yang disupervisi) tidak boleh mengunduh.
        $lain = $this->employee(['full_name' => 'Guru Lain']);
        $lainUser = $this->employeeWithAccount($lain, 'guru');

        $this->actingAsUser($lainUser)->get("/api/v1/supervisi/visits/{$setup['visit_id']}/blank-form")->assertStatus(403);
    }

    public function test_batas_laju_melindungi_endpoint_publik_dan_ekspor(): void
    {
        Storage::fake('local');

        [, $headUser, , $setup] = $this->setupVisit();

        // Verifikasi dokumen publik: 30 permintaan per menit per IP.
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/supervisi/reports/verify/UJICOBA'.str_pad((string) $i, 3, '0', STR_PAD_LEFT))
                ->assertStatus(404);
        }

        $this->getJson('/api/v1/supervisi/reports/verify/UJICOBA999')->assertStatus(429);

        // Ekspor dokumen dibatasi 20 per menit per pengguna.
        for ($i = 0; $i < 20; $i++) {
            $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports', [
                'report_key' => 'hasil_supervisi',
                'format' => 'CSV',
            ])->assertStatus(201);
        }

        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports', [
            'report_key' => 'hasil_supervisi',
            'format' => 'CSV',
        ])->assertStatus(429);

        $this->assertSame(20, SupReport::query()->count());
        $this->assertNotNull($setup['visit_id']);
    }

    public function test_pencarian_memperlakukan_wildcard_sebagai_teks_biasa(): void
    {
        [, $headUser] = $this->setupVisit();

        // Dua instrumen: satu dari fixture, satu lagi dibuat lewat API.
        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/instruments', [
            'name' => 'Observasi Kelas',
            'code' => 'OBS-KELAS-'.random_int(100, 999),
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
        ])->assertCreated();

        $semua = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/instruments')->assertOk()->json('meta.total');
        $this->assertGreaterThanOrEqual(2, $semua);

        // Kata kunci yang hanya berisi wildcard diabaikan (daftar tak tersaring),
        // bukan diterjemahkan menjadi pola LIKE — jadi tidak ada pemindaian liar.
        $wildcardSaja = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/instruments?search=%25')->assertOk();
        $this->assertSame($semua, $wildcardSaja->json('meta.total'));

        // `_` yang dulu mencocokkan karakter apa pun & `%` yang mencocokkan
        // segalanya kini diperlakukan sebagai teks: tidak ada hasil palsu.
        // Bila `_` masih wildcard, "Obs_rvasi" akan cocok dengan "Observasi";
        // sesudah dibersihkan ia menjadi "Obs rvasi" — tidak cocok.
        $this->assertSame(0, $this->actingAsUser($headUser)
            ->getJson('/api/v1/supervisi/instruments?search=Obs_rvasi')->assertOk()->json('meta.total'));

        // Sama untuk `%`: "Obs%rvasi" tidak lagi mencocokkan "Observasi".
        $this->assertSame(0, $this->actingAsUser($headUser)
            ->getJson('/api/v1/supervisi/instruments?search=Obs%25rvasi')->assertOk()->json('meta.total'));

        // Pencarian wajar tetap menemukan instrumennya.
        $this->assertSame(1, $this->actingAsUser($headUser)
            ->getJson('/api/v1/supervisi/instruments?search=Observasi')->assertOk()->json('meta.total'));
    }

    public function test_paginasi_kalibrasi_dibatasi_maksimum_seratus(): void
    {
        [, $headUser] = $this->setupVisit();

        $respons = $this->actingAsUser($headUser)
            ->getJson('/api/v1/supervisi/calibrations?per_page=100000')
            ->assertOk()
            ->json('data.meta');

        $this->assertSame(100, (int) $respons['per_page']);
    }

    public function test_kesehatan_menemukan_data_yang_tidak_konsisten(): void
    {
        [, $headUser, , $setup] = $this->setupVisit();

        $visitId = $setup['visit_id'];

        // Kegiatan difinalkan tanpa jawaban & tanpa nilai akhir (kerusakan nyata).
        SupVisit::query()->whereKey($visitId)->update([
            'finalized_at' => now(),
            'status' => 'COMPLETED',
            'final_score' => null,
        ]);

        SupCalibrationSession::query()->create([
            'title' => 'Moderasi tanpa kesepakatan',
            'status' => 'DRAFT',
            'threshold' => 1.0,
            'held_at' => now(),
            'created_by' => $headUser->id,
        ])->scores()->createMany([
            ['supervisor_employee_id' => $headUser->userable_id, 'indicator_label' => 'Kasus A', 'score' => 1],
            ['supervisor_employee_id' => $headUser->userable_id, 'indicator_label' => 'Kasus A', 'score' => 5],
        ]);

        $laporan = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/health')->assertOk()->json('data');

        $kritis = collect($laporan['checks'])->where('status', 'critical')->pluck('key')->all();
        $peringatan = collect($laporan['checks'])->where('status', 'warning')->pluck('key')->all();

        $this->assertContains('visit_final_without_answers', $kritis);
        $this->assertContains('visit_final_without_score', $kritis);
        $this->assertContains('calibration_pending_conclusion', $peringatan);
        $this->assertContains($laporan['status'], ['critical', 'warning']);
        $this->assertGreaterThan(0, $laporan['summary']['needs_attention']);

        // Guru tidak berhak membaca kesehatan modul.
        $guruUser = $this->employeeWithAccount($this->employee(['full_name' => 'Guru Biasa']), 'guru');
        $this->actingAsUser($guruUser)->getJson('/api/v1/supervisi/health')->assertStatus(403);

        $this->assertSame(2, SupCalibrationScore::query()->count());
    }

    public function test_penyapu_menghapus_berkas_tetapi_mempertahankan_riwayat_dokumen(): void
    {
        Storage::fake('local');

        [, $headUser, , $setup] = $this->setupVisit();

        $dokumen = $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports', [
            'report_key' => 'hasil_supervisi',
            'format' => 'CSV',
        ])->assertCreated()->json('data');

        $report = SupReport::query()->findOrFail($dokumen['id']);
        Storage::disk('local')->assertExists($report->path);

        // Berkas berumur 30 bulan.
        $report->forceFill(['created_at' => now()->subMonths(30)])->save();
        SupReport::query()->whereKey($report->id)->update(['created_at' => now()->subMonths(30)]);

        // Tanpa --force: hanya melapor.
        $this->artisan('supervisi:prune')->assertSuccessful();
        Storage::disk('local')->assertExists($report->path);

        // Dengan --force: berkas hilang, baris riwayat (kode & checksum) tetap.
        $this->artisan('supervisi:prune', ['--force' => true])->assertSuccessful();

        Storage::disk('local')->assertMissing($report->path);

        $tersimpan = SupReport::query()->findOrFail($report->id);
        $this->assertNull($tersimpan->path);
        $this->assertSame('PRUNED', $tersimpan->status);
        $this->assertSame($dokumen['verification_code'], $tersimpan->verification_code);
        $this->assertNotNull($tersimpan->checksum);
        $this->assertSame($setup['visit_id'], (int) $setup['visit_id']);
    }
}
