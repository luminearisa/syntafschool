<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Application\Services\GradeCalculationService;
use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Models\LmsGradeItem;

/**
 * Keamanan & privasi nilai (§53).
 *
 * Siswa hanya nilai sendiri, wali hanya anak yang sah, guru hanya course yang
 * diizinkan, dan tidak ada jalur yang dapat mengubah nilai terkunci tanpa izin.
 */
class LmsGradebookSecurityTest extends LmsTestCase
{
    /** @return array<string, mixed> */
    private function scenario(int $studentCount = 2): array
    {
        $world = $this->world($studentCount);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $item = $this->makeManualItem($scenario['gradebook'], ['name' => 'Nilai Rahasia']);

        foreach ($scenario['user_ids'] as $index => $userId) {
            $this->recordGrade($item, $userId, 90.0 - $index * 20);
        }

        return [
            'world' => $world,
            'course' => $course,
            'gradebook' => $scenario['gradebook'],
            'item' => $item,
            'students' => $scenario['user_ids'],
        ];
    }

    public function test_siswa_tidak_dapat_melihat_nilai_siswa_lain_meski_memintanya(): void
    {
        $ctx = $this->scenario();
        $self = $ctx['students'][0];
        $other = $ctx['students'][1];

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);

        // Tanpa parameter: selalu nilai sendiri.
        $own = $this->getJson('/api/v1/lms/my/grades')->assertOk();
        $this->assertSame($self, (int) $own->json('data.user_id'));
        $this->assertTrue($own->json('data.is_self'));

        // Meminta nilai siswa lain ditolak, bukan dialihkan diam-diam (§53).
        $this->getJson('/api/v1/lms/my/grades?user_id='.$other)->assertForbidden();
    }

    public function test_siswa_tidak_dapat_membuka_nilai_course_siswa_lain(): void
    {
        $ctx = $this->scenario();
        $other = $ctx['students'][1];

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);

        $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/grades?user_id='.$other)
            ->assertForbidden();
    }

    public function test_siswa_tidak_dapat_menulis_nilai(): void
    {
        $ctx = $this->scenario();

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);

        $this->putJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grades/'.$ctx['students'][0], ['score' => 100])
            ->assertForbidden();

        $this->assertEqualsWithDelta(90.0, (float) $ctx['item']->resultFor($ctx['students'][0])?->final_score, 0.01);
    }

    public function test_wali_tidak_dapat_membuka_nilai_anak_orang_lain(): void
    {
        $ctx = $this->scenario();

        $guardian = \App\Models\Guardian::create(['full_name' => 'Wali Lain', 'phone' => '0812000002', 'is_active' => true]);

        $this->actingAsParent($guardian);

        $this->getJson('/api/v1/lms/children/'.$ctx['students'][0].'/grades')->assertForbidden();
    }

    public function test_guru_course_lain_tidak_dapat_membuka_gradebook(): void
    {
        $ctx = $this->scenario();

        // Guru lain: punya peran guru tetapi bukan pengampu course ini.
        $foreignTeacher = \App\Models\Employee::create([
            'nip' => '199002022026011002',
            'full_name' => 'Guru Mata Pelajaran Lain',
            'is_teacher' => true,
            'is_active' => true,
            'email' => 'guru.lain@syntaf.sch.id',
        ]);

        $this->actingAsTeacher($foreignTeacher);

        $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/gradebook')->assertForbidden();
        $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/gradebook/sheet')->assertForbidden();
        $this->getJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grades')->assertForbidden();
    }

    public function test_tamu_belum_terautentikasi_ditolak(): void
    {
        $ctx = $this->scenario();

        // Tanpa Sanctum: seluruh endpoint nilai adalah area terautentikasi.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/lms/my/grades')->assertStatus(401);
        $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/gradebook')->assertStatus(401);
    }

    public function test_item_tersembunyi_tidak_dihitung_dalam_nilai_akhir(): void
    {
        $ctx = $this->scenario(1);
        $student = $ctx['students'][0];

        $hidden = $this->makeManualItem($ctx['gradebook'], [
            'name' => 'Catatan Internal',
            'visibility' => 'HIDDEN',
            'weight' => 100,
        ]);

        $this->recordGrade($hidden, $student, 10);

        $calculation = app(GradeCalculationService::class)->calculate($ctx['gradebook']->refresh(), $student);

        // Nilai akhir hanya dari item yang boleh dihitung (90), bukan 10/0.
        $this->assertEqualsWithDelta(90.0, (float) $calculation['normalized'], 0.01);

        $hiddenItem = LmsGradeItem::query()->findOrFail($hidden->id);
        $this->assertFalse($hiddenItem->countsTowardFinal());
    }

    public function test_nilai_terkunci_tidak_dapat_diubah_guru_biasa_dan_riwayatnya_utuh(): void
    {
        $ctx = $this->scenario(1);
        $student = $ctx['students'][0];

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);
        $releases = app(GradeReleaseService::class);

        $releases->releaseStudent($ctx['gradebook'], $student, $actor);
        $releases->lock(\App\Modules\LMS\Models\LmsGradeRelease::query()->firstOrFail(), $actor, 'Pasca sinkronisasi');

        $historyBefore = \App\Modules\LMS\Models\LmsGradeHistory::query()->count();

        $response = $this->putJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grades/'.$student, ['score' => 10]);

        $this->assertContains($response->status(), [403, 422]);
        $this->assertEqualsWithDelta(90.0, (float) $ctx['item']->resultFor($student)?->final_score, 0.01);
        $this->assertSame($historyBefore, \App\Modules\LMS\Models\LmsGradeHistory::query()->count());
    }

    public function test_koreksi_massal_menghasilkan_riwayat_per_siswa(): void
    {
        $ctx = $this->scenario(2);
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);

        $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grades/bulk', [
            'entries' => [
                ['user_id' => $ctx['students'][0], 'score' => 95, 'feedback' => 'Diperbaiki setelah moderasi.'],
                ['user_id' => $ctx['students'][1], 'score' => 85],
            ],
        ])->assertOk();

        foreach ($ctx['students'] as $student) {
            $this->assertDatabaseHas('lms_grade_histories', [
                'grade_item_id' => $ctx['item']->id,
                'user_id' => $student,
                'action' => 'UPDATED',
            ]);
        }

        $this->assertEqualsWithDelta(95.0, (float) $ctx['item']->resultFor($ctx['students'][0])?->final_score, 0.01);
        $this->assertEqualsWithDelta(85.0, (float) $ctx['item']->resultFor($ctx['students'][1])?->final_score, 0.01);
    }

    public function test_nilai_melebihi_maksimum_ditolak(): void
    {
        $ctx = $this->scenario(1);
        $this->actingAsTeacher($ctx['world']['teacher']);

        $this->putJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grades/'.$ctx['students'][0], ['score' => 150])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(90.0, (float) $ctx['item']->resultFor($ctx['students'][0])?->final_score, 0.01);
    }

    public function test_extra_credit_boleh_melebihi_maksimum_item(): void
    {
        $ctx = $this->scenario(1);
        $this->actingAsTeacher($ctx['world']['teacher']);

        $bonus = $this->makeManualItem($ctx['gradebook'], [
            'name' => 'Poin Bonus',
            'is_extra_credit' => true,
            'maximum_grade' => 5,
            'weight' => 5,
        ]);

        $this->putJson('/api/v1/lms/grade-items/'.$bonus->id.'/grades/'.$ctx['students'][0], ['score' => 5])
            ->assertOk();

        $this->assertEqualsWithDelta(5.0, (float) $bonus->resultFor($ctx['students'][0])?->final_score, 0.01);
    }
}
