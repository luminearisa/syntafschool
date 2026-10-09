<?php

namespace Tests\Feature;

use App\Models\FinancePurchaseOrder;
use App\Models\FinancePurchaseOrderItem;
use App\Models\FinancePurchaseRequest;
use App\Models\FinanceVendor;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengadaan (§37, §38) lewat HTTP: purchase request → approval → purchase order
 * → penerimaan barang.
 *
 * Yang diverifikasi: baris & total dihitung; pengaju tidak boleh menyetujui
 * sendiri (§3); PO hanya bisa dari PR yang disetujui; penerimaan menaikkan
 * received_quantity dan status (partially_received → received); penerimaan
 * melebihi kuantitas PO ditolak; PO yang sudah terima barang tidak bisa dibatalkan;
 * guard permission (pencatat vs penyetuju).
 */
class FinancePurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
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

    private function vendor(string $code = 'V-PO'): FinanceVendor
    {
        return FinanceVendor::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Vendor '.$code, 'vendor_type' => FinanceVendor::TYPE_SUPPLIER, 'status' => FinanceVendor::STATUS_ACTIVE, 'is_active' => true],
        );
    }

    /** Buat PR (submit) sebagai pengadaan, kembalikan id. */
    private function createSubmittedPR(): int
    {
        $this->actingAsRole('pengadaan');

        return (int) $this->postJson('/api/v1/finance/purchase-requests', [
            'description' => 'Pengadaan alat tulis',
            'needed_date' => '2026-10-01',
            'submit' => true,
            'items' => [
                ['item_name' => 'Kertas A4', 'quantity' => 10, 'estimated_price' => 50000],
                ['item_name' => 'Pulpen', 'quantity' => 100, 'estimated_price' => 5000],
            ],
        ])->assertCreated()->json('data.id');
    }

    // =================================================================
    // Purchase Request (§37)
    // =================================================================

    public function test_pr_dibuat_dengan_baris_dan_total(): void
    {
        $id = $this->createSubmittedPR();

        $pr = FinancePurchaseRequest::with('items')->find($id);
        $this->assertSame('submitted', $pr->status);
        $this->assertCount(2, $pr->items);
        $this->assertEquals(1000000.0, (float) $pr->items->sum('total')); // 10*50000 + 100*5000
    }

    public function test_pr_disetujui_oleh_manager(): void
    {
        $id = $this->createSubmittedPR();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/purchase-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_pengaju_tidak_boleh_menyetujui_pr_sendiri(): void
    {
        $this->actingAsRole('finance-admin');
        $id = (int) $this->postJson('/api/v1/finance/purchase-requests', [
            'description' => 'Uji self-approve',
            'submit' => true,
            'items' => [['item_name' => 'Barang', 'quantity' => 1, 'estimated_price' => 10000]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/purchase-requests/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('approved_by');
    }

    public function test_pr_ditolak_wajib_alasan(): void
    {
        $id = $this->createSubmittedPR();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/purchase-requests/{$id}/reject", [])
            ->assertStatus(422)->assertJsonValidationErrors('reject_reason');

        $this->postJson("/api/v1/finance/purchase-requests/{$id}/reject", ['reject_reason' => 'Belum perlu'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    // =================================================================
    // Purchase Order (§38)
    // =================================================================

    public function test_po_dibuat_dari_pr_yang_disetujui(): void
    {
        $prId = $this->createSubmittedPR();
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/purchase-requests/{$prId}/approve")->assertOk();

        $this->actingAsRole('pengadaan');
        $resp = $this->postJson('/api/v1/finance/purchase-orders', [
            'vendor_id' => $this->vendor()->id,
            'purchase_request_id' => $prId,
            'po_date' => '2026-09-15',
            'tax' => 100000,
            'submit' => true,
            'items' => [
                ['item_name' => 'Kertas A4', 'quantity' => 10, 'price' => 50000],
                ['item_name' => 'Pulpen', 'quantity' => 100, 'price' => 5000],
            ],
        ])->assertCreated();

        $poId = (int) $resp->json('data.id');
        $po = FinancePurchaseOrder::find($poId);
        $this->assertEquals(1000000.0, (float) $po->subtotal);
        $this->assertEquals(1100000.0, (float) $po->total);
        $this->assertSame(FinancePurchaseOrder::STATUS_SUBMITTED, $po->status);
    }

    public function test_po_tidak_bisa_dari_pr_belum_disetujui(): void
    {
        $prId = $this->createSubmittedPR();

        $this->actingAsRole('pengadaan');
        $this->postJson('/api/v1/finance/purchase-orders', [
            'vendor_id' => $this->vendor()->id,
            'purchase_request_id' => $prId,
            'items' => [['item_name' => 'Kertas', 'quantity' => 1, 'price' => 50000]],
        ])->assertStatus(422)->assertJsonValidationErrors('purchase_request_id');
    }

    public function test_penerimaan_barang_parsial_lalu_penuh(): void
    {
        $poId = $this->createApprovedPO();
        $items = FinancePurchaseOrderItem::query()->where('purchase_order_id', $poId)->orderBy('id')->get();

        $this->actingAsRole('pengadaan');

        // Terima sebagian salah satu baris.
        $this->postJson("/api/v1/finance/purchase-orders/{$poId}/receive", [
            'items' => [['purchase_order_item_id' => $items[0]->id, 'quantity' => 4]],
        ])->assertOk()->assertJsonPath('data.status', FinancePurchaseOrder::STATUS_PARTIALLY_RECEIVED);

        // Terima sisanya sampai penuh.
        $this->postJson("/api/v1/finance/purchase-orders/{$poId}/receive", [
            'items' => [
                ['purchase_order_item_id' => $items[0]->id, 'quantity' => 6],
                ['purchase_order_item_id' => $items[1]->id, 'quantity' => 100],
            ],
        ])->assertOk()->assertJsonPath('data.status', FinancePurchaseOrder::STATUS_RECEIVED);

        $po = FinancePurchaseOrder::find($poId);
        $this->assertNotNull($po->received_at);
        $this->assertTrue($po->items()->get()->every(fn (FinancePurchaseOrderItem $i): bool => $i->isFullyReceived()));
    }

    public function test_penerimaan_melebihi_kuantitas_po_ditolak(): void
    {
        $poId = $this->createApprovedPO();
        $item = FinancePurchaseOrderItem::query()->where('purchase_order_id', $poId)->first();

        $this->actingAsRole('pengadaan');
        $this->postJson("/api/v1/finance/purchase-orders/{$poId}/receive", [
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 9999]],
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_po_sudah_terima_barang_tidak_bisa_dibatalkan(): void
    {
        $poId = $this->createApprovedPO();
        $item = FinancePurchaseOrderItem::query()->where('purchase_order_id', $poId)->first();

        $this->actingAsRole('pengadaan');
        $this->postJson("/api/v1/finance/purchase-orders/{$poId}/receive", [
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 1]],
        ])->assertOk();

        $this->postJson("/api/v1/finance/purchase-orders/{$poId}/cancel")->assertStatus(422);
    }

    /** Buat PO langsung (tanpa PR), submit + approve, kembalikan id. */
    private function createApprovedPO(): int
    {
        $this->actingAsRole('pengadaan');
        $poId = (int) $this->postJson('/api/v1/finance/purchase-orders', [
            'vendor_id' => $this->vendor()->id,
            'po_date' => '2026-09-15',
            'submit' => true,
            'items' => [
                ['item_name' => 'Kertas A4', 'quantity' => 10, 'price' => 50000],
                ['item_name' => 'Pulpen', 'quantity' => 100, 'price' => 5000],
            ],
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/purchase-orders/{$poId}/approve")->assertOk();

        return $poId;
    }

    // =================================================================
    // Guard permission (§3)
    // =================================================================

    public function test_pengadaan_mencatat_tapi_tidak_menyetujui(): void
    {
        $id = $this->createSubmittedPR();

        $this->actingAsRole('pengadaan');
        $this->postJson("/api/v1/finance/purchase-requests/{$id}/approve")->assertStatus(403);
    }

    public function test_manager_menyetujui_tapi_tidak_mencatat_po(): void
    {
        $this->actingAsRole('finance-manager');
        $this->postJson('/api/v1/finance/purchase-orders', [
            'vendor_id' => $this->vendor()->id,
            'items' => [['item_name' => 'X', 'quantity' => 1, 'price' => 1000]],
        ])->assertStatus(403);
    }

    public function test_auditor_baca_saja(): void
    {
        $id = $this->createSubmittedPR();

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/purchase-requests')->assertOk();
        $this->getJson("/api/v1/finance/purchase-requests/{$id}")->assertOk();
        $this->postJson("/api/v1/finance/purchase-requests/{$id}/approve")->assertStatus(403);
    }
}
