<?php

namespace Tests\Feature;

use App\Models\BookCategory;
use App\Models\Library;
use App\Models\LibraryClassificationSystem;
use App\Models\LibrarySubject;
use App\Models\User;
use App\Services\Library\LibraryService;
use App\Services\Library\MasterData\LibraryMasterRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Master data Library — Step 2.
 *
 * Satu test untuk satu aturan: CRUD, hierarki (termasuk siklus & scope),
 * delapan belas master dari registry, scope global vs per-perpustakaan,
 * proteksi hapus berdasarkan pemakaian, pencarian/pagination, otorisasi per
 * peran, dan jejak audit MASTER_*.
 */
class LibraryMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
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

    private function library(string $code = 'LIB-MASTER'): Library
    {
        return app(LibraryService::class)->create([
            'code' => $code,
            'name' => 'Perpustakaan Uji',
            'timezone' => 'Asia/Jakarta',
            'status' => Library::STATUS_DRAFT,
        ]);
    }

    // =================================================================
    // CRUD
    // =================================================================

    public function test_master_dapat_dibuat_dibaca_dan_diubah(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/library/collection-types', [
            'code' => 'book',
            'name' => 'Buku',
            'is_digital' => false,
            'is_physical' => true,
        ])->assertCreated();

        $id = $created->json('data.id');

        // Kode diseragamkan menjadi huruf besar.
        $this->assertSame('BOOK', $created->json('data.code'));
        $this->assertSame('active', $created->json('data.status'));

        $this->getJson("/api/v1/library/collection-types/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Buku');

        $this->putJson("/api/v1/library/collection-types/{$id}", [
            'code' => 'BOOK',
            'name' => 'Buku Cetak',
        ])->assertOk()->assertJsonPath('data.name', 'Buku Cetak');
    }

    public function test_kode_unik_dalam_scope_yang_sama(): void
    {
        $this->actingAsRole('admin-sekolah');

        $libraryA = $this->library('LIB-A');
        $libraryB = $this->library('LIB-B');

        $payload = ['code' => 'BOOK', 'name' => 'Buku'];

        $this->postJson('/api/v1/library/collection-types', $payload)->assertCreated();

        // Global yang sama → ditolak.
        $this->postJson('/api/v1/library/collection-types', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('code');

        // Scope perpustakaan berbeda → boleh.
        $this->postJson('/api/v1/library/collection-types', $payload + ['library_id' => $libraryA->id])
            ->assertCreated();

        $this->postJson('/api/v1/library/collection-types', $payload + ['library_id' => $libraryB->id])
            ->assertCreated();
    }

    public function test_master_tidak_dikenal_menghasilkan_404(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->getJson('/api/v1/library/master-tidak-ada')->assertNotFound();
    }

    // =================================================================
    // HIERARKI
    // =================================================================

    public function test_master_hierarkis_dapat_bertingkat(): void
    {
        $this->actingAsRole('admin-sekolah');

        $parent = $this->postJson('/api/v1/library/subjects', [
            'code' => 'ISLAM',
            'name' => 'Islam',
        ])->assertCreated();

        $parentId = $parent->json('data.id');

        $child = $this->postJson('/api/v1/library/subjects', [
            'code' => 'FIQIH',
            'name' => 'Fiqih',
            'parent_id' => $parentId,
        ])->assertCreated();

        $this->assertSame($parentId, $child->json('data.parent_id'));

        $this->getJson("/api/v1/library/subjects/{$parentId}")
            ->assertOk()
            ->assertJsonPath('data.children_count', 1);
    }

    public function test_induk_tidak_boleh_dirinya_sendiri(): void
    {
        $this->actingAsRole('admin-sekolah');

        $id = $this->postJson('/api/v1/library/subjects', ['code' => 'ISLAM', 'name' => 'Islam'])
            ->json('data.id');

        $this->putJson("/api/v1/library/subjects/{$id}", [
            'code' => 'ISLAM',
            'name' => 'Islam',
            'parent_id' => $id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_induk_tidak_boleh_keturunannya_sendiri(): void
    {
        $this->actingAsRole('admin-sekolah');

        $parentId = $this->postJson('/api/v1/library/subjects', ['code' => 'ISLAM', 'name' => 'Islam'])
            ->json('data.id');

        $childId = $this->postJson('/api/v1/library/subjects', [
            'code' => 'FIQIH',
            'name' => 'Fiqih',
            'parent_id' => $parentId,
        ])->json('data.id');

        // Memindahkan induk ke bawah anaknya akan membentuk siklus.
        $this->putJson("/api/v1/library/subjects/{$parentId}", [
            'code' => 'ISLAM',
            'name' => 'Islam',
            'parent_id' => $childId,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_induk_harus_se_scope_dan_aktif(): void
    {
        $this->actingAsRole('admin-sekolah');

        $library = $this->library();

        $scopedParent = $this->postJson('/api/v1/library/subjects', [
            'code' => 'LOKAL',
            'name' => 'Kurikulum Lokal',
            'library_id' => $library->id,
        ])->json('data.id');

        // Induk milik perpustakaan lain scope → ditolak untuk anak global.
        $this->postJson('/api/v1/library/subjects', [
            'code' => 'ANAK',
            'name' => 'Anak',
            'parent_id' => $scopedParent,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        // Induk nonaktif → ditolak.
        $activeParent = $this->postJson('/api/v1/library/subjects', [
            'code' => 'GLOBAL',
            'name' => 'Global',
        ])->json('data.id');

        $this->putJson("/api/v1/library/subjects/{$activeParent}", [
            'code' => 'GLOBAL',
            'name' => 'Global',
            'status' => 'inactive',
        ])->assertOk();

        $this->postJson('/api/v1/library/subjects', [
            'code' => 'ANAK-2',
            'name' => 'Anak 2',
            'parent_id' => $activeParent,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_induk_yang_masih_punya_anak_tidak_dapat_dihapus(): void
    {
        $this->actingAsRole('admin-sekolah');

        $parentId = $this->postJson('/api/v1/library/subjects', ['code' => 'ISLAM', 'name' => 'Islam'])
            ->json('data.id');

        $this->postJson('/api/v1/library/subjects', [
            'code' => 'FIQIH',
            'name' => 'Fiqih',
            'parent_id' => $parentId,
        ]);

        $this->deleteJson("/api/v1/library/subjects/{$parentId}")->assertStatus(422);
    }

    // =================================================================
    // SCOPE
    // =================================================================

    public function test_scope_global_dan_per_perpustakaan_terpisah(): void
    {
        $this->actingAsRole('admin-sekolah');

        $library = $this->library();

        $this->postJson('/api/v1/library/subjects', ['code' => 'GLOBAL', 'name' => 'Global']);
        $this->postJson('/api/v1/library/subjects', [
            'code' => 'LOKAL',
            'name' => 'Lokal',
            'library_id' => $library->id,
        ])->assertCreated();

        // Filter scope: hanya milik perpustakaan tersebut (tanpa global).
        $scoped = $this->getJson("/api/v1/library/subjects?filter[library_id]={$library->id}")
            ->assertOk();
        $this->assertSame(1, count($scoped->json('data')));
        $this->assertSame('LOKAL', $scoped->json('data.0.code'));

        // Master global tersedia tanpa filter.
        $all = $this->getJson('/api/v1/library/subjects')->assertOk();
        $this->assertSame(2, count($all->json('data')));
    }

    public function test_master_global_tidak_menyimpan_library_id(): void
    {
        $this->actingAsRole('admin-sekolah');

        $library = $this->library();

        // negara adalah master global — library_id yang dikirim diabaikan.
        $this->postJson('/api/v1/library/countries', [
            'code' => 'ID',
            'name' => 'Indonesia',
            'library_id' => $library->id,
        ])->assertCreated()->assertJsonPath('data.library_id', null);
    }

    // =================================================================
    // PROTEKSI HAPUS
    // =================================================================

    public function test_master_yang_belum_dipakai_dapat_dihapus(): void
    {
        $this->actingAsRole('admin-sekolah');

        $id = $this->postJson('/api/v1/library/tags', ['code' => 'AI', 'name' => 'AI'])
            ->json('data.id');

        $this->deleteJson("/api/v1/library/tags/{$id}")->assertOk();

        $this->assertSoftDeleted('library_tags', ['id' => $id]);
    }

    public function test_master_yang_sudah_dipakai_tidak_dapat_dihapus(): void
    {
        $this->actingAsRole('admin-sekolah');

        $systemId = $this->postJson('/api/v1/library/classification-systems', [
            'code' => 'DDC',
            'name' => 'Dewey Decimal Classification',
        ])->json('data.id');

        $classificationId = $this->postJson('/api/v1/library/classifications', [
            'code' => '200',
            'name' => 'Agama',
            'system_id' => $systemId,
        ])->assertCreated()->json('data.id');

        // Sistem sudah dipakai satu klasifikasi → tidak boleh dihapus.
        $this->deleteJson("/api/v1/library/classification-systems/{$systemId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('master');

        // Pemakaian terlihat pada kolom usage_count.
        $this->getJson("/api/v1/library/classification-systems/{$systemId}")
            ->assertOk()
            ->assertJsonPath('data.usage_count', 1);

        // Klasifikasi itu sendiri belum dipakai → boleh dihapus.
        $this->deleteJson("/api/v1/library/classifications/{$classificationId}")->assertOk();
    }

    // =================================================================
    // STATUS & BULK
    // =================================================================

    public function test_status_dapat_diubah_dan_dinonaktifkan_massal(): void
    {
        $this->actingAsRole('admin-sekolah');

        $id = $this->postJson('/api/v1/library/genres', ['code' => 'NOVEL', 'name' => 'Novel'])
            ->json('data.id');

        $this->putJson("/api/v1/library/genres/{$id}", [
            'code' => 'NOVEL',
            'name' => 'Novel',
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');

        $id2 = $this->postJson('/api/v1/library/genres', ['code' => 'PUISI', 'name' => 'Puisi'])
            ->json('data.id');

        $this->postJson('/api/v1/library/genres/bulk-status', [
            'ids' => [$id, $id2],
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->getJson("/api/v1/library/genres?filter[status]=inactive")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_kategori_existing_memakai_kolom_is_active(): void
    {
        $this->actingAsRole('admin-sekolah');

        $id = $this->postJson('/api/v1/library/categories', ['code' => 'AGAMA', 'name' => 'Agama'])
            ->json('data.id');

        $this->putJson("/api/v1/library/categories/{$id}", [
            'code' => 'AGAMA',
            'name' => 'Agama',
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');

        // Tabel existing menyimpan status pada kolom boolean.
        $this->assertFalse(BookCategory::query()->find($id)->is_active);
    }

    // =================================================================
    // PENCARIAN & PAGINATION
    // =================================================================

    public function test_pencarian_dan_pagination(): void
    {
        $this->actingAsRole('admin-sekolah');

        foreach (['ALPHA', 'BETA', 'GAMMA', 'DELTA'] as $code) {
            $this->postJson('/api/v1/library/tags', ['code' => $code, 'name' => "Tag {$code}"]);
        }

        $this->getJson('/api/v1/library/tags?search=GAMMA')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'GAMMA');

        $this->getJson('/api/v1/library/tags?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    // =================================================================
    // OTORISASI
    // =================================================================

    public function test_peran_tanpa_permission_master_ditolak(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/library/collection-types')->assertForbidden();
    }

    public function test_pustakawan_dapat_menambah_tetapi_tidak_menghapus(): void
    {
        $this->actingAsRole('pustakawan');

        $id = $this->postJson('/api/v1/library/tags', ['code' => 'TAHFIZ', 'name' => 'Tahfiz'])
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/library/tags/{$id}")->assertForbidden();
    }

    public function test_library_manager_dapat_mengelola_tetapi_tidak_menghapus(): void
    {
        $this->actingAsRole('library-manager');

        $id = $this->postJson('/api/v1/library/tags', ['code' => 'AI', 'name' => 'AI'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/library/tags/{$id}", ['code' => 'AI', 'name' => 'AI & ML'])->assertOk();
        $this->deleteJson("/api/v1/library/tags/{$id}")->assertForbidden();
    }

    public function test_admin_dapat_menghapus(): void
    {
        $this->actingAsRole('admin-sekolah');

        $id = $this->postJson('/api/v1/library/tags', ['code' => 'AI', 'name' => 'AI'])
            ->json('data.id');

        $this->deleteJson("/api/v1/library/tags/{$id}")->assertOk();
    }

    // =================================================================
    // AUDIT & META
    // =================================================================

    public function test_perubahan_master_tercatat_di_jejak_audit(): void
    {
        $this->actingAsRole('admin-sekolah');

        $id = $this->postJson('/api/v1/library/tags', ['code' => 'AI', 'name' => 'AI'])
            ->json('data.id');

        $this->putJson("/api/v1/library/tags/{$id}", ['code' => 'AI', 'name' => 'Kecerdasan Buatan'])
            ->assertOk();

        $this->putJson("/api/v1/library/tags/{$id}", [
            'code' => 'AI',
            'name' => 'Kecerdasan Buatan',
            'status' => 'inactive',
        ])->assertOk();

        $events = Activity::query()->where('log_name', 'library')->pluck('event')->all();

        $this->assertContains('MASTER_CREATED', $events);
        $this->assertContains('MASTER_UPDATED', $events);
        $this->assertContains('MASTER_DEACTIVATED', $events);
    }

    public function test_master_meta_menyediakan_seluruh_master(): void
    {
        $this->actingAsRole('admin-sekolah');

        $response = $this->getJson('/api/v1/library/master-meta')->assertOk();

        $masters = collect($response->json('data.masters'));

        $this->assertSame(count(LibraryMasterRegistry::keys()), $masters->count());

        $collectionTypes = $masters->firstWhere('key', 'collection-types');
        $this->assertTrue($collectionTypes['scoped']);
        $this->assertContains('is_digital', array_column($collectionTypes['fields'], 'name'));

        $subjects = $masters->firstWhere('key', 'subjects');
        $this->assertTrue($subjects['hierarchical']);
    }

    public function test_registry_menjaga_konsistensi_tabel_dan_model(): void
    {
        // Setiap master harus punya model Eloquent dengan tabel yang benar —
        // mencegah registry dan skema menyimpang.
        foreach (LibraryMasterRegistry::keys() as $key) {
            $master = LibraryMasterRegistry::find($key);

            $this->assertTrue(class_exists($master['model']), "Model {$master['model']} tidak ada");
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasTable((new $master['model'])->getTable()),
                "Tabel untuk {$key} tidak ada"
            );
        }
    }
}