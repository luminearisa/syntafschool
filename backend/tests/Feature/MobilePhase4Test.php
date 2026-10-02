<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use App\Modules\CBT\Database\Seeders\CbtPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 4 mobile: pagar CBT untuk siswa, unduh rapor PDF oleh wali, dasbor manajemen berbasis izin.
 */
class MobilePhase4Test extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CbtPermissionSeeder::class);
    }

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'client' => 'mobile'])->assertOk()->json('data.token');
    }

    private function h(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_student_reaches_only_the_student_side_of_cbt(): void
    {
        $student = Student::create(['nis' => 'C1', 'full_name' => 'Siswa CBT']);
        $user = User::factory()->create(['email' => 'cbt@syntaf.test', 'password' => self::PASSWORD, 'is_active' => true, 'userable_type' => $student->getMorphClass(), 'userable_id' => $student->id]);
        $user->syncRoles(['siswa']);
        $token = $this->token($user);

        $this->getJson('/api/v1/cbt/my/sessions', $this->h($token))->assertOk();
        // Sisi pengelola/proktor tertutup bagi siswa.
        $this->getJson('/api/v1/cbt/sessions', $this->h($token))->assertStatus(403);
        $this->postJson('/api/v1/cbt/attempts/1/void', ['reason' => 'x'], $this->h($token))->assertStatus(403);
        $this->getJson('/api/v1/cbt/grading', $this->h($token))->assertStatus(403);
    }

    public function test_guardian_cannot_use_cbt_and_cannot_download_other_childrens_report_card(): void
    {
        $guardian = Guardian::create(['full_name' => 'Wali']);
        $mine = Student::create(['nis' => 'R1', 'full_name' => 'Anak Saya']);
        $other = Student::create(['nis' => 'R2', 'full_name' => 'Anak Orang']);
        DB::table('student_guardians')->insert(['student_id' => $mine->id, 'guardian_id' => $guardian->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['email' => 'wali4@syntaf.test', 'password' => self::PASSWORD, 'is_active' => true, 'userable_type' => $guardian->getMorphClass(), 'userable_id' => $guardian->id]);
        $user->syncRoles(['wali-murid']);
        $token = $this->token($user);

        $this->getJson('/api/v1/cbt/my/sessions', $this->h($token))->assertStatus(403);

        // Anak orang lain → 404 (tidak membocorkan keberadaan rapor), juga lewat id anak sendiri.
        $this->getJson("/api/v1/mobile/children/{$other->id}/report-cards/1/pdf", $this->h($token))->assertStatus(404);
        $this->getJson("/api/v1/mobile/children/{$mine->id}/report-cards/999/pdf", $this->h($token))->assertStatus(404);
    }
}
