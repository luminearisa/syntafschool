<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role = 'admin-sekolah'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_barang_dapat_dibuat_dengan_saldo_awal_yang_dicatat_ke_mutasi(): void
    {
        $this->actingAsRole();
        $category = ItemCategory::create(['name' => 'Peralatan', 'type' => 'Aset']);
        $unit = Unit::create(['name' => 'Unit', 'symbol' => 'unit']);

        $response = $this->postJson('/api/v1/inventory/items', [
            'sku' => 'INV-001',
            'name' => 'Proyektor Kelas',
            'item_category_id' => $category->id,
            'unit_id' => $unit->id,
            'quantity' => 3,
            'minimum_quantity' => 1,
            'condition' => 'good',
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'INV-001')
            ->assertJsonPath('data.quantity', 3);

        $itemId = (int) $response->json('data.id');
        $this->assertSame(1, InventoryMovement::query()->where('inventory_item_id', $itemId)->count());
        $this->assertSame('opening', InventoryMovement::query()->where('inventory_item_id', $itemId)->value('movement_type'));
    }

    public function test_penyesuaian_stok_menyimpan_mutasi_dan_mencegah_stok_negatif(): void
    {
        $this->actingAsRole();
        $item = InventoryItem::create([
            'sku' => 'INV-002', 'name' => 'Kertas A4', 'quantity' => 5,
            'minimum_quantity' => 2, 'condition' => 'good', 'is_active' => true,
        ]);

        $this->postJson("/api/v1/inventory/items/{$item->id}/adjust", [
            'direction' => 'out', 'quantity' => 2, 'reason' => 'Dipakai administrasi',
        ])->assertOk()->assertJsonPath('data.quantity', 3);

        $this->postJson("/api/v1/inventory/items/{$item->id}/adjust", [
            'direction' => 'out', 'quantity' => 4, 'reason' => 'Pengeluaran melebihi saldo',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(3.0, (float) $item->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::query()->where('inventory_item_id', $item->id)->count());
    }

    public function test_low_stock_filter_dan_detail_menampilkan_riwayat(): void
    {
        $this->actingAsRole();
        $item = InventoryItem::create([
            'sku' => 'INV-003', 'name' => 'Spidol', 'quantity' => 1,
            'minimum_quantity' => 2, 'condition' => 'good', 'is_active' => true,
        ]);

        $this->getJson('/api/v1/inventory/items?low_stock=1')
            ->assertOk()
            ->assertJsonPath('data.0.is_low_stock', true);

        $this->getJson("/api/v1/inventory/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.sku', 'INV-003')
            ->assertJsonPath('data.movements', []);
    }

    public function test_modul_inventaris_dibatasi_permission(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/inventory/items')->assertForbidden();
        $this->postJson('/api/v1/inventory/items', [
            'sku' => 'INV-004', 'name' => 'Meja', 'quantity' => 1,
        ])->assertForbidden();
    }
}
