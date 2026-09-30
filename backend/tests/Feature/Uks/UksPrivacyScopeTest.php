<?php

namespace Tests\Feature\Uks;

use App\Models\ClassSubject;

/**
 * Privasi catatan kesehatan: guru hanya melihat kelas yang diampunya, orang
 * tua hanya anaknya, siswa hanya dirinya, petugas & admin melihat semua.
 */
class UksPrivacyScopeTest extends UksTestCase
{
    public function test_setiap_peran_hanya_melihat_cakupannya(): void
    {
        $teacherA = $this->makeEmployee('Guru A');
        $teacherB = $this->makeEmployee('Guru B');
        // Wali kelas B juga mengajar satu mapel di kelas A — cakupannya harus
        // gabungan keduanya, bukan hanya kelas yang diwalikan.
        $classA = $this->makeClass($teacherA);
        $classB = $this->makeClass($teacherB);

        $studentA = $this->makeStudent('Siswa A', $classA);
        $studentB = $this->makeStudent('Siswa B', $classB);

        $accountA = $this->accountFor('guru', $teacherA);
        $accountB = $this->accountFor('wali-kelas', $teacherB);
        $parentA = $this->makeGuardian($studentA);
        $studentAccount = $this->accountFor('siswa', $studentA);
        $officer = $this->makeOfficer();

        // Dua kunjungan, satu per siswa.
        $this->actAs($officer['user']);
        foreach ([$studentA, $studentB] as $student) {
            $this->postJson('/api/v1/uks/visits', [
                'student_id' => $student->id,
                'complaint' => 'Pusing',
                'severity' => 'RINGAN',
            ])->assertCreated();
        }

        // Petugas melihat keduanya.
        $this->getJson('/api/v1/uks/visits')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/uks/me')->assertOk()->assertJsonPath('data.can_view_all', true);

        // Guru A hanya melihat siswa kelasnya.
        $this->actAs($accountA);
        $rows = $this->getJson('/api/v1/uks/visits')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($studentA->id, $rows[0]['student_id']);

        // Guru A tidak bisa membuka detail kunjungan siswa kelas lain.
        $visitB = \App\Modules\UKS\Models\UksVisit::query()->where('student_id', $studentB->id)->first();
        $this->getJson("/api/v1/uks/visits/{$visitB->id}")->assertForbidden();

        // Pemilih siswa pun mengikuti cakupan.
        $visible = $this->getJson('/api/v1/uks/students')->assertOk()->json('data');
        $this->assertSame([$studentA->id], array_column($visible, 'id'));

        // Guru B (wali kelas) melihat kelasnya sendiri.
        $this->actAs($accountB);
        $this->getJson('/api/v1/uks/visits')->assertOk()->assertJsonPath('meta.total', 1);

        // Bila guru B juga mengampu mapel di kelas A, ia melihat kedua siswa.
        ClassSubject::query()->create([
            'school_class_id' => $classA->id,
            'subject_id' => \App\Models\Subject::query()->create(['code' => 'MTK', 'name' => 'Matematika'])->id,
            'employee_id' => $teacherB->id,
            'academic_year_id' => $classA->academic_year_id,
        ]);
        $this->getJson('/api/v1/uks/visits')->assertOk()->assertJsonPath('meta.total', 2);

        // Orang tua hanya melihat anaknya, dan dasbornya bertanda lingkup ANAK.
        $this->actAs($parentA['user']);
        $this->getJson('/api/v1/uks/visits')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/uks/dashboard')->assertOk()
            ->assertJsonPath('data.scope.level', 'ANAK')
            ->assertJsonPath('data.visits.total', 1);
        $this->getJson("/api/v1/uks/records/{$studentB->id}")->assertForbidden();
        $this->getJson("/api/v1/uks/records/{$studentA->id}")->assertOk();

        // Siswa hanya melihat dirinya.
        $this->actAs($studentAccount);
        $this->getJson('/api/v1/uks/visits')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/uks/me')->assertOk()->assertJsonPath('data.is_student', true);
        $this->getJson("/api/v1/uks/records/{$studentB->id}")->assertForbidden();
    }

    public function test_guru_mata_pelajaran_melihat_siswa_kelas_yang_diajar(): void
    {
        $teacher = $this->makeEmployee('Guru Mapel');
        $class = $this->makeClass();
        $student = $this->makeStudent('Siswa Mapel', $class);

        ClassSubject::query()->create([
            'school_class_id' => $class->id,
            'subject_id' => \App\Models\Subject::query()->create(['code' => 'BIO', 'name' => 'Biologi'])->id,
            'employee_id' => $teacher->id,
            'academic_year_id' => $class->academic_year_id,
        ]);

        $officer = $this->makeOfficer();
        $this->actAs($officer['user']);
        $this->postJson('/api/v1/uks/visits', [
            'student_id' => $student->id, 'complaint' => 'Mual',
        ])->assertCreated();

        $this->actAs($this->accountFor('guru', $teacher));
        $this->getJson('/api/v1/uks/visits')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_kepala_sekolah_melihat_semua_tanpa_bisa_mengelola(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Siswa', $class);
        $officer = $this->makeOfficer();

        $this->actAs($officer['user']);
        $this->postJson('/api/v1/uks/visits', [
            'student_id' => $student->id, 'complaint' => 'Batuk',
        ])->assertCreated();

        $this->actingAsRole('kepala-sekolah');
        $this->getJson('/api/v1/uks/visits')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/uks/dashboard')->assertOk()->assertJsonPath('data.scope.level', 'SEKOLAH');

        // Melihat boleh, mengelola tidak.
        $this->postJson('/api/v1/uks/visits', [
            'student_id' => $student->id, 'complaint' => 'lagi',
        ])->assertForbidden();
        $this->postJson('/api/v1/uks/medicines', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }
}
