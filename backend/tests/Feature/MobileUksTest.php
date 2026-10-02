<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\UKS\Database\Seeders\UksPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Portal UKS mobile: proyeksi terkurasi, kepemilikan anak, persetujuan rujukan hanya oleh wali.
 */
class MobileUksTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-123';

    private Student $mine;

    private Student $other;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UksPermissionSeeder::class);

        $guardian = Guardian::create(['full_name' => 'Wali UKS']);
        $this->mine = Student::create(['nis' => 'U1', 'full_name' => 'Anak Saya']);
        $this->other = Student::create(['nis' => 'U2', 'full_name' => 'Anak Orang']);
        DB::table('student_guardians')->insert(['student_id' => $this->mine->id, 'guardian_id' => $guardian->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        User::factory()->create(['email' => 'waliuks@syntaf.test', 'password' => self::PASSWORD, 'is_active' => true, 'userable_type' => $guardian->getMorphClass(), 'userable_id' => $guardian->id])->syncRoles(['wali-murid']);
        $this->token = $this->postJson('/api/v1/auth/login', ['email' => 'waliuks@syntaf.test', 'password' => self::PASSWORD, 'client' => 'mobile'])->assertOk()->json('data.token');
    }

    private function h(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function visit(Student $s, string $code, bool $referral): int
    {
        $id = DB::table('uks_visits')->insertGetId([
            'code' => $code, 'student_id' => $s->id, 'visit_type' => 'KELUHAN', 'complaint' => 'Sakit kepala', 'severity' => 'SEDANG',
            'arrived_at' => now(), 'status' => 'DITANGANI', 'notes' => 'CATATAN-INTERNAL-RAHASIA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($referral) {
            DB::table('uks_referrals')->insert(['visit_id' => $id, 'facility_name' => 'Puskesmas', 'reason' => 'Demam tinggi', 'status' => 'DIAJUKAN', 'referred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    public function test_overview_is_scoped_to_own_child_and_hides_internal_fields(): void
    {
        $this->visit($this->mine, 'UKS-1', true);
        $this->visit($this->other, 'UKS-2', false);

        $res = $this->getJson("/api/v1/mobile/children/{$this->mine->id}/uks", $this->h())->assertOk();
        $res->assertJsonCount(1, 'data.visits')->assertJsonPath('data.visits.0.needs_consent', true);
        $this->assertStringNotContainsString('CATATAN-INTERNAL-RAHASIA', $res->getContent());

        $this->getJson("/api/v1/mobile/children/{$this->other->id}/uks", $this->h())->assertStatus(404);
        // Endpoint mentah UKS tertutup bagi token mobile wali.
        $this->getJson('/api/v1/uks/visits', $this->h())->assertStatus(403);
    }

    public function test_guardian_consents_once_and_only_for_referred_own_child_visit(): void
    {
        $withReferral = $this->visit($this->mine, 'UKS-3', true);
        $noReferral = $this->visit($this->mine, 'UKS-4', false);
        $otherVisit = $this->visit($this->other, 'UKS-5', true);

        $this->postJson("/api/v1/mobile/uks/visits/{$otherVisit}/consent", ['decision' => 'DISETUJUI'], $this->h())->assertStatus(404);
        $this->postJson("/api/v1/mobile/uks/visits/{$noReferral}/consent", ['decision' => 'DISETUJUI'], $this->h())->assertStatus(422);
        $this->postJson("/api/v1/mobile/uks/visits/{$withReferral}/consent", ['decision' => 'DISETUJUI', 'channel' => 'TELEPON'], $this->h())->assertStatus(201);
        $this->assertSame('APLIKASI', DB::table('uks_consents')->where('visit_id', $withReferral)->value('channel'));
        // Sudah diputuskan → tidak dapat diputuskan ulang.
        $this->postJson("/api/v1/mobile/uks/visits/{$withReferral}/consent", ['decision' => 'DITOLAK'], $this->h())->assertStatus(422);
        $this->assertSame(1, DB::table('uks_consents')->where('visit_id', $withReferral)->count());
    }

    public function test_other_childrens_letter_is_not_downloadable(): void
    {
        $letter = DB::table('uks_letters')->insertGetId([
            'number' => 'SK/1', 'student_id' => $this->other->id, 'letter_type' => 'SAKIT', 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
            'verification_code' => 'ABC123XYZ789', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/mobile/uks/letters/{$letter}/pdf", $this->h())->assertStatus(404);
    }
}
