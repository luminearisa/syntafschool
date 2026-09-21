<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryMember;
use App\Models\Student;
use App\Models\User;
use App\Services\Library\LibraryService;
use Database\Seeders\LibraryMasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 3 — Library Member, Membership & Member Card.
 *
 * Menguji penautan entitas yang sudah ada (tanpa duplikasi biodata),
 * aturan satu keanggotaan aktif, nomor anggota, masa berlaku, status,
 * perpanjangan, dan kartu anggota beserta token QR-nya.
 */
class LibraryMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LibraryMasterSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function library(string $code = 'LIB-MBR'): Library
    {
        return app(LibraryService::class)->create([
            'code' => $code,
            'name' => 'Perpustakaan Anggota',
            'timezone' => 'Asia/Jakarta',
            'status' => Library::STATUS_DRAFT,
        ]);
    }

    private function master(string $key, string $code): int
    {
        $master = \App\Services\Library\MasterData\LibraryMasterRegistry::find($key);

        $query = $master['model']::query()->where('code', $code);

        if ($master['scoped']) {
            $query->whereNull('library_id');
        }

        return (int) $query->value('id');
    }

    private function student(string $nis = 'S-001', string $name = 'Ahmad Fauzan'): Student
    {
        return Student::create(['nis' => $nis, 'full_name' => $name, 'is_active' => true]);
    }

    // =================================================================
    // PENDAFTARAN
    // =================================================================

    public function test_siswa_dapat_menjadi_anggota_dengan_identitas_ditarik_otomatis(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $student = $this->student();

        $response = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'student_id' => $student->id,
            'membership_type_id' => $this->master('membership-types', 'STUDENT'),
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $this->assertSame('LIB-MBR-'.date('Y').'-001', $response->json('data.member_number'));
        $this->assertSame('student', $response->json('data.linked_type'));
        // Nama ditarik dari data siswa — tidak diinput ulang (Rule 11).
        $this->assertSame('Ahmad Fauzan', $response->json('data.name'));
        $this->assertSame('active', $response->json('data.status'));
        $this->assertTrue($response->json('data.is_within_validity'));
    }

    public function test_anggota_umum_wajib_punya_nama(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'membership_type_id' => $this->master('membership-types', 'PUBLIC'),
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'name' => 'Pengunjung Umum',
            'phone' => '0812345678',
        ])->assertCreated()->assertJsonPath('data.linked_type', 'public');
    }

    public function test_satu_orang_hanya_boleh_punya_satu_keanggotaan_aktif_per_perpustakaan(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $student = $this->student();

        $payload = ['library_id' => $library->id, 'student_id' => $student->id];

        $this->postJson('/api/v1/library/members', $payload)->assertCreated();

        // Keanggotaan aktif kedua pada perpustakaan yang sama → ditolak (Rule 7).
        $this->postJson('/api/v1/library/members', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('member');

        // Perpustakaan lain tetap boleh.
        $other = $this->library('LIB-MBR-2');
        $this->postJson('/api/v1/library/members', ['library_id' => $other->id, 'student_id' => $student->id])
            ->assertCreated();
    }

    // =================================================================
    // STATUS, VALIDITAS & PERPANJANGAN
    // =================================================================

    public function test_valid_until_di_masa_lalu_menghasilkan_status_expired(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $student = $this->student();

        $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'student_id' => $student->id,
            'valid_until' => now()->subDay()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'expired');
    }

    public function test_perpanjangan_mengaktifkan_kembali_dan_mencatat_audit(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $student = $this->student();

        $memberId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'student_id' => $student->id,
            'valid_until' => now()->subDay()->toDateString(),
        ])->json('data.id');

        $this->postJson("/api/v1/library/members/{$memberId}/renew", [
            'valid_until' => now()->addYear()->toDateString(),
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertContains(
            'MEMBERSHIP_RENEWED',
            Activity::query()->where('log_name', 'library')->pluck('event')->all()
        );

        // Perpanjangan ke masa lalu ditolak.
        $this->postJson("/api/v1/library/members/{$memberId}/renew", [
            'valid_until' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_penangguhan_dan_evaluasi_kedaluwarsa(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $student = $this->student();

        $memberId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'student_id' => $student->id,
            'valid_until' => now()->addMonth()->toDateString(),
        ])->json('data.id');

        $this->postJson("/api/v1/library/members/{$memberId}/status", [
            'status' => 'suspended',
            'reason' => 'Melanggar aturan peminjaman',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertContains(
            'MEMBERSHIP_SUSPENDED',
            Activity::query()->where('log_name', 'library')->pluck('event')->all()
        );

        // Anggota yang lewat masa berlaku ditandai expired oleh evaluasi —
        // datanya tidak dihapus (Rule 10).
        $expiringId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'name' => 'Anggota Kedaluwarsa',
            'valid_until' => now()->addDay()->toDateString(),
        ])->json('data.id');

        LibraryMember::query()->whereKey($expiringId)->update(['valid_until' => now()->subDay()]);

        $this->postJson('/api/v1/library/members/evaluate-statuses', [])
            ->assertOk()->assertJsonPath('data.expired', 1);

        $this->assertSame('expired', LibraryMember::query()->find($expiringId)->status);
        $this->assertNotNull(LibraryMember::query()->find($expiringId));
    }

    public function test_ringkasan_keanggotaan(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $this->postJson('/api/v1/library/members', ['library_id' => $library->id, 'name' => 'A']);
        $this->postJson('/api/v1/library/members', ['library_id' => $library->id, 'name' => 'B']);

        $this->getJson("/api/v1/library/members/summary?library_id={$library->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.active', 2);
    }

    // =================================================================
    // KARTU ANGGOTA
    // =================================================================

    public function test_kartu_diterbitkan_dengan_nomor_dan_token_qr(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $student = $this->student();

        $memberId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'student_id' => $student->id,
            'valid_until' => now()->addYear()->toDateString(),
        ])->json('data.id');

        $response = $this->postJson("/api/v1/library/members/{$memberId}/card/generate")
            ->assertCreated();

        $this->assertSame('CARD-'.date('Y').'-001', $response->json('data.card_number'));
        $this->assertSame(64, strlen((string) $response->json('data.qr_token')));
        $this->assertTrue($response->json('data.is_valid'));
        $this->assertSame('Ahmad Fauzan', $response->json('data.member.display_name'));

        $this->assertContains(
            'CARD_GENERATED',
            Activity::query()->where('log_name', 'library')->pluck('event')->all()
        );
    }

    public function test_kartu_baru_mencabut_kartu_lama_dan_pencetakan_tercatat(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $memberId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'name' => 'Anggota Kartu',
        ])->json('data.id');

        $firstId = $this->postJson("/api/v1/library/members/{$memberId}/card/generate")->json('data.id');
        $secondId = $this->postJson("/api/v1/library/members/{$memberId}/card/generate")->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        // Kartu lama tidak dihapus, hanya dicabut.
        $this->assertSame('revoked', \App\Models\LibraryMemberCard::query()->find($firstId)->status);

        $this->postJson("/api/v1/library/member-cards/{$secondId}/printed")
            ->assertOk()->assertJsonPath('data.printed_count', 1);

        // Kartu aktif yang diambil hanya yang berlaku.
        $this->getJson("/api/v1/library/members/{$memberId}/card")
            ->assertOk()->assertJsonPath('data.id', $secondId);
    }

    public function test_kartu_saya_untuk_pengguna_yang_login(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $user = User::factory()->create(['email' => 'anggota@schoolerp.test', 'is_active' => true]);

        $memberId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'user_id' => $user->id,
        ])->json('data.id');

        $this->postJson("/api/v1/library/members/{$memberId}/card/generate")->assertCreated();

        // Pengguna tersebut membuka halaman "Kartu Saya" tanpa permission modul.
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/library/members/me/card')
            ->assertOk()
            ->assertJsonPath('data.member.id', $memberId);
    }

    public function test_anggota_dengan_kartu_tidak_dapat_dihapus(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $memberId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'name' => 'Anggota Berdokumen',
        ])->json('data.id');

        $this->postJson("/api/v1/library/members/{$memberId}/card/generate")->assertCreated();

        $this->deleteJson("/api/v1/library/members/{$memberId}")->assertStatus(422);
    }
}