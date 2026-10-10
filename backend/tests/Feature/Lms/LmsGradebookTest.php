<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Application\Services\GradeCalculationService;
use App\Modules\LMS\Application\Services\GradeItemService;
use App\Modules\LMS\Application\Services\GradebookService;
use App\Modules\LMS\Domain\Enums\AggregationMethod;
use App\Modules\LMS\Domain\Enums\MissingGradePolicy;
use App\Modules\LMS\Domain\Enums\WeightPolicy;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradeScale;

/**
 * Gradebook, grade item, kategori, bobot, kebijakan nilai & skala (§2–§11).
 *
 * Perhitungan diuji lewat service (bukan HTTP) supaya aturan penilaian
 * benar-benar terjaga terlepas dari lapisan transport.
 */
class LmsGradebookTest extends LmsTestCase
{
    public function test_gradebook_dibuat_otomatis_per_course_dengan_skala_default(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $gradebook = $this->gradebookFor($course);

        $this->assertSame($course->id, (int) $gradebook->course_id);
        $this->assertSame('ACTIVE', $gradebook->status->value);
        $this->assertSame(MissingGradePolicy::PENDING, $gradebook->missing_grade_policy);
        $this->assertNotNull($gradebook->grade_scale_id);

        // Idempoten: memanggil ulang tidak membuat gradebook kedua.
        $this->assertSame($gradebook->id, $this->gradebookFor($course)->id);
        $this->assertDatabaseCount('lms_gradebooks', 1);

        $scale = LmsGradeScale::query()->findOrFail($gradebook->grade_scale_id);
        $this->assertSame('A', $scale->letterFor(95));
        $this->assertSame('D', $scale->letterFor(62));
        $this->assertSame('E', $scale->letterFor(40));
    }

    public function test_aktivitas_bernilai_terbit_otomatis_punya_grade_item(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->firstOrFail();

        $activity = $this->publishActivity($this->makeActivity($section, [
            'title' => 'Tugas Aljabar',
            'activity_type' => 'ASSIGNMENT',
        ]));

        $item = LmsGradeItem::query()->where('activity_id', $activity->id)->first();

        $this->assertNotNull($item, 'Aktivitas bernilai harus punya grade item.');
        $this->assertSame('ASSIGNMENT', $item->source->value);
        $this->assertSame($activity->title, $item->name);
        $this->assertTrue($item->include_in_final);
        $this->assertSame($course->id, (int) $item->gradebook->course_id);
        $this->assertNotNull($item->category_id);
    }

    public function test_ensure_for_activity_tidak_membuat_item_ganda_saat_gradebook_baru(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->firstOrFail();

        // Aktivitas masih DRAFT dan course belum pernah punya gradebook.
        $activity = $this->makeActivity($section, [
            'title' => 'Esai Literasi Digital',
            'activity_type' => 'ASSIGNMENT',
        ]);

        $this->assertDatabaseCount('lms_gradebooks', 0);
        $this->assertDatabaseCount('lms_grade_items', 0);

        // ensureForCourse (dipanggil di dalam) membuat gradebook sekaligus
        // menyinkronkan aktivitas ini; dulu hal itu membuat create() kedua dan
        // melempar UniqueConstraintViolationException pada lms_grade_items.activity_id.
        $item = app(GradeItemService::class)->ensureForActivity($activity);

        $this->assertSame(1, LmsGradeItem::query()->where('activity_id', $activity->id)->count());
        $this->assertSame((int) $activity->id, (int) $item->activity_id);
    }

    public function test_grade_item_manual_tetap_tersedia_untuk_guru(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);

        $item = $this->makeManualItem($scenario['gradebook'], ['name' => 'Ujian Praktik', 'weight' => 40]);

        $this->assertSame('MANUAL', $item->source->value);
        $this->assertNull($item->activity_id);
        $this->assertTrue($item->isManual());

        $result = $this->recordGrade($item, $scenario['user_ids'][0], 88);

        $this->assertSame(88.0, (float) $result->final_score);
        $this->assertSame('GRADED', $result->status->value);
    }

    public function test_bobot_kategori_dan_kontribusi_dapat_ditelusuri(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $student = $scenario['user_ids'][0];

        // Contoh §15: Assignments 30%, Quiz 20%, Midterm 20%, Final 30%.
        $composition = [
            ['Assignments', 30.0, 80.0],
            ['Quiz', 20.0, 90.0],
            ['Midterm', 20.0, 85.0],
            ['Final Exam', 30.0, 90.0],
        ];

        foreach ($composition as [$name, $weight, $score]) {
            $rows = $this->categoryItems($gradebook, $name, $weight, [$score]);
            $this->recordGrade($rows[0]['item'], $student, $rows[0]['score']);
        }

        $calculation = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);

        // 80×30% + 90×20% + 85×20% + 90×30% = 86
        $this->assertEqualsWithDelta(86.0, (float) $calculation['normalized'], 0.01);
        $this->assertEqualsWithDelta(86.0, (float) $calculation['final_score'], 0.01);
        $this->assertTrue($calculation['passed']);

        $final = app(\App\Modules\LMS\Application\Services\FinalGradeService::class)
            ->forStudent($gradebook->refresh(), $student);

        $this->assertSame('B', $final['letter']);
        $this->assertSame('Baik', $final['predicate']);

        $byName = collect($calculation['blocks'])->keyBy('name');

        $this->assertEqualsWithDelta(80.0, (float) $byName['Assignments']['value'], 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $byName['Assignments']['weight'], 0.01);
        $this->assertSame(1, $byName['Final Exam']['counted']);
    }

    public function test_metode_agregasi_dapat_dikonfigurasi(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $student = $scenario['user_ids'][0];

        $scores = [70.0, 90.0, 80.0, 60.0];

        foreach ($scores as $index => $score) {
            $item = $this->makeManualItem($gradebook, ['name' => 'Kuis '.($index + 1), 'maximum_grade' => 100]);
            $this->recordGrade($item, $student, $score);
        }

        $expectations = [
            AggregationMethod::SIMPLE_MEAN->value => 75.0,
            AggregationMethod::HIGHEST->value => 90.0,
            AggregationMethod::LOWEST->value => 60.0,
            AggregationMethod::MEDIAN->value => 75.0,
            AggregationMethod::SUM->value => 300.0,
        ];

        foreach ($expectations as $method => $expected) {
            $gradebook->forceFill(['aggregation_method' => $method])->save();

            $calculation = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);

            $this->assertEqualsWithDelta(
                $expected,
                (float) $calculation['normalized'],
                0.01,
                'Agregasi '.$method.' tidak sesuai.',
            );
        }
    }

    public function test_nilai_kosong_tidak_dianggap_nol_pada_policy_pending(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $student = $scenario['user_ids'][0];

        $graded = $this->makeManualItem($gradebook, ['name' => 'Sudah dinilai']);
        $missing = $this->makeManualItem($gradebook, ['name' => 'Belum dinilai']);

        $this->recordGrade($graded, $student, 80);

        $calculation = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);

        $this->assertEqualsWithDelta(80.0, (float) $calculation['normalized'], 0.01, 'Nilai kosong tidak boleh menjadi 0.');
        $this->assertSame(1, $calculation['missing_count']);
        $this->assertSame(1, $calculation['pending_missing_count']);
        $this->assertTrue($calculation['is_incomplete']);

        $missingRow = collect($calculation['items'])->firstWhere('grade_item_id', (int) $missing->id);
        $this->assertNull($missingRow['normalized']);
        $this->assertFalse($missingRow['counted']);
        $this->assertTrue($missingRow['is_missing']);
    }

    public function test_policy_missing_as_zero_dan_exclude_memberi_hasil_berbeda(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $student = $scenario['user_ids'][0];

        $first = $this->makeManualItem($gradebook, ['name' => 'Nilai 90']);
        $second = $this->makeManualItem($gradebook, ['name' => 'Kosong']);

        $this->recordGrade($first, $student, 90);

        // EXCLUDE: item kosong tidak dihitung sama sekali → 90.
        $gradebook->forceFill(['missing_grade_policy' => MissingGradePolicy::EXCLUDE->value])->save();
        $excluded = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);
        $this->assertEqualsWithDelta(90.0, (float) $excluded['normalized'], 0.01);
        $this->assertFalse($excluded['is_incomplete']);

        // MISSING_AS_ZERO: hanya bila kebijakan sekolah memang demikian → 45.
        $gradebook->forceFill(['missing_grade_policy' => MissingGradePolicy::MISSING_AS_ZERO->value])->save();
        $asZero = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);
        $this->assertEqualsWithDelta(45.0, (float) $asZero['normalized'], 0.01);

        $this->assertNotNull($second->id);
    }

    public function test_pembebasan_tidak_dianggap_nol_dan_tercatat_di_riwayat(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $student = $scenario['user_ids'][0];

        $graded = $this->makeManualItem($gradebook, ['name' => 'Nilai 85']);
        $exemptItem = $this->makeManualItem($gradebook, ['name' => 'Tidak diikuti']);

        $this->recordGrade($graded, $student, 85);

        $teacher = $this->actingAsTeacher($world['teacher']);
        $result = app(\App\Modules\LMS\Application\Services\AssessmentResultService::class)
            ->exempt($exemptItem, $student, 'Sakit dengan surat dokter', $this->actorFor($teacher));

        $this->assertTrue($result->isExempt());
        $this->assertSame('EXEMPT', $result->status->value);
        $this->assertSame('Sakit dengan surat dokter', $result->exemption_reason);

        $calculation = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);

        $this->assertEqualsWithDelta(85.0, (float) $calculation['normalized'], 0.01);
        $this->assertSame(1, $calculation['exempt_count']);

        $this->assertDatabaseHas('lms_grade_histories', [
            'grade_item_id' => $exemptItem->id,
            'user_id' => $student,
            'action' => 'EXEMPTED',
            'reason' => 'Sakit dengan surat dokter',
        ]);
    }

    public function test_extra_credit_mengikuti_kebijakan_cap_uncapped_dan_ignore(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $student = $scenario['user_ids'][0];

        $base = $this->makeManualItem($gradebook, ['name' => 'Nilai utama', 'weight' => 100]);
        $this->recordGrade($base, $student, 100);

        $bonus = $this->makeManualItem($gradebook, [
            'name' => 'Extra credit',
            'is_extra_credit' => true,
            'weight' => 5,
        ]);
        $this->recordGrade($bonus, $student, 100);

        $gradebook->forceFill(['extra_credit_policy' => 'CAP'])->save();
        $capped = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);
        $this->assertEqualsWithDelta(100.0, (float) $capped['normalized'], 0.01, 'CAP membatasi nilai akhir.');
        $this->assertEqualsWithDelta(5.0, (float) $capped['extra_credit']['points'], 0.01);

        $gradebook->forceFill(['extra_credit_policy' => 'UNCAPPED'])->save();
        $uncapped = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);
        $this->assertEqualsWithDelta(105.0, (float) $uncapped['normalized'], 0.01);

        $gradebook->forceFill(['extra_credit_policy' => 'IGNORE'])->save();
        $ignored = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);
        $this->assertEqualsWithDelta(100.0, (float) $ignored['normalized'], 0.01);
    }

    public function test_drop_lowest_pada_kategori(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $student = $scenario['user_ids'][0];

        $category = $this->makeGradeCategory($gradebook, 'Kuis Harian', 100, ['drop_lowest' => 1]);

        foreach ([60.0, 90.0, 80.0] as $index => $score) {
            $item = $this->makeManualItem($gradebook, ['name' => 'Kuis '.($index + 1), 'category_id' => $category->id]);
            $this->recordGrade($item, $student, $score);
        }

        $calculation = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);

        // Nilai terendah (60) dibuang → rata-rata 85.
        $this->assertEqualsWithDelta(85.0, (float) $calculation['normalized'], 0.01);
    }

    public function test_weight_policy_strict_menolak_total_bobot_tidak_100(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $gradebook = $this->gradebookFor($course);

        $this->makeGradeCategory($gradebook, 'Tugas', 30);
        $this->makeGradeCategory($gradebook, 'Kuis', 20);

        $service = app(GradebookService::class);
        $gradebook->forceFill(['weight_policy' => WeightPolicy::STRICT->value])->save();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->assertWeightsValid($gradebook->refresh());
    }

    public function test_normalisasi_bobot_menjaga_proporsi(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $student = $scenario['user_ids'][0];

        // Total bobot 50 (bukan 100) → dinormalisasi, proporsi tetap 1:1.
        $first = $this->categoryItems($gradebook, 'A', 25, [80.0]);
        $second = $this->categoryItems($gradebook, 'B', 25, [90.0]);

        $this->recordGrade($first[0]['item'], $student, 80);
        $this->recordGrade($second[0]['item'], $student, 90);

        $calculation = app(GradeCalculationService::class)->calculate($gradebook->refresh(), $student);

        $this->assertEqualsWithDelta(85.0, (float) $calculation['normalized'], 0.01);
        $this->assertStringContainsString('dinormalisasi', (string) $calculation['weighting']['normalized_weight']);
    }

    public function test_skala_nilai_dapat_dikhususkan_per_course(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $gradebook = $this->gradebookFor($course);
        $service = app(GradebookService::class);

        $scale = $service->createScale([
            'name' => 'Skala Ketuntasan Course',
            'scope' => 'COURSE',
            'course_id' => $course->id,
            'is_default' => false,
        ], null, [
            ['grade' => 'LULUS', 'min_score' => 70, 'max_score' => 100, 'predicate' => 'Tuntas'],
            ['grade' => 'ULANG', 'min_score' => 0, 'max_score' => 69.99, 'predicate' => 'Belum tuntas'],
        ]);

        $resolved = $service->scaleFor($gradebook->refresh());

        $this->assertSame($scale->id, $resolved?->id);
        $this->assertSame('LULUS', $resolved?->letterFor(75));
        $this->assertSame('ULANG', $resolved?->letterFor(60));
    }

    public function test_gradebook_dibekukan_tidak_dapat_disunting_tanpa_izin(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $item = $this->makeManualItem($gradebook, ['name' => 'Nilai dibekukan']);
        $student = $scenario['user_ids'][0];

        $teacherUser = $this->actingAsTeacher($world['teacher']);
        app(GradebookService::class)->freeze($gradebook, $this->actorFor($teacherUser));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->recordGrade($item, $student, 90);
    }

    public function test_statistik_dan_distribusi_nilai_course(): void
    {
        $world = $this->world(3);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];
        $item = $this->makeManualItem($gradebook, ['name' => 'Ujian', 'passing_grade' => 70]);

        foreach ($scenario['user_ids'] as $index => $userId) {
            $this->recordGrade($item, $userId, [95.0, 85.0, 65.0][$index]);
        }

        $statistics = app(\App\Modules\LMS\Application\Services\GradeStatisticsService::class);

        $summary = $statistics->courseSummary($gradebook->refresh());

        $this->assertSame(3, $summary['student_count']);
        $this->assertEqualsWithDelta(81.67, (float) $summary['average'], 0.01);
        $this->assertEqualsWithDelta(85.0, (float) $summary['median'], 0.01);
        $this->assertEqualsWithDelta(95.0, (float) $summary['highest'], 0.01);
        $this->assertEqualsWithDelta(65.0, (float) $summary['lowest'], 0.01);
        $this->assertEqualsWithDelta(66.67, (float) $summary['pass_rate'], 0.01);
        $this->assertNull($summary['ranking'] ?? null, 'Ranking tidak ditampilkan secara default.');

        $distribution = collect($summary['distribution'])->keyBy('label');
        $this->assertSame(1, $distribution['90–100']['count']);
        $this->assertSame(1, $distribution['80–89']['count']);
        $this->assertSame(0, $distribution['70–79']['count']);
        $this->assertSame(1, $distribution['60–69']['count']);
        $this->assertSame(0, $distribution['<60']['count']);

        $itemStats = $statistics->itemStatistics($item->refresh());
        $this->assertSame(3, $itemStats['graded_count']);
        $this->assertSame(0, $itemStats['pending_count']);
    }
}
