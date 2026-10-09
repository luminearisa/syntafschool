<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryBibliography;
use App\Models\LibraryItem;
use App\Models\User;
use App\Services\Library\LibraryService;
use Database\Seeders\LibraryMasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 3 — OPAC (katalog publik).
 *
 * Menguji akses publik tanpa login, penyembunyian record non-publik,
 * pencarian & filter, detail berbasis slug, ketersediaan yang dihitung dari
 * eksemplar, dan penyaringan data sensitif.
 */
class LibraryOpacTest extends TestCase
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

    private function library(string $code = 'LIB-OPAC'): Library
    {
        return app(LibraryService::class)->create([
            'code' => $code,
            'name' => 'Perpustakaan Publik',
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

    /**
     * Record publik beserta eksemplarnya.
     */
    private function publishedLibrary(Library $library, string $title = 'Belajar Laravel'): LibraryBibliography
    {
        $this->actingAsRole('admin-sekolah');

        $id = $this->postJson('/api/v1/library/catalog', [
            'library_id' => $library->id,
            'title' => $title,
            'publication_year' => 2026,
            'category_id' => $this->master('categories', 'TEKNOLOGI'),
            'collection_type_id' => $this->master('collection-types', 'BOOK'),
            'creators' => [[
                'creator_id' => $this->master('creators', 'UNKNOWN'),
                'creator_role_id' => $this->master('creator-roles', 'AUTHOR'),
            ]],
            'subjects' => [$this->master('subjects', 'PROGRAMMING')],
            // ISBN berbeda per judul agar tidak dianggap duplikat.
            'identifiers' => [[
                'identifier_type' => 'isbn13',
                'value' => '978-602-'.str_pad((string) (crc32($title) % 1000000), 6, '0', STR_PAD_LEFT).'-1',
            ]],
        ])->json('data.id');

        $this->postJson("/api/v1/library/catalog/{$id}/items/bulk", ['quantity' => 5]);

        // Terbitkan ke katalog publik (PUT bersifat ganti-utuh → judul ikut dikirim).
        $this->putJson("/api/v1/library/catalog/{$id}", [
            'title' => $title,
            'status' => 'active',
        ])->assertOk();

        return LibraryBibliography::query()->find($id);
    }

    /** Akses sebagai publik (tanpa login). */
    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // =================================================================
    // AKSES PUBLIK
    // =================================================================

    public function test_katalog_publik_dapat_diakses_tanpa_login(): void
    {
        $library = $this->library();
        $this->publishedLibrary($library);

        $this->asGuest();

        $this->getJson('/api/v1/library/opac')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_record_draft_tidak_muncul_di_opac(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');
        $this->postJson('/api/v1/library/catalog', [
            'library_id' => $library->id,
            'title' => 'Masih Draf',
        ])->assertCreated();

        $this->asGuest();

        // Record draf tidak boleh tampil sampai diaktifkan (Rule 8).
        $this->getJson('/api/v1/library/opac')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_perpustakaan_yang_mematikan_opac_tidak_tampil(): void
    {
        $library = $this->library();
        $this->publishedLibrary($library);

        $this->actingAsRole('admin-sekolah');
        $this->putJson("/api/v1/library/libraries/{$library->id}/settings", [
            'settings' => ['opac.enabled' => false],
        ])->assertOk();

        $this->asGuest();

        $this->getJson('/api/v1/library/opac')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // =================================================================
    // PENCARIAN & FILTER
    // =================================================================

    public function test_pencarian_judul_kreator_dan_identifier(): void
    {
        $library = $this->library();
        $bibliography = $this->publishedLibrary($library);

        $this->asGuest();

        // Judul dicari sebagai bagian kata (partial match).
        $this->getJson('/api/v1/library/opac?search=Laravel')->assertOk()->assertJsonPath('meta.total', 1);
        // Nama kreator (Unknown) ikut dicari.
        $this->getJson('/api/v1/library/opac?search=Unknown')->assertOk()->assertJsonPath('meta.total', 1);

        // Identifier dicari dalam bentuk ternormalisasi (tanpa tanda hubung).
        $isbn = \App\Models\LibraryBibliographyIdentifier::query()
            ->where('bibliography_id', $bibliography->id)
            ->value('normalized_value');

        $this->assertNotNull($isbn);
        $this->getJson("/api/v1/library/opac?search={$isbn}")->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/library/opac?search=TidakAdaJudulIni')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_filter_kategori_dan_ketersediaan(): void
    {
        $library = $this->library();
        $bibliography = $this->publishedLibrary($library);

        $this->asGuest();

        $categoryId = $this->master('categories', 'TEKNOLOGI');

        $this->getJson("/api/v1/library/opac?filter[category_id]={$categoryId}")
            ->assertOk()->assertJsonPath('meta.total', 1);

        // Semua eksemplar tersedia.
        $this->getJson('/api/v1/library/opac?availability=available')
            ->assertOk()->assertJsonPath('meta.total', 1);

        // Semua eksemplar ditarik → tidak lagi tersedia.
        LibraryItem::query()->where('bibliography_id', $bibliography->id)->update(['status' => 'withdrawn']);

        $this->getJson('/api/v1/library/opac?availability=available')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/library/opac?availability=unavailable')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_hasil_opac_dipaginasi(): void
    {
        $library = $this->library();
        $this->publishedLibrary($library, 'Buku Satu');
        $this->publishedLibrary($library, 'Buku Dua');

        $this->asGuest();

        $this->getJson('/api/v1/library/opac?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    // =================================================================
    // DETAIL & KETERSEDIAAN
    // =================================================================

    public function test_detail_publik_menggunakan_slug(): void
    {
        $library = $this->library();
        $bibliography = $this->publishedLibrary($library);

        $this->asGuest();

        $this->getJson("/api/v1/library/opac/{$bibliography->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $bibliography->slug)
            ->assertJsonPath('data.title', 'Belajar Laravel');

        $this->getJson('/api/v1/library/opac/slug-tidak-ada')->assertNotFound();
    }

    public function test_slug_lama_tetap_dapat_ditelusuri(): void
    {
        $library = $this->library();
        $bibliography = $this->publishedLibrary($library);
        $oldSlug = $bibliography->slug;

        $this->actingAsRole('admin-sekolah');
        $this->putJson("/api/v1/library/catalog/{$bibliography->id}", [
            'title' => 'Belajar Laravel Edisi Kedua',
            'regenerate_slug' => true,
        ])->assertOk();

        $this->asGuest();

        $this->getJson("/api/v1/library/opac/{$oldSlug}")
            ->assertOk()
            ->assertJsonPath('data.slug', 'belajar-laravel-edisi-kedua');
    }

    public function test_ketersediaan_dihitung_dari_eksemplar(): void
    {
        $library = $this->library();
        $bibliography = $this->publishedLibrary($library);

        $items = LibraryItem::query()->where('bibliography_id', $bibliography->id)->orderBy('id')->get();

        // 5 eksemplar: 1 dipinjam, 1 hanya baca di tempat, 1 ditarik.
        $items[0]->update(['status' => 'on_loan']);
        $items[1]->update(['status' => 'reference_only']);
        $items[2]->update(['status' => 'withdrawn']);

        $this->asGuest();

        $response = $this->getJson("/api/v1/library/opac/{$bibliography->slug}/availability")->assertOk();

        $this->assertSame(5, $response->json('data.total_copies'));
        // Withdrawn tidak dihitung tersedia (Rule 9).
        $this->assertSame(2, $response->json('data.available'));
        $this->assertSame(3, $response->json('data.unavailable'));
        $this->assertSame(1, $response->json('data.on_loan'));
        $this->assertSame(1, $response->json('data.reference_only'));
        // Masih ada eksemplar tersedia → record tersedia.
        $this->assertTrue($response->json('data.is_available'));
    }

    // =================================================================
    // PRIVASI
    // =================================================================

    public function test_opac_tidak_mengekspos_data_internal(): void
    {
        $library = $this->library();
        $bibliography = $this->publishedLibrary($library);

        $this->asGuest();

        $row = $this->getJson('/api/v1/library/opac')->assertOk()->json('data.0');

        // Identifier publik = slug, bukan id basis data.
        $this->assertArrayNotHasKey('id', $row);
        $this->assertArrayNotHasKey('library_id', $row);
        $this->assertArrayNotHasKey('notes', $row);
        $this->assertArrayNotHasKey('acquisition_price', $row);
        $this->assertArrayNotHasKey('status', $row);
        $this->assertArrayHasKey('slug', $row);

        $detail = $this->getJson("/api/v1/library/opac/{$bibliography->slug}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('id', $detail);
        $this->assertArrayNotHasKey('notes', $detail);
        // Tidak ada data peminjam/transaksi pada fase ini.
        $this->assertArrayNotHasKey('items', $detail);
    }

    public function test_pratinjau_opac_membutuhkan_izin_untuk_record_non_publik(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');
        $id = $this->postJson('/api/v1/library/catalog', [
            'library_id' => $library->id,
            'title' => 'Belum Terbit',
        ])->json('data.id');

        // Pustakawan dapat melihat katalog tetapi tidak boleh mempratinjau draf.
        $this->actingAsRole('pustakawan');
        $this->getJson("/api/v1/library/catalog/{$id}/opac-preview")->assertForbidden();

        // Library manager memiliki `library.opac.manage`.
        $this->actingAsRole('library-manager');
        $this->getJson("/api/v1/library/catalog/{$id}/opac-preview")->assertOk();
    }
}