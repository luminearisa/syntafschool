<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Models\UserIdentity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileContextTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $email, ?object $userable = null): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => self::PASSWORD,
            'is_active' => true,
            'userable_type' => $userable?->getMorphClass(),
            'userable_id' => $userable?->id,
        ]);

        return $user;
    }

    private function mobileLogin(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD, 'client' => 'mobile', 'device_name' => 'test',
        ]);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function child(string $nis, Guardian $guardian): Student
    {
        $student = Student::create(['nis' => $nis, 'full_name' => 'Anak '.$nis]);
        DB::table('student_guardians')->insert([
            'student_id' => $student->id, 'guardian_id' => $guardian->id, 'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $student;
    }

    public function test_single_role_account_is_auto_selected_on_mobile_login(): void
    {
        $employee = Employee::create(['full_name' => 'Pak Guru']);
        $this->user('guru@syntaf.test', $employee);

        $res = $this->mobileLogin('guru@syntaf.test')->assertOk();

        $this->assertSame('employee', $res->json('data.active_context'));
        $this->assertCount(1, $res->json('data.contexts'));
    }

    public function test_account_without_identity_cannot_login_to_mobile(): void
    {
        $this->user('kosong@syntaf.test');

        $this->mobileLogin('kosong@syntaf.test')->assertStatus(403);
    }

    public function test_employee_who_is_also_guardian_can_switch_and_is_isolated(): void
    {
        $employee = Employee::create(['full_name' => 'Bu Rina']);
        $guardian = Guardian::create(['full_name' => 'Bu Rina (Wali)']);
        $mine = $this->child('N-1', $guardian);
        $other = $this->child('N-2', Guardian::create(['full_name' => 'Wali Lain']));

        $user = $this->user('rina@syntaf.test', $employee);
        $user->syncRoles(['guru']);
        UserIdentity::create(['user_id' => $user->id, 'identity_type' => 'guardian', 'identity_id' => $guardian->id]);

        // Login: dua peran → token "pending", belum bisa apa-apa.
        $res = $this->mobileLogin('rina@syntaf.test')->assertOk();
        $this->assertNull($res->json('data.active_context'));
        $this->assertCount(2, $res->json('data.contexts'));
        $pending = $res->json('data.token');

        $this->getJson('/api/v1/mobile/employee/home', $this->bearer($pending))->assertStatus(403);
        $this->getJson('/api/v1/employees', $this->bearer($pending))->assertStatus(403);

        // Mode wali.
        $res = $this->postJson('/api/v1/auth/switch-context', ['context' => 'guardian'], $this->bearer($pending))->assertOk();
        $this->assertSame('guardian', $res->json('data.active_context'));
        $guardianToken = $res->json('data.token');

        // Token lama sudah dicabut.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/contexts', $this->bearer($pending))->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mobile/children', $this->bearer($guardianToken))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/mobile/children/{$mine->id}/attendance", $this->bearer($guardianToken))->assertOk();

        // Anak wali lain → 404, bukan 403.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/mobile/children/{$other->id}/scores", $this->bearer($guardianToken))->assertStatus(404);

        // Mode wali tak boleh menyentuh endpoint admin / pegawai walau role `guru` melekat pada akun.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/employees', $this->bearer($guardianToken))->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mobile/employee/home', $this->bearer($guardianToken))->assertStatus(403);

        // Kembali ke mode pegawai.
        $this->app['auth']->forgetGuards();
        $res = $this->postJson('/api/v1/auth/switch-context', ['context' => 'employee'], $this->bearer($guardianToken))->assertOk();
        $employeeToken = $res->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mobile/employee/home', $this->bearer($employeeToken))->assertOk()
            ->assertJsonPath('data.employee.full_name', 'Bu Rina');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mobile/children', $this->bearer($employeeToken))->assertStatus(403);
    }

    public function test_cannot_switch_to_a_role_the_account_does_not_have(): void
    {
        $employee = Employee::create(['full_name' => 'Pak Budi']);
        $user = $this->user('budi@syntaf.test', $employee);
        Sanctum::actingAs($user, ['ctx:employee']);

        $this->postJson('/api/v1/auth/switch-context', ['context' => 'guardian'])->assertStatus(403);
    }

    public function test_student_context_only_sees_self_and_no_finance(): void
    {
        $guardian = Guardian::create(['full_name' => 'Ortu']);
        $self = $this->child('S-1', $guardian);
        $sibling = $this->child('S-2', $guardian);
        $this->user('siswa@syntaf.test', $self);

        $token = $this->mobileLogin('siswa@syntaf.test')->assertOk()->json('data.token');

        $this->getJson('/api/v1/mobile/children', $this->bearer($token))->assertOk()->assertJsonCount(1, 'data');
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/mobile/children/{$sibling->id}/scores", $this->bearer($token))->assertStatus(404);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/mobile/children/{$self->id}/finance", $this->bearer($token))->assertStatus(403);
    }

    public function test_web_login_is_unchanged_and_unrestricted(): void
    {
        $user = $this->user('admin@syntaf.test');
        $user->syncRoles(['super-admin']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@syntaf.test', 'password' => self::PASSWORD])
            ->assertOk()->json('data.token');

        $this->getJson('/api/v1/employees', $this->bearer($token))->assertOk();
    }

    public function test_admin_links_identity_once_and_gets_suggestions(): void
    {
        $admin = $this->user('admin2@syntaf.test');
        $admin->syncRoles(['super-admin']);
        Sanctum::actingAs($admin);

        $employee = Employee::create(['full_name' => 'Bu Sari', 'phone' => '081234567890']);
        $owner = $this->user('sari@syntaf.test', $employee);
        $guardian = Guardian::create(['full_name' => 'Sari (Wali)', 'phone' => '0812-3456-7890']);

        $this->getJson("/api/v1/users/{$owner->id}/identities/suggestions")
            ->assertOk()->assertJsonPath('data.0.identity_type', 'guardian')
            ->assertJsonPath('data.0.identity_id', $guardian->id);

        $this->postJson("/api/v1/users/{$owner->id}/identities", ['identity_type' => 'guardian', 'identity_id' => $guardian->id])
            ->assertCreated();

        // Identitas yang sama tidak boleh dipakai akun kedua.
        $intruder = $this->user('lain@syntaf.test', Employee::create(['full_name' => 'Orang Lain']));
        $this->postJson("/api/v1/users/{$intruder->id}/identities", ['identity_type' => 'guardian', 'identity_id' => $guardian->id])
            ->assertStatus(422);

        $this->getJson("/api/v1/users/{$owner->id}/identities")
            ->assertOk()->assertJsonCount(2, 'data.contexts');
    }
}
