<?php

namespace Tests\Feature\Cbt;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassStudent;
use App\Models\Employee;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Modules\CBT\Database\Seeders\CbtPermissionSeeder;
use App\Modules\CBT\Models\CbtAttempt;
use App\Modules\CBT\Models\CbtQuestion;
use App\Modules\CBT\Models\CbtSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CbtTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    private SchoolClass $class;

    private Semester $semester;

    private Student $student;

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CbtPermissionSeeder::class);

        $year = AcademicYear::create([
            'name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $this->semester = Semester::create([
            'academic_year_id' => $year->id, 'name' => 'Ganjil',
            'start_date' => '2026-07-01', 'end_date' => '2026-12-20', 'is_active' => true,
        ]);
        $this->subject = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $this->class = SchoolClass::create([
            'academic_year_id' => $year->id, 'name' => '7A', 'code' => '7A', 'capacity' => 32,
        ]);
        $this->student = Student::create(['nis' => '26001', 'full_name' => 'Aisyah']);
        ClassStudent::create([
            'school_class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'academic_year_id' => $year->id,
            'status' => 'aktif',
        ]);
        $this->studentUser = User::factory()->create(['is_active' => true]);
        $this->studentUser->forceFill([
            'userable_type' => Student::class,
            'userable_id' => $this->student->id,
        ])->save();
        $this->studentUser->syncRoles(['siswa']);
    }

    private function teacher(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles(['guru']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_bank_soal_menolak_kunci_ganda_dan_menyimpan_revisi(): void
    {
        $this->teacher();

        $this->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question_type' => 'multiple_choice',
            'stem' => '2 + 2 = ?',
            'options' => [
                ['option_key' => 'A', 'text' => '3', 'is_correct' => true],
                ['option_key' => 'B', 'text' => '4', 'is_correct' => true],
            ],
        ])->assertStatus(422);

        $created = $this->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question_type' => 'multiple_choice',
            'difficulty' => 'easy',
            'topic' => 'Aritmetika',
            'stem' => '2 + 2 = ?',
            'points' => 2,
            'options' => [
                ['option_key' => 'A', 'text' => '3', 'is_correct' => false],
                ['option_key' => 'B', 'text' => '4', 'is_correct' => true],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame(1, $created['version']);

        $updated = $this->patchJson('/api/v1/cbt/questions/'.$created['id'], [
            'stem' => '2 + 2 sama dengan ?',
        ])->assertOk()->json('data');

        $this->assertSame(2, $updated['version']);
        $this->assertDatabaseCount('cbt_question_revisions', 2);
    }

    public function test_siswa_tidak_bisa_membuat_soal(): void
    {
        Sanctum::actingAs($this->studentUser);

        $this->postJson('/api/v1/cbt/questions', [
            'question_type' => 'essay',
            'stem' => 'Jelaskan fotosintesis',
        ])->assertForbidden();
    }

    public function test_alur_ujian_acak_token_nilai_dan_impor_rapor(): void
    {
        $this->teacher();

        $mc = $this->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question_type' => 'multiple_choice',
            'topic' => 'Aljabar',
            'difficulty' => 'easy',
            'stem' => 'x + 1 = 3, x = ?',
            'points' => 2,
            'options' => [
                ['option_key' => 'A', 'text' => '1', 'is_correct' => false],
                ['option_key' => 'B', 'text' => '2', 'is_correct' => true],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question_type' => 'numeric',
            'topic' => 'Aljabar',
            'difficulty' => 'medium',
            'stem' => 'Akar kuadrat 9',
            'points' => 2,
            'payload' => ['expected' => 3, 'tolerance' => 0],
        ])->assertCreated();

        $this->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question_type' => 'short_answer',
            'topic' => 'Aljabar',
            'stem' => 'Ibu kota Indonesia',
            'payload' => ['accepted' => ['Jakarta', 'jakarta']],
        ])->assertCreated();

        $this->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question_type' => 'essay',
            'topic' => 'Aljabar',
            'stem' => 'Uraikan sifat komutatif',
            'points' => 4,
        ])->assertCreated();

        $blueprint = $this->postJson('/api/v1/cbt/blueprints', [
            'name' => 'UH Aljabar',
            'subject_id' => $this->subject->id,
            'duration_minutes' => 30,
            'question_count' => 3,
            'passing_score' => 60,
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'attempts_allowed' => 1,
            'rules' => [
                ['topic' => 'Aljabar', 'question_type' => 'numeric', 'count' => 1, 'points' => 2],
                ['topic' => 'Aljabar', 'question_type' => 'essay', 'count' => 1, 'points' => 4],
            ],
            'items' => [
                ['question_id' => $mc, 'points' => 2, 'required' => true],
            ],
        ])->assertCreated()->json('data');

        $session = $this->postJson('/api/v1/cbt/sessions', [
            'title' => 'UH 1 Matematika',
            'blueprint_id' => $blueprint['id'],
            'subject_id' => $this->subject->id,
            'school_class_id' => $this->class->id,
            'semester_id' => $this->semester->id,
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'settings' => ['require_token' => true, 'show_score' => false],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/enroll', ['from_class' => true])
            ->assertOk()
            ->assertJsonPath('data.added', 1);

        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/open')->assertOk();

        $token = CbtSession::find($session['id'])->access_token;

        Sanctum::actingAs($this->studentUser);

        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/start', ['token' => 'SALAH'])
            ->assertStatus(422);

        $player = $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/start', ['token' => $token])
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $player['questions']);
        $this->assertNull($player['questions'][0]['options'][0]['is_correct'] ?? null);

        $byType = collect($player['questions'])->keyBy('type');
        $mcId = $byType['multiple_choice']['id'];
        $this->putJson('/api/v1/cbt/attempts/'.$player['attempt']['id'].'/questions/'.$mcId, [
            'response' => ['key' => 'B'],
        ])->assertOk();

        if (isset($byType['numeric'])) {
            $this->putJson('/api/v1/cbt/attempts/'.$player['attempt']['id'].'/questions/'.$byType['numeric']['id'], [
                'response' => ['value' => 3],
            ])->assertOk();
        }

        $this->postJson('/api/v1/cbt/attempts/'.$player['attempt']['id'].'/incidents', [
            'type' => 'tab_blur',
            'note' => 'berpindah tab',
        ])->assertOk();

        $this->postJson('/api/v1/cbt/attempts/'.$player['attempt']['id'].'/heartbeat')->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $submitted = $this->postJson('/api/v1/cbt/attempts/'.$player['attempt']['id'].'/submit')
            ->assertOk()
            ->json('data');

        $this->assertTrue($submitted['needs_manual_grade']);
        $this->assertNull($submitted['score']);

        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/start', ['token' => $token])
            ->assertStatus(422);

        $attempt = CbtAttempt::find($player['attempt']['id']);
        $this->assertNotNull($attempt->score);
        $this->assertLessThan(100, $attempt->integrity_score);

        $this->teacher();
        $essay = $attempt->answers()->whereHas('question', fn ($q) => $q->where('question_type', 'essay'))->first();
        $this->assertNotNull($essay);

        $this->postJson('/api/v1/cbt/answers/'.$essay->id.'/grade', [
            'awarded' => 3,
            'note' => 'hampir lengkap',
        ])->assertOk();

        $attempt->refresh();
        $this->assertFalse($attempt->needs_manual_grade);
        $this->assertSame('graded', $attempt->status);
        $this->assertNotNull($attempt->passed);

        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/close')->assertOk();
        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/release')->assertOk();

        Sanctum::actingAs($this->studentUser);
        $this->getJson('/api/v1/cbt/attempts/'.$attempt->id.'/review')
            ->assertOk()
            ->assertJsonPath('data.attempt.passed', $attempt->passed);

        $this->teacher();
        $type = AssessmentType::create(['code' => 'uh', 'name' => 'Ulangan Harian', 'category' => 'pengetahuan']);
        $pushed = $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/push-scores', [
            'assessment_type_id' => $type->id,
        ])->assertCreated()->json('data');

        $this->assertSame(1, $pushed['scores']);
        $this->assertSame(1, Assessment::find($pushed['assessment_id'])->scores()->count());

        $report = $this->getJson('/api/v1/cbt/sessions/'.$session['id'].'/report')->assertOk()->json('data');
        $this->assertSame(1, $report['summary']['submitted']);
        $this->assertNotEmpty($report['items']);
    }

    public function test_waktu_habis_mengunci_jawaban(): void
    {
        $this->teacher();
        $q = CbtQuestion::create([
            'subject_id' => $this->subject->id,
            'question_type' => 'essay',
            'stem' => 'Esai',
            'points' => 5,
            'version' => 1,
            'is_active' => true,
        ]);
        $blueprint = $this->postJson('/api/v1/cbt/blueprints', [
            'name' => 'Singkat',
            'subject_id' => $this->subject->id,
            'duration_minutes' => 1,
            'question_count' => 1,
            'items' => [['question_id' => $q->id, 'points' => 5]],
        ])->assertCreated()->json('data');

        $session = $this->postJson('/api/v1/cbt/sessions', [
            'title' => 'Kedaluwarsa',
            'blueprint_id' => $blueprint['id'],
            'starts_at' => now()->subMinutes(5)->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'settings' => ['require_token' => false],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/enroll', [
            'student_ids' => [$this->student->id],
        ])->assertOk();
        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/open')->assertOk();

        Sanctum::actingAs($this->studentUser);
        $player = $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/start', [])
            ->assertOk()->json('data');

        $this->travel(10)->minutes();

        $this->putJson('/api/v1/cbt/attempts/'.$player['attempt']['id'].'/questions/'.$q->id, [
            'response' => ['text' => 'terlambat'],
        ])->assertStatus(422);

        $this->assertSame('expired', CbtAttempt::find($player['attempt']['id'])->status);
    }

    public function test_multi_select_dan_matching_dinilai_sebagian(): void
    {
        $this->teacher();

        $multi = $this->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question_type' => 'multi_select',
            'stem' => 'Bilangan genap',
            'points' => 4,
            'options' => [
                ['option_key' => 'A', 'text' => '2', 'is_correct' => true],
                ['option_key' => 'B', 'text' => '3', 'is_correct' => false],
                ['option_key' => 'C', 'text' => '4', 'is_correct' => true],
            ],
        ])->assertCreated()->json('data.id');

        $match = $this->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question_type' => 'matching',
            'stem' => 'Jodohkan',
            'points' => 4,
            'payload' => ['prompts' => ['A ibu kota', 'B sungai']],
            'options' => [
                ['option_key' => 'A', 'text' => 'Jakarta', 'match_key' => '1'],
                ['option_key' => 'B', 'text' => 'Nil', 'match_key' => '2'],
            ],
        ])->assertCreated()->json('data.id');

        $blueprint = $this->postJson('/api/v1/cbt/blueprints', [
            'name' => 'Campuran',
            'subject_id' => $this->subject->id,
            'duration_minutes' => 20,
            'question_count' => 2,
            'passing_score' => 50,
            'shuffle_questions' => false,
            'items' => [
                ['question_id' => $multi, 'points' => 4],
                ['question_id' => $match, 'points' => 4],
            ],
        ])->assertCreated()->json('data');

        $session = $this->postJson('/api/v1/cbt/sessions', [
            'title' => 'Campuran',
            'blueprint_id' => $blueprint['id'],
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'settings' => ['require_token' => false, 'show_score' => true],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/enroll', ['student_ids' => [$this->student->id]])->assertOk();
        $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/open')->assertOk();

        Sanctum::actingAs($this->studentUser);
        $player = $this->postJson('/api/v1/cbt/sessions/'.$session['id'].'/start')->assertOk()->json('data');
        $id = $player['attempt']['id'];

        $this->putJson("/api/v1/cbt/attempts/{$id}/questions/{$multi}", ['response' => ['keys' => ['A']]])->assertOk();
        $this->putJson("/api/v1/cbt/attempts/{$id}/questions/{$match}", [
            'response' => ['pairs' => ['A' => '1', 'B' => '9']],
        ])->assertOk();

        $this->postJson("/api/v1/cbt/attempts/{$id}/submit")->assertOk()->assertJsonPath('data.needs_manual_grade', false);

        $attempt = CbtAttempt::find($id);
        $this->assertEquals(4.0, (float) $attempt->score);
        $this->assertEquals(50.0, (float) $attempt->percentage);
    }

    public function test_impor_massal_melewati_baris_rusak(): void
    {
        $this->teacher();

        $result = $this->postJson('/api/v1/cbt/questions/import', [
            'subject_id' => $this->subject->id,
            'rows' => [
                [
                    'question_type' => 'true_false',
                    'stem' => 'Bumi bulat',
                    'options' => [
                        ['option_key' => 'T', 'text' => 'Benar', 'is_correct' => true],
                        ['option_key' => 'F', 'text' => 'Salah', 'is_correct' => false],
                    ],
                ],
                ['question_type' => 'multiple_choice', 'stem' => 'tanpa opsi'],
            ],
        ])->assertOk()->json('data');

        $this->assertSame(1, $result['created']);
        $this->assertCount(1, $result['failed']);
    }

    public function test_pengawas_tidak_wajib_jadi_guru_mapel(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $employee = Employee::create(['full_name' => 'Pengawas', 'employee_type' => 'staf', 'is_teacher' => false]);
        $user->syncRoles(['guru']);
        Sanctum::actingAs($user);

        $q = $this->postJson('/api/v1/cbt/questions', [
            'question_type' => 'true_false',
            'stem' => '1 > 0',
            'options' => [
                ['option_key' => 'T', 'text' => 'Benar', 'is_correct' => true],
                ['option_key' => 'F', 'text' => 'Salah', 'is_correct' => false],
            ],
        ])->assertCreated()->json('data.id');

        $blueprint = $this->postJson('/api/v1/cbt/blueprints', [
            'name' => 'TF',
            'duration_minutes' => 5,
            'items' => [['question_id' => $q]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/cbt/sessions', [
            'title' => 'Dengan pengawas',
            'blueprint_id' => $blueprint['id'],
            'proctor_employee_id' => $employee->id,
            'ends_at' => now()->addHour()->toIso8601String(),
            'starts_at' => now()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.proctor_employee_id', $employee->id);
    }
}
