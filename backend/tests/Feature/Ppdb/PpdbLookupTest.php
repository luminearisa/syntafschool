<?php

namespace Tests\Feature\Ppdb;

use App\Models\AcademicYear;
use App\Models\EducationLevel;
use App\Models\Gender;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Religion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /ppdb/lookups` — opsi dropdown halaman PPDB.
 *
 * Endpoint ini ada justru karena panitia penerimaan TIDAK memegang
 * `master-data.view` dan `academic.view`: tanpa whitelist tetap ini pilihan
 * jenis kelamin, agama, jenjang, program sekolah, tahun ajaran, dan metode
 * bayar pada halaman mereka kosong tanpa pesan error. Yang dijaga di sini
 * adalah bentuk jawabannya — kolom identitas saja, tidak ada baris di luar
 * daftar referensi yang dipakai formulir PPDB.
 */
class PpdbLookupTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'genders', 'religions', 'education_levels', 'programs', 'academic_years', 'payment_methods',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_panitia_mendapat_opsi_meski_tidak_punya_hak_master_data(): void
    {
        $panitia = $this->staff('panitia-ppdb');

        $this->assertFalse($panitia->can('master-data.view'), 'Panitia tidak boleh memegang hak baca master data.');
        $this->assertFalse($panitia->can('academic.view'), 'Hak Kurikulum pun tidak ikut dipakai untuk mengisi dropdown.');

        $this->seedReferences();

        $response = $this->as($panitia)->getJson('/api/v1/ppdb/lookups')->assertOk();

        $this->assertSame(self::KEYS, array_keys((array) $response->json('data')));
        $response
            ->assertJsonPath('data.genders.1', ['id' => 2, 'code' => 'P', 'name' => 'Perempuan'])
            ->assertJsonPath('data.religions.0', ['id' => 1, 'name' => 'Islam'])
            ->assertJsonPath('data.payment_methods.0.code', 'TRANSFER')
            ->assertJsonPath('data.programs.0.code', 'IPA');

        $this->assertSame(['SMP Negeri'], array_column($response->json('data.education_levels'), 'name'));
        $this->assertSame(['2027/2028', '2026/2027'], array_column($response->json('data.academic_years'), 'name'));
    }

    public function test_hanya_kolom_identitas_referensi_yang_dikembalikan(): void
    {
        $this->seedReferences();

        $data = $this->as($this->staff('panitia-ppdb'))
            ->getJson('/api/v1/ppdb/lookups')
            ->assertOk()
            ->json('data');

        foreach ($data as $group => $rows) {
            $this->assertNotEmpty($rows, "Kelompok {$group} tidak boleh kosong.");

            foreach ($rows as $row) {
                $allowed = [
                    'genders' => ['id', 'code', 'name'],
                    'payment_methods' => ['id', 'code', 'name'],
                    // `jenjang` dipakai untuk menata label opsi; `education_level_id`
                    // dan `description` dipakai formulir penawaran program sebagai
                    // nilai awal dari master yang dipilih.
                    'education_levels' => ['id', 'name', 'jenjang'],
                    'programs' => ['id', 'code', 'name', 'education_level_id', 'description'],
                ][$group] ?? ['id', 'name'];

                $this->assertEqualsCanonicalizing(
                    $allowed,
                    array_keys($row),
                    "Referensi {$group} membocorkan kolom di luar ".implode('/', $allowed).'.'
                );
            }
        }
    }

    public function test_referensi_nonaktif_tersaring_kecuali_tahun_ajaran(): void
    {
        $this->seedReferences();

        $data = $this->as($this->staff('panitia-ppdb'))
            ->getJson('/api/v1/ppdb/lookups')
            ->assertOk()
            ->json('data');

        // Jenjang & metode bayar yang dinonaktifkan hilang dari pilihan.
        $this->assertNotContains('SD Negeri', array_column($data['education_levels'], 'name'));
        $this->assertNotContains('TUNAI', array_column($data['payment_methods'], 'code'));

        // Tahun ajaran & program sekolah sengaja TIDAK disaring `is_active`:
        // kolom tahun ajaran berasumsi default `false` (saringan menghapus
        // isinya), dan penawaran PPDB yang lama boleh menunjuk program yang
        // belakangan dinonaktifkan.
        $this->assertContains('2027/2028', array_column($data['academic_years'], 'name'));
        $this->assertSame(['IPA', 'IPS'], array_column($data['programs'], 'code'));
    }

    public function test_akun_orang_tua_tidak_membaca_opsi_panitia(): void
    {
        $this->seedReferences();

        $parent = User::factory()->create(['is_active' => true]);

        // Tanpa permission apa pun — akun portal bukan pengguna admin PPDB.
        $this->as($parent)->getJson('/api/v1/ppdb/lookups')->assertForbidden();

        // Termasuk ketika ia juga memegang peran siswa: hak PPDB tidak ikut.
        $this->as($this->staff('siswa'))->getJson('/api/v1/ppdb/lookups')->assertForbidden();
    }

    // =================================================================
    // FIXTURE
    // =================================================================

    private function seedReferences(): void
    {
        Gender::query()->create(['code' => 'L', 'name' => 'Laki-laki']);
        Gender::query()->create(['code' => 'P', 'name' => 'Perempuan']);

        Religion::query()->create(['name' => 'Islam']);
        Religion::query()->create(['name' => 'Kristen']);

        $smp = EducationLevel::query()->create(['name' => 'SMP Negeri', 'level_number' => 3, 'jenjang' => 'SMP', 'is_active' => true]);
        EducationLevel::query()->create(['name' => 'SD Negeri', 'level_number' => 1, 'jenjang' => 'SD', 'is_active' => false]);

        Program::query()->create(['code' => 'IPA', 'name' => 'MIPA', 'education_level_id' => $smp->id, 'is_active' => true]);
        Program::query()->create(['code' => 'IPS', 'name' => 'Sosial', 'education_level_id' => $smp->id, 'is_active' => false]);

        AcademicYear::query()->create(['name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        AcademicYear::query()->create(['name' => '2027/2028', 'start_date' => '2027-07-01', 'end_date' => '2028-06-30', 'is_active' => false]);

        PaymentMethod::query()->create(['name' => 'Transfer Bank', 'code' => 'TRANSFER', 'is_active' => true]);
        PaymentMethod::query()->create(['name' => 'Tunai di Kasir', 'code' => 'TUNAI', 'is_active' => false]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['email' => "{$role}@ppdb-opsi.test", 'is_active' => true]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }
}
