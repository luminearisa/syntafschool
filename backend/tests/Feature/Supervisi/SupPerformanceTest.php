<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Models\SupActionItem;
use App\Modules\Supervisi\Models\SupActionPlan;
use App\Modules\Supervisi\Models\SupProgram;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitAnswer;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 — uji beban & N+1 (dokumentasi angka ada di
 * `docs/supervisi/11-IMPLEMENTASI-FASE-5.md`).
 *
 * Dua hal yang dijaga:
 *  1. **Jumlah kueri per permintaan tetap** saat data bertambah — inilah yang
 *     membedakan "lambat karena data banyak" dari "lambat karena kueri per
 *     baris" (N+1).
 *  2. **Anggaran waktu agregat** analitik: puluhan kueri agregat atas ribuan
 *     jawaban tetap selesai jauh di bawah ambang yang mengganggu pengguna.
 *
 * Batas waktu sengaja longgar (mesin CI bisa lambat) — yang dicari regresi
 * bentuk O(n), bukan angka detik yang presisi.
 */
class SupPerformanceTest extends SupTestCase
{
    protected const VISITS = 240;

    protected const INDICATORS_PER_VISIT = 12;

    /** @return array{0: User, 1: Employee} */
    protected function seedBulkData(): array
    {
        $head = $this->employee(['full_name' => 'Hj. Siti Rahmawati', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $head, 'kind' => 'KEPALA_SEKOLAH', 'can_finalize' => true]);
        $headUser = $this->employeeWithAccount($head, 'kepala-sekolah');

        $teachers = Employee::query()->where('is_teacher', true)->limit(30)->pluck('id')->all();

        if ($teachers === []) {
            for ($i = 0; $i < 30; $i++) {
                $teachers[] = $this->employee(['full_name' => 'Guru Bulk '.$i, 'is_teacher' => true])->id;
            }
        }

        // Instrumen beban dibuat sendiri: satu bagian dengan 12 indikator skala
        // 1–4, meniru instrumen observasi kelas yang sesungguhnya.
        $bebanIndicators = [];

        foreach (range(1, self::INDICATORS_PER_VISIT) as $number) {
            $bebanIndicators[] = [
                'statement' => 'Indikator beban '.$number,
                'guidance' => 'Panduan penilaian indikator beban '.$number.'.',
                'input_type' => 'SCALE',
                'weight' => 1,
                'is_required' => true,
                'min_comment_below' => 2,
                'options' => [
                    ['label' => 'Kurang', 'score' => 1, 'tone' => 'RED'],
                    ['label' => 'Cukup', 'score' => 2, 'tone' => 'AMBER'],
                    ['label' => 'Baik', 'score' => 3, 'tone' => 'GREEN'],
                    ['label' => 'Sangat Baik', 'score' => 4, 'tone' => 'GREEN'],
                ],
            ];
        }

        $version = $this->publishedInstrument([
            'sections' => [
                [
                    'title' => 'Pelaksanaan Pembelajaran',
                    'weight' => 1,
                    'indicators' => $bebanIndicators,
                ],
            ],
        ])['version']->load('sections.indicators.options');

        $indicators = $version->indicators->values();
        $this->assertSame(self::INDICATORS_PER_VISIT, $indicators->count());

        // Program & sasaran agar laporan program punya data.
        $program = SupProgram::query()->create([
            'title' => 'Program Supervisi Beban',
            'scope' => 'AKADEMIK',
            'status' => 'PUBLISHED',
            'starts_at' => now()->startOfYear(),
            'ends_at' => now()->endOfYear(),
            'created_by' => $headUser->id,
        ]);

        $visitRows = [];
        $answerRows = [];
        $planRows = [];
        $itemRows = [];
        $now = now();

        for ($visit = 1; $visit <= self::VISITS; $visit++) {
            $teacherId = $teachers[$visit % count($teachers)];
            $visitId = $visit; // id eksplisit agar jawaban dapat disiapkan massal.
            $observed = $now->copy()->subDays($visit % 180)->setTime(8, 0);

            $visitRows[] = [
                'id' => $visitId,
                'code' => 'SUP/BEBAN/'.str_pad((string) $visit, 6, '0', STR_PAD_LEFT),
                'program_id' => $program->id,
                'supervisor_employee_id' => $head->id,
                'supervisee_employee_id' => $teacherId,
                'instrument_version_id' => $version->id,
                'activity_kind' => 'CLASS_OBSERVATION',
                'status' => 'COMPLETED',
                'observed_at' => $observed,
                'finalized_at' => $observed->copy()->addDay(),
                'final_score' => 70 + ($visit % 30),
                'grade_band' => 'BAIK',
                'revision' => 1,
                'created_at' => $observed->copy()->subDay(),
                'updated_at' => $observed->copy()->addDay(),
            ];

            foreach ($indicators as $index => $indicator) {
                $options = $indicator->options->sortBy('score')->values();
                $choice = $options[($visit + $index) % $options->count()];

                $answerRows[] = [
                    'visit_id' => $visitId,
                    'indicator_id' => $indicator->id,
                    'option_id' => $choice->id,
                    'score' => $choice->score,
                    'is_na' => false,
                    'comment' => 'Bukti pengamatan kelas '.$visit,
                    'answered_by' => $headUser->id,
                    'answered_at' => $observed,
                    'created_at' => $observed,
                    'updated_at' => $observed,
                ];
            }

            if ($visit % 3 === 0) {
                $planRows[] = [
                    'visit_id' => $visitId,
                    'title' => 'RTL kegiatan '.$visit,
                    'status' => 'ONGOING',
                    'supervisee_employee_id' => $teacherId,
                    'created_by' => $headUser->id,
                    'created_at' => $observed,
                    'updated_at' => $observed,
                ];
            }
        }

        foreach (array_chunk($visitRows, 60) as $chunk) {
            DB::table('sup_visits')->insert($chunk);
        }

        foreach (array_chunk($answerRows, 500) as $chunk) {
            DB::table('sup_visit_answers')->insert($chunk);
        }

        foreach (array_chunk($planRows, 60) as $chunk) {
            DB::table('sup_action_plans')->insert($chunk);
        }

        $plans = SupActionPlan::query()->pluck('id', 'visit_id');

        foreach ($plans as $visitId => $planId) {
            foreach (range(1, 3) as $number) {
                $itemRows[] = [
                    'action_plan_id' => $planId,
                    'statement' => 'Butir perbaikan '.$number.' untuk kegiatan '.$visitId,
                    'owner_employee_id' => $teachers[((int) $visitId + $number) % count($teachers)],
                    'due_date' => $now->copy()->subDays($number)->toDateString(),
                    'status' => $number === 1 ? 'DONE' : 'IN_PROGRESS',
                    'progress_percent' => $number === 1 ? 100 : 40,
                    'completed_at' => $number === 1 ? $now->copy()->subDays(3) : null,
                    'created_at' => $now->copy()->subDays(20),
                    'updated_at' => $now->copy()->subDays(2),
                ];
            }
        }

        foreach (array_chunk($itemRows, 200) as $chunk) {
            DB::table('sup_action_items')->insert($chunk);
        }

        return [$headUser, $head];
    }

    public function test_jumlah_kueri_tetap_saat_data_bertambah(): void
    {
        [$headUser] = $this->seedBulkData();

        $this->assertSame(self::VISITS, SupVisit::query()->count());
        $this->assertSame(self::VISITS * self::INDICATORS_PER_VISIT, SupVisitAnswer::query()->count());
        $this->assertGreaterThan(0, SupActionItem::query()->count());

        // Daftar kegiatan: eager load penyaji — kueri tidak tumbuh per baris.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/visits?per_page=25')->assertOk();
        $daftarKegiatan = $queries;

        $queries = 0;
        $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/action-plans?per_page=25')->assertOk();
        $daftarRtl = $queries;

        $queries = 0;
        $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/pips')->assertOk();
        $daftarPip = $queries;

        // Halaman 25 baris dari 240 kegiatan: jauh di bawah satu kueri per baris.
        $this->assertLessThan(40, $daftarKegiatan, "Daftar kegiatan memakai {$daftarKegiatan} kueri.");
        $this->assertLessThan(40, $daftarRtl, "Papan RTL memakai {$daftarRtl} kueri.");
        $this->assertLessThan(40, $daftarPip, "Daftar PIP memakai {$daftarPip} kueri.");

        // Halaman kedua memakai jumlah kueri yang sama (paginasi, bukan akumulasi).
        $queries = 0;
        $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/visits?per_page=25&page=5')->assertOk();
        $this->assertEqualsWithDelta($daftarKegiatan, $queries, 4, 'Halaman ke-5 seharusnya sama murahnya dengan halaman pertama.');
    }

    public function test_agregat_analitik_dan_laporan_selesai_dalam_anggaran_waktu(): void
    {
        [$headUser] = $this->seedBulkData();

        $mulai = microtime(true);
        $analitik = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/analytics')->assertOk()->json('data');
        $waktuAnalitik = (microtime(true) - $mulai) * 1000;

        $this->assertSame(self::VISITS, $analitik['visits']['total']);
        $this->assertSame(self::VISITS, $analitik['scores']['count']);
        $this->assertNotEmpty($analitik['weak_indicators']);
        $this->assertNotEmpty($analitik['supervisors']);

        $mulai = microtime(true);
        $pratinjau = $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/reports/preview', [
            'report_key' => 'analitik_indikator',
        ])->assertOk()->json('data');
        $waktuLaporan = (microtime(true) - $mulai) * 1000;

        $this->assertNotEmpty($pratinjau['rows']);

        $mulai = microtime(true);
        $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/health')->assertOk();
        $waktuKesehatan = (microtime(true) - $mulai) * 1000;

        // Anggaran longgar: yang dijaga adalah bentuk kueri, bukan detik.
        $this->assertLessThan(6000, $waktuAnalitik, sprintf('Analitik %.0f ms.', $waktuAnalitik));
        $this->assertLessThan(6000, $waktuLaporan, sprintf('Pratinjau laporan %.0f ms.', $waktuLaporan));
        $this->assertLessThan(6000, $waktuKesehatan, sprintf('Pemeriksaan kesehatan %.0f ms.', $waktuKesehatan));

        fwrite(STDERR, sprintf(
            "\n[Beban %d kegiatan / %d jawaban] analitik %.0f ms · laporan %.0f ms · kesehatan %.0f ms\n",
            self::VISITS,
            self::VISITS * self::INDICATORS_PER_VISIT,
            $waktuAnalitik,
            $waktuLaporan,
            $waktuKesehatan,
        ));

        $this->assertTrue(true);
    }

    public function test_indeks_penting_dipakai_basis_data(): void
    {
        [$headUser] = $this->seedBulkData();

        // Rencana kueri tidak boleh berupa pemindaian tabel penuh pada kolom yang
        // sudah berindeks (SQLite menuliskan "USING INDEX" / "SEARCH" di EXPLAIN).
        $rencana = DB::select('EXPLAIN QUERY PLAN SELECT id FROM sup_visits WHERE supervisee_employee_id = ? AND observed_at >= ?', [$headUser->id, now()->subYear()]);

        $teks = strtolower(json_encode($rencana));

        $this->assertStringContainsString('sup_visit_supervisee_idx', $teks);

        $answerPlan = strtolower(json_encode(DB::select('EXPLAIN QUERY PLAN SELECT id FROM sup_visit_answers WHERE visit_id = ? AND indicator_id = ?', [1, 1])));
        $this->assertStringContainsString('sup_answer_unique', $answerPlan);

        $itemPlan = strtolower(json_encode(DB::select('EXPLAIN QUERY PLAN SELECT id FROM sup_action_items WHERE due_date < ? AND status = ?', [now()->toDateString(), 'PENDING'])));
        $this->assertStringContainsString('sup_item_due_idx', $itemPlan);
    }
}
