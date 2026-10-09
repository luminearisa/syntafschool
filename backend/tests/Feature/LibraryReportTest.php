<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Models\LibraryMember;
use App\Models\Student;
use App\Models\User;
use App\Services\Library\LibraryService;
use Database\Seeders\LibraryMasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5 — laporan, ekspor/impor, notifikasi, dan otomatisasi harian.
 */
class LibraryReportTest extends TestCase
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
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function library(string $code = 'LIB-REP'): Library
    {
        $library = app(LibraryService::class)->create([
            'code' => $code,
            'name' => 'Perpustakaan Laporan',
            'timezone' => 'Asia/Jakarta',
            'status' => Library::STATUS_DRAFT,
        ]);

        app(\App\Services\Library\LibrarySettingsService::class)->update($library, [
            'fine.enabled' => true,
            'fine.per_day' => 500,
        ]);

        return $library->refresh();
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

    private function setupLoan(Library $library, string $nis = 'S-100'): array
    {
        $this->actingAsRole('admin-sekolah');

        $bibliographyId = $this->postJson('/api/v1/library/catalog', [
            'library_id' => $library->id,
            'title' => 'Buku Laporan',
            'collection_type_id' => $this->master('collection-types', 'BOOK'),
        ])->json('data.id');

        $items = $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items/bulk", ['quantity' => 2])
            ->json('data');

        $student = Student::create(['nis' => $nis, 'full_name' => 'Anggota Laporan', 'is_active' => true]);

        $memberId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'student_id' => $student->id,
            'membership_type_id' => $this->master('membership-types', 'STUDENT'),
            'valid_until' => now()->addYear()->toDateString(),
        ])->json('data.id');

        $this->actingAsRole('pustakawan');

        $loanId = $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberId,
            'item_id' => $items[0]['id'],
        ])->json('data.id');

        return [$bibliographyId, $items, $memberId, $loanId];
    }

    // =================================================================
    // LAPORAN (§3)
    // =================================================================

    public function test_laporan_koleksi_dan_sirkulasi(): void
    {
        $library = $this->library();
        $this->setupLoan($library);

        $this->actingAsRole('admin-sekolah');

        $this->getJson("/api/v1/library/reports/collection?library_id={$library->id}")
            ->assertOk()
            ->assertJsonPath('data.totals.titles', 1)
            ->assertJsonPath('data.totals.copies', 2)
            ->assertJsonPath('data.totals.copies_available', 1)
            ->assertJsonStructure(['data' => ['by_category', 'by_collection_type', 'by_location', 'by_year', 'by_status']]);

        $this->getJson("/api/v1/library/reports/circulation?library_id={$library->id}")
            ->assertOk()
            ->assertJsonPath('data.borrowed', 1)
            ->assertJsonPath('data.active', 1)
            ->assertJsonCount(12, 'data.by_month')
            ->assertJsonPath('data.popular.0.loans', 1);
    }

    public function test_laporan_anggota_denda_dan_inventaris(): void
    {
        $library = $this->library();
        [, , $memberId] = $this->setupLoan($library);

        $this->actingAsRole('admin-sekolah');

        $this->getJson("/api/v1/library/reports/member?library_id={$library->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.active', 1)
            ->assertJsonPath('data.by_linked_type.student', 1);

        // Denda manual untuk mengisi laporan denda.
        $this->postJson('/api/v1/library/fines', [
            'member_id' => $memberId,
            'type' => 'damage',
            'amount' => 7000,
        ])->assertCreated();

        $this->getJson("/api/v1/library/reports/fine?library_id={$library->id}")
            ->assertOk()
            ->assertJsonPath('data.assessed', 1)
            ->assertJsonPath('data.outstanding_amount', 7000)
            ->assertJsonPath('data.by_type.0.type', 'damage');

        $this->getJson("/api/v1/library/reports/inventory?library_id={$library->id}")
            ->assertOk()
            ->assertJsonPath('data.current.available', 1)
            ->assertJsonPath('data.current.on_loan', 1);
    }

    // =================================================================
    // EKSPOR & IMPOR (§4, §5)
    // =================================================================

    public function test_ekspor_csv_katalog(): void
    {
        $library = $this->library();
        $this->setupLoan($library);

        $this->actingAsRole('admin-sekolah');

        $response = $this->get("/api/v1/library/export?type=catalog&library_id={$library->id}")
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();

        $this->assertStringContainsString('control_number', $content);
        $this->assertStringContainsString('Buku Laporan', $content);
    }

    public function test_ekspor_jenis_tidak_dikenal_ditolak(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->getJson('/api/v1/library/export?type=tidak-ada')->assertStatus(500);
    }

    public function test_impor_pratinjau_dan_commit_hanya_baris_valid(): void
    {
        $library = $this->library();
        $this->actingAsRole('admin-sekolah');

        // Baris 1 valid, baris 2 tanpa judul, baris 3 ISBN tidak valid.
        $csv = "title,subtitle,isbn13,publisher,publication_year,language,collection_type\n"
            ."Buku Impor Satu,,9786021111111,Erlangga,2026,Indonesia,Buku\n"
            .",,9786022222222,Erlangga,2026,Indonesia,Buku\n"
            ."Buku Impor Tiga,,123,Erlangga,2026,Indonesia,Buku\n";

        $file = UploadedFile::fake()->createWithContent('impor.csv', $csv);

        $preview = $this->postJson('/api/v1/library/imports/catalog/preview', [
            'library_id' => $library->id,
            'file' => $file,
        ])->assertOk();

        $this->assertSame(3, $preview->json('data.total'));
        $this->assertSame(1, $preview->json('data.valid'));
        $this->assertSame(2, $preview->json('data.invalid'));
        // Error per baris memberi tahu nomor barisnya.
        $this->assertSame(3, $preview->json('data.rows.1.row'));
        $this->assertNotEmpty($preview->json('data.rows.1.errors'));

        // Commit hanya menyimpan baris valid.
        $rows = $preview->json('data.rows');

        $result = $this->postJson('/api/v1/library/imports/catalog/commit', [
            'library_id' => $library->id,
            'rows' => $rows,
        ])->assertOk();

        $this->assertSame(1, $result->json('data.imported'));
        $this->assertSame(2, $result->json('data.skipped'));
        $this->assertSame(1, \App\Models\LibraryBibliography::query()->count());

        // Template tersedia untuk diunduh.
        $this->get("/api/v1/library/imports/catalog/template")->assertOk();
    }

    // =================================================================
    // NOTIFIKASI & OTOMATISASI (§9, §10)
    // =================================================================

    public function test_pengingat_harian_idempoten_dan_endpoint_notifikasi(): void
    {
        $library = $this->library();
        [, , $memberId, $loanId] = $this->setupLoan($library);

        // Beri akun pada anggota agar notifikasi punya penerima.
        $user = User::factory()->create(['email' => 'anggota.notif@schoolerp.test', 'is_active' => true]);
        LibraryMember::query()->whereKey($memberId)->update(['user_id' => $user->id]);

        // Jatuh tempo H+2 (pengingat) → jalankan perawatan harian.
        LibraryLoan::query()->whereKey($loanId)->update(['due_at' => now()->addDays(2)->toDateString()]);

        $this->artisan('library:daily-maintenance')->assertExitCode(0);

        $this->assertSame(1, $user->notifications()->count());

        // Menjalankan ulang tidak menggandakan notifikasi.
        $this->artisan('library:daily-maintenance')->assertExitCode(0);
        $this->assertSame(1, $user->notifications()->count());

        // Setelah lewat tenggat → notifikasi keterlambatan (jenis berbeda).
        LibraryLoan::query()->whereKey($loanId)->update(['due_at' => now()->subDays(3)->toDateString()]);
        $this->artisan('library:daily-maintenance')->assertExitCode(0);

        $this->assertSame(2, $user->notifications()->count());

        // Endpoint notifikasi milik pengguna sendiri.
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/library/notifications')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/library/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 2);

        $notificationId = $user->notifications()->first()->id;

        $this->postJson("/api/v1/library/notifications/{$notificationId}/read")->assertOk();
        $this->getJson('/api/v1/library/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);

        $this->postJson('/api/v1/library/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/library/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_otorisasi_laporan(): void
    {
        $library = $this->library();

        // Guru tidak memiliki akses laporan perpustakaan.
        $this->actingAsRole('guru');
        $this->getJson('/api/v1/library/reports/collection')->assertForbidden();

        // Kepala sekolah dapat melihat laporan.
        $this->actingAsRole('kepala-sekolah');
        $this->getJson("/api/v1/library/reports/collection?library_id={$library->id}")->assertOk();
    }
}