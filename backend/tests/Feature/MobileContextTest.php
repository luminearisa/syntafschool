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
use App\Models\TahfizNotification;
use App\Support\ContextPermissions;
use App\Support\ContextState;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

    protected function tearDown(): void
    {
        ContextState::clear();
        ContextPermissions::flush();
        parent::tearDown();
    }

    /** Guru yang juga wali: permission & identitas mengikuti peran aktif, DB tak berubah. */
    public function test_permissions_and_userable_follow_the_active_role(): void
    {
        $employee = Employee::create(['full_name' => 'Pak Dedi']);
        $guardian = Guardian::create(['full_name' => 'Pak Dedi (Wali)']);
        $user = $this->user('dedi@syntaf.test', $employee);
        $user->syncRoles(['guru']);
        UserIdentity::create(['user_id' => $user->id, 'identity_type' => 'guardian', 'identity_id' => $guardian->id]);

        // Hanya role wali-murid yang memegang izin portal ini.
        $portal = Permission::firstOrCreate(['name' => 'clinic.portal.view', 'guard_name' => 'web']);
        Role::findByName('wali-murid', 'web')->givePermissionTo($portal);
        $teacherOnly = Permission::firstOrCreate(['name' => 'academic.grade', 'guard_name' => 'web']);
        Role::findByName('guru', 'web')->givePermissionTo($teacherOnly);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Tanpa konteks (token web): perilaku lama.
        $this->assertTrue($user->fresh()->hasPermissionTo('academic.grade'));
        $this->assertFalse($user->fresh()->hasPermissionTo('clinic.portal.view'));

        ContextState::set($user->id, 'guardian', $guardian->id);
        $asGuardian = $user->fresh();
        $this->assertTrue($asGuardian->hasPermissionTo('clinic.portal.view'));
        $this->assertFalse($asGuardian->hasPermissionTo('academic.grade'));
        $this->assertInstanceOf(Guardian::class, $asGuardian->userable);
        $this->assertSame(['wali-murid'], $asGuardian->getRoleNames()->all());
        $this->assertTrue($asGuardian->hasRole('wali-murid'));
        $this->assertFalse($asGuardian->hasRole('guru'));

        ContextPermissions::flush();
        ContextState::set($user->id, 'employee', $employee->id);
        $asEmployee = $user->fresh();
        $this->assertTrue($asEmployee->hasPermissionTo('academic.grade'));
        $this->assertFalse($asEmployee->hasPermissionTo('clinic.portal.view'));
        $this->assertInstanceOf(Employee::class, $asEmployee->userable);

        // Penimpaan hanya di memori.
        $this->assertSame(Employee::class, DB::table('users')->where('id', $user->id)->value('userable_type'));
    }

    /** Wali yang ditautkan lewat user_identities dikenali portal Keuangan (resolver tunggal). */
    public function test_finance_portal_uses_the_single_guardian_resolver(): void
    {
        $employee = Employee::create(['full_name' => 'Bu Tia']);
        $guardian = Guardian::create(['full_name' => 'Bu Tia (Wali)']);
        $kid = $this->child('F-1', $guardian);
        $user = $this->user('tia@syntaf.test', $employee);
        UserIdentity::create(['user_id' => $user->id, 'identity_type' => 'guardian', 'identity_id' => $guardian->id]);

        $pending = $this->mobileLogin('tia@syntaf.test')->json('data.token');
        $guardianToken = $this->postJson('/api/v1/auth/switch-context', ['context' => 'guardian'], $this->bearer($pending))->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/finance/portal/children', $this->bearer($guardianToken))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $kid->id);

        // Di mode pegawai, akun yang sama TIDAK dianggap wali.
        $employeeToken = $this->postJson('/api/v1/auth/switch-context', ['context' => 'employee'], $this->bearer($guardianToken))->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/finance/portal/children', $this->bearer($employeeToken))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_revoked_identity_invalidates_the_token(): void
    {
        $employee = Employee::create(['full_name' => 'Pak Eko']);
        $guardian = Guardian::create(['full_name' => 'Pak Eko (Wali)']);
        $user = $this->user('eko@syntaf.test', $employee);
        $link = UserIdentity::create(['user_id' => $user->id, 'identity_type' => 'guardian', 'identity_id' => $guardian->id]);

        $pending = $this->mobileLogin('eko@syntaf.test')->json('data.token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context' => 'guardian'], $this->bearer($pending))->json('data.token');

        $link->delete();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mobile/children', $this->bearer($token))->assertStatus(401);
    }

    public function test_notification_inbox_merges_tahfiz_and_filters_by_role(): void
    {
        $employee = Employee::create(['full_name' => 'Bu Fani']);
        $guardian = Guardian::create(['full_name' => 'Bu Fani (Wali)']);
        $user = $this->user('fani@syntaf.test', $employee);
        UserIdentity::create(['user_id' => $user->id, 'identity_type' => 'guardian', 'identity_id' => $guardian->id]);

        TahfizNotification::create(['user_id' => $user->id, 'audience' => 'parent', 'type' => 'setoran_reviewed', 'title' => 'Setoran dinilai']);
        TahfizNotification::create(['user_id' => $user->id, 'audience' => 'instructor', 'type' => 'target_overdue', 'title' => 'Target tertinggal']);

        $pending = $this->mobileLogin('fani@syntaf.test')->json('data.token');
        $guardianToken = $this->postJson('/api/v1/auth/switch-context', ['context' => 'guardian'], $this->bearer($pending))->json('data.token');

        $this->app['auth']->forgetGuards();
        $res = $this->getJson('/api/v1/mobile/notifications', $this->bearer($guardianToken))->assertOk();
        $this->assertSame(['Setoran dinilai'], collect($res->json('data.items'))->pluck('title')->all());
        $this->assertSame(1, $res->json('data.unread'));

        $employeeToken = $this->postJson('/api/v1/auth/switch-context', ['context' => 'employee'], $this->bearer($guardianToken))->json('data.token');
        $this->app['auth']->forgetGuards();
        $res = $this->getJson('/api/v1/mobile/notifications', $this->bearer($employeeToken))->assertOk();
        $this->assertSame(['Target tertinggal'], collect($res->json('data.items'))->pluck('title')->all());
    }
}
