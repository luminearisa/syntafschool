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
 * Fase 3 — Collection / Eksemplar.
 *
 * Menguji pemisahan item code vs nomor inventaris, pembangkitan barcode,
 * keunikan barcode/RFID, pembuatan massal, status, aksi massal, dan proteksi
 * hapus. Satu record boleh punya banyak eksemplar (Rule 1).
 */
class LibraryCollectionItemTest extends TestCase
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

    private function library(string $code = 'LIB-ITM'): Library
    {
        return app(LibraryService::class)->create([
            'code' => $code,
            'name' => 'Perpustakaan Eksemplar',
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

    private function bibliography(Library $library, string $title = 'Belajar Laravel'): int
    {
        return (int) $this->postJson('/api/v1/library/catalog', [
            'library_id' => $library->id,
            'title' => $title,
            'collection_type_id' => $this->master('collection-types', 'BOOK'),
        ])->json('data.id');
    }

    // =================================================================
    // PEMBUATAN
    // =================================================================

    public function test_eksemplar_dibuat_dengan_kode_inventaris_dan_barcode_otomatis(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $bibliographyId = $this->bibliography($library);

        $response = $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items", [
            'location_id' => $this->locationId($library),
            'condition_id' => $this->master('conditions', 'NEW'),
        ])->assertCreated();

        // Item code & nomor inventaris berasal dari konfigurasi, dan berbeda.
        $this->assertSame('LIB-LIB-ITM-001', $response->json('data.item_code'));
        $this->assertSame('ACQ-'.date('Y').'-001', $response->json('data.accession_number'));
        $this->assertNotSame($response->json('data.item_code'), $response->json('data.accession_number'));

        // Barcode otomatis mengikuti item code (tanpa pemisah).
        $this->assertSame('LIBLIBITM001', $response->json('data.barcode'));
        $this->assertSame('available', $response->json('data.status'));
        $this->assertSame('unassigned', $response->json('data.rfid_status'));

        // Record menandai ketersediaan fisik begitu punya eksemplar.
        $this->assertTrue(LibraryBibliography::query()->find($bibliographyId)->physical_available);
    }

    public function test_pembuatan_massal_menghasilkan_kode_berbeda(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $bibliographyId = $this->bibliography($library);

        $response = $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items/bulk", [
            'quantity' => 5,
            'condition_id' => $this->master('conditions', 'GOOD'),
        ])->assertCreated();

        $this->assertCount(5, $response->json('data'));

        $codes = array_column($response->json('data'), 'item_code');
        $this->assertCount(5, array_unique($codes));

        $this->assertSame(5, LibraryItem::query()->where('bibliography_id', $bibliographyId)->count());
    }

    public function test_barcode_dan_rfid_harus_unik(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $bibliographyId = $this->bibliography($library);

        $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items", [
            'barcode' => 'BC-001',
            'rfid_tag' => 'RFID-001',
        ])->assertCreated();

        // Barcode duplikat.
        $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items", ['barcode' => 'BC-001'])
            ->assertStatus(422)->assertJsonValidationErrors('barcode');

        // RFID duplikat.
        $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items", ['rfid_tag' => 'RFID-001'])
            ->assertStatus(422)->assertJsonValidationErrors('rfid_tag');
    }

    // =================================================================
    // STATUS & AUDIT
    // =================================================================

    public function test_perubahan_status_tercatat_di_audit(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $bibliographyId = $this->bibliography($library);

        $itemId = $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items", [])->json('data.id');

        $this->postJson("/api/v1/library/items/{$itemId}/status", [
            'status' => 'withdrawn',
            'reason' => 'Rusak berat',
        ])->assertOk()->assertJsonPath('data.status', 'withdrawn');

        // Item withdrawn tidak dihitung tersedia (Rule 9).
        $this->assertFalse(LibraryItem::query()->find($itemId)->isAvailable());

        $this->assertContains(
            'ITEM_STATUS_CHANGED',
            Activity::query()->where('log_name', 'library')->pluck('event')->all()
        );
    }

    public function test_perubahan_barcode_tercatat_di_audit(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $bibliographyId = $this->bibliography($library);

        $itemId = $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items", [])->json('data.id');

        $this->putJson("/api/v1/library/items/{$itemId}", ['barcode' => 'BC-BARU-1'])->assertOk();

        $this->assertContains(
            'ITEM_BARCODE_CHANGED',
            Activity::query()->where('log_name', 'library')->pluck('event')->all()
        );
    }

    // =================================================================
    // AKSI MASSAL
    // =================================================================

    public function test_aksi_massal_lokasi_kondisi_dan_status(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $bibliographyId = $this->bibliography($library);

        $ids = array_column(
            $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items/bulk", ['quantity' => 3])
                ->json('data'),
            'id'
        );

        $locationId = $this->locationId($library);

        $this->postJson('/api/v1/library/items/bulk-assign', [
            'ids' => $ids,
            'location_id' => $locationId,
            'status' => 'processing',
        ])->assertOk()->assertJsonPath('data.updated', 3);

        $this->assertSame(
            3,
            LibraryItem::query()->whereIn('id', $ids)->where('location_id', $locationId)
                ->where('status', 'processing')->count()
        );
    }

    public function test_barcode_massal_dibangkitkan_untuk_yang_kosong(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $bibliographyId = $this->bibliography($library);

        // Matikan barcode otomatis agar eksemplar dibuat tanpa barcode.
        $this->putJson("/api/v1/library/libraries/{$library->id}/settings", [
            'settings' => ['catalog.barcode_auto' => false],
        ])->assertOk();

        $itemId = $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items", [])
            ->json('data.id');

        $this->assertNull(LibraryItem::query()->find($itemId)->barcode);

        $this->postJson('/api/v1/library/items/bulk-barcode', ['ids' => [$itemId]])
            ->assertOk()->assertJsonPath('data.generated', 1);

        $this->assertNotNull(LibraryItem::query()->find($itemId)->barcode);
    }

    // =================================================================
    // PROTEKSI & FILTER
    // =================================================================

    public function test_eksemplar_dalam_sirkulasi_tidak_dapat_dihapus(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();
        $bibliographyId = $this->bibliography($library);

        $itemId = $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items", [])->json('data.id');

        LibraryItem::query()->whereKey($itemId)->update(['status' => 'on_loan']);

        $this->deleteJson("/api/v1/library/items/{$itemId}")->assertStatus(422);

        LibraryItem::query()->whereKey($itemId)->update(['status' => 'available']);
        $this->deleteJson("/api/v1/library/items/{$itemId}")->assertOk();
    }

    public function test_daftar_eksemplar_dapat_disaring_per_record(): void
    {
        $this->actingAsRole('admin-sekolah');
        $library = $this->library();

        $first = $this->bibliography($library, 'Buku Pertama');
        $second = $this->bibliography($library, 'Buku Kedua');

        $this->postJson("/api/v1/library/catalog/{$first}/items/bulk", ['quantity' => 2]);
        $this->postJson("/api/v1/library/catalog/{$second}/items/bulk", ['quantity' => 1]);

        $this->getJson("/api/v1/library/catalog/{$first}/items")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/library/items?filter[bibliography_id]={$first}")
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    private function locationId(Library $library): int
    {
        return (int) \App\Models\LibraryLocation::create([
            'library_id' => $library->id,
            'code' => 'L1',
            'name' => 'Lantai 1',
            'type' => 'floor',
        ])->id;
    }
}