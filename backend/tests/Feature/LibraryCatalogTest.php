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
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 3 — Bibliographic Catalog.
 *
 * Menguji pemisahan record vs eksemplar, relasi kreator/subjek/identifier,
 * control number yang immutable, deteksi duplikat, slug publik, proteksi
 * hapus, dan otorisasi.
 */
class LibraryCatalogTest extends TestCase
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

    private function library(string $code = 'LIB-CAT'): Library
    {
        return app(LibraryService::class)->create([
            'code' => $code,
            'name' => 'Perpustakaan Katalog',
            'timezone' => 'Asia/Jakarta',
            'status' => Library::STATUS_DRAFT,
        ]);
    }

    /** ID master global berdasarkan kode. */
    private function master(string $key, string $code): int
    {
        $master = \App\Services\Library\MasterData\LibraryMasterRegistry::find($key);

        $query = $master['model']::query()->where('code', $code);

        // Filter scope hanya untuk master yang memang punya kolom library_id;
        // master global tidak memilikinya.
        if ($master['scoped']) {
            $query->whereNull('library_id');
        }

        return (int) $query->value('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Library $library, array $overrides = []): array
    {
        return array_merge([
            'library_id' => $library->id,
            'title' => 'Belajar Laravel',
            'subtitle' => 'Panduan Membangun Aplikasi Modern',
            'collection_type_id' => $this->master('collection-types', 'BOOK'),
            'material_type_id' => $this->master('material-types', 'PRINTED'),
            'language_id' => $this->master('languages', 'ID'),
            'category_id' => $this->master('categories', 'TEKNOLOGI'),
            'publisher_id' => $this->master('publishers', 'ERL'),
            'publication_year' => 2026,
            'publication_place' => 'Jakarta',
            'classification_id' => $this->master('classifications', '200'),
            'creators' => [[
                'creator_id' => $this->master('creators', 'UNKNOWN'),
                'creator_role_id' => $this->master('creator-roles', 'AUTHOR'),
                'is_primary' => true,
            ]],
            'subjects' => [$this->master('subjects', 'PROGRAMMING')],
            'genres' => [$this->master('genres', 'EDUCATIONAL')],
            'tags' => [$this->master('tags', 'LARAVEL')],
            'identifiers' => [['identifier_type' => 'isbn13', 'value' => '978-602-000-001-1', 'is_primary' => true]],
        ], $overrides);
    }

    // =================================================================
    // CRUD & RELASI
    // =================================================================

    public function test_record_katalog_dapat_dibuat_dengan_control_number_dan_slug(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $response = $this->postJson('/api/v1/library/catalog', $this->payload($library))->assertCreated();

        // Nomor dari konfigurasi penomoran (BIB-{YYYY}-{SEQ}), bukan hardcode.
        $this->assertSame('BIB-'.date('Y').'-001', $response->json('data.control_number'));
        $this->assertSame('belajar-laravel', $response->json('data.slug'));
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame(0, $response->json('data.items_count'));
    }

    public function test_relasi_kreator_subjek_genre_tag_dan_identifier_tersimpan(): void
    {
        $this->actingAsRole('admin-sekolah');

        $response = $this->postJson('/api/v1/library/catalog', $this->payload($this->library()))->assertCreated();

        $this->assertCount(1, $response->json('data.creators'));
        $this->assertSame('Unknown', $response->json('data.creators.0.name'));
        $this->assertSame('Penulis', $response->json('data.creators.0.role'));
        $this->assertCount(1, $response->json('data.subjects'));
        $this->assertCount(1, $response->json('data.genres'));
        $this->assertCount(1, $response->json('data.tags'));
        $this->assertSame('978-602-000-001-1', $response->json('data.identifiers.0.value'));
        // Normalisasi identifier memudahkan pencarian.
        $this->assertSame('9786020000011', $response->json('data.identifiers.0.normalized_value'));
    }

    public function test_call_number_disusun_otomatis_dari_pola(): void
    {
        $this->actingAsRole('admin-sekolah');

        $response = $this->postJson('/api/v1/library/catalog', $this->payload($this->library()))->assertCreated();

        // Pola bawaan: {CLASS} {AUTHOR} {YEAR} → 200 UNK 2026.
        $this->assertSame('200 UNK 2026', $response->json('data.call_number'));
    }

    public function test_control_number_tidak_berubah_setelah_dibuat(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $id = $this->postJson('/api/v1/library/catalog', $this->payload($library))->json('data.id');
        $original = LibraryBibliography::query()->find($id)->control_number;

        $this->putJson("/api/v1/library/catalog/{$id}", [
            'title' => 'Belajar Laravel Edisi 2',
            'control_number' => 'BIB-1999-999999',
        ])->assertOk();

        $this->assertSame($original, LibraryBibliography::query()->find($id)->control_number);
    }

    // =================================================================
    // DUPLIKAT & SLUG
    // =================================================================

    public function test_identifier_baku_yang_sudah_dipakai_ditolak(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $this->postJson('/api/v1/library/catalog', $this->payload($library))->assertCreated();

        // ISBN sama pada perpustakaan yang sama → duplikasi nyata, ditolak.
        $this->postJson('/api/v1/library/catalog', $this->payload($library, [
            'title' => 'Judul Berbeda',
            'identifiers' => [['identifier_type' => 'isbn13', 'value' => '9786020000011']],
        ]))->assertStatus(422)->assertJsonValidationErrors('identifiers.0.value');
    }

    public function test_pemeriksaan_duplikat_memberi_peringatan(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $this->postJson('/api/v1/library/catalog', $this->payload($library))->assertCreated();

        $this->postJson('/api/v1/library/catalog/duplicate-check', [
            'title' => 'Belajar Laravel',
        ])->assertOk()
            ->assertJsonPath('data.has_candidates', true)
            ->assertJsonPath('data.message', 'Kemungkinan record serupa ditemukan.');
    }

    public function test_slug_dapat_diregenerasi_dan_slug_lama_tersimpan(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $id = $this->postJson('/api/v1/library/catalog', $this->payload($library))->json('data.id');

        $response = $this->putJson("/api/v1/library/catalog/{$id}", [
            'title' => 'Panduan Laravel Modern',
            'regenerate_slug' => true,
        ])->assertOk();

        $this->assertSame('panduan-laravel-modern', $response->json('data.slug'));
        $this->assertContains('belajar-laravel', $response->json('data.previous_slugs'));
    }

    // =================================================================
    // PROTEKSI HAPUS & STATUS
    // =================================================================

    public function test_record_dengan_eksemplar_tidak_dapat_dihapus(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $id = $this->postJson('/api/v1/library/catalog', $this->payload($library))->json('data.id');

        LibraryItem::create([
            'library_id' => $library->id,
            'bibliography_id' => $id,
            'item_code' => 'LIB-TEST-000001',
            'status' => 'available',
        ]);

        $this->deleteJson("/api/v1/library/catalog/{$id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('bibliography');

        // Eksemplar dihapus → record boleh dihapus.
        LibraryItem::query()->where('bibliography_id', $id)->delete();
        $this->deleteJson("/api/v1/library/catalog/{$id}")->assertOk();
    }

    public function test_nonaktivasi_massal_tercatat_di_audit(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $id = $this->postJson('/api/v1/library/catalog', $this->payload($library, ['status' => 'active']))
            ->json('data.id');

        $this->postJson('/api/v1/library/catalog/bulk-status', [
            'ids' => [$id],
            'status' => 'inactive',
            'reason' => 'Sudah tidak relevan',
        ])->assertOk();

        $this->assertSame('inactive', LibraryBibliography::query()->find($id)->status);
        $this->assertContains(
            'BIBLIOGRAPHIC_DEACTIVATED',
            Activity::query()->where('log_name', 'library')->pluck('event')->all()
        );
    }

    // =================================================================
    // PENCARIAN & OTORISASI
    // =================================================================

    public function test_katalog_dapat_dicari_dan_dipaginasi(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $this->postJson('/api/v1/library/catalog', $this->payload($library))->assertCreated();
        $this->postJson('/api/v1/library/catalog', $this->payload($library, [
            'title' => 'Sejarah Islam Nusantara',
            'identifiers' => [['identifier_type' => 'isbn13', 'value' => '978-602-000-002-2']],
        ]))->assertCreated();

        $this->getJson('/api/v1/library/catalog?search=Sejarah')
            ->assertOk()->assertJsonPath('meta.total', 1);

        // Pencarian lewat identifier (tanpa tanda hubung).
        $this->getJson('/api/v1/library/catalog?search=9786020000022')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/library/catalog?per_page=1')
            ->assertOk()->assertJsonPath('meta.last_page', 2);
    }

    public function test_otorisasi_katalog_per_peran(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $payload = $this->payload($library);

        $this->actingAsRole('guru');
        $this->getJson('/api/v1/library/catalog')->assertForbidden();

        $this->actingAsRole('pustakawan');
        $id = $this->postJson('/api/v1/library/catalog', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/library/catalog')->assertOk();
        // Pustakawan tidak memiliki hak hapus.
        $this->deleteJson("/api/v1/library/catalog/{$id}")->assertForbidden();
    }
}