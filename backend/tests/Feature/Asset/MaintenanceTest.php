<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Application\Services\InspectionService;
use App\Modules\Asset\Application\Services\MaintenanceService;
use App\Modules\Asset\Application\Services\PreventiveMaintenanceService;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Models\MaintenanceWorkOrder;

class MaintenanceTest extends AssetTestCase
{
    public function test_alur_pemeliharaan_lengkap_dengan_biaya_dan_waktu_henti(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $service = app(MaintenanceService::class);

        $request = $service->createRequest($this->admin, [
            'asset_id' => $asset->id,
            'title' => 'AC tidak dingin',
            'description' => 'Suhu ruangan naik',
            'priority' => 'HIGH',
        ]);

        $this->assertSame('NEW', $request->status?->value);
        $this->assertNotNull($request->sla_due_at);

        $request = $service->triage($this->admin, $request, ['category' => 'PENDINGINAN']);
        $this->assertSame('TRIAGED', $request->status?->value);

        $workOrder = $service->assign($this->admin, $request, $this->technician->id);

        $this->assertSame('ASSIGNED', $workOrder->status?->value);
        $this->assertSame('IN_MAINTENANCE', $asset->refresh()->status?->value);

        $workOrder = $service->start($this->technician, $workOrder, 'Freon habis dan filter kotor.');
        $this->assertSame('IN_PROGRESS', $workOrder->status?->value);

        $workOrder = $service->complete($this->technician, $workOrder, [
            'labor_cost' => '150000',
            'parts_cost' => '250000',
            'transport_cost' => '50000',
        ]);

        $this->assertSame('COMPLETED', $workOrder->status?->value);
        $this->assertSame('450000.00', $workOrder->total_cost);
        $this->assertSame('ACTIVE', $asset->refresh()->status?->value);

        // Biaya dicatatkan ke Keuangan (DISPATCHED bila tersedia, PENDING + outbox bila belum).
        $this->assertContains($workOrder->finance_status, ['PENDING', 'DISPATCHED']);

        $workOrder = $service->verify($this->admin, $workOrder);
        $this->assertSame('VERIFIED', $workOrder->status?->value);
        $this->assertSame('CLOSED', $workOrder->request?->refresh()->status?->value);
        $this->assertDatabaseHas('asset_audit_logs', ['action' => 'MAINTENANCE_COMPLETED']);
    }

    public function test_suku_cadang_diambil_dari_persediaan_dan_menahan_pekerjaan_bila_kurang(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $service = app(MaintenanceService::class);

        $request = $service->createRequest($this->admin, ['asset_id' => $asset->id, 'title' => 'Ganti filter']);
        $workOrder = $service->assign($this->admin, $request, $this->technician->id);

        // Stok tersedia.
        \Illuminate\Support\Facades\DB::table('inventory_items')->insert([
            'sku' => 'FLT-01', 'name' => 'Filter AC',
            'quantity' => 5, 'unit_cost' => 25000, 'minimum_quantity' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $service->requestParts($this->admin, $workOrder, [['code' => 'FLT-01', 'quantity' => 2]]);

        $this->assertCount(1, $result['issued']);
        $this->assertSame('50000.00', $workOrder->refresh()->parts_cost);
        $this->assertSame(3.0, (float) \Illuminate\Support\Facades\DB::table('inventory_items')->where('sku', 'FLT-01')->value('quantity'));

        // Stok kurang: pekerjaan DITAHAN, bukan dianggap selesai.
        $hasil = $service->requestParts($this->admin, $workOrder, [['code' => 'FLT-01', 'quantity' => 10]]);

        $this->assertTrue($hasil['shortage']);
        $this->assertSame('ON_HOLD', $workOrder->refresh()->status?->value);
        $this->assertSame('WAITING_PARTS', $workOrder->request?->refresh()->status?->value);
    }

    public function test_inspeksi_dengan_butir_gagal_melahirkan_permintaan_pemeliharaan(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $inspection = app(InspectionService::class)->schedule($this->admin, ['asset_id' => $asset->id, 'inspection_type' => 'SAFETY']);

        $inspection = app(InspectionService::class)->perform($this->admin, $inspection, [
            ['code' => 'CONDITION', 'label' => 'Kondisi', 'result' => 'PASS'],
            ['code' => 'SAFETY', 'label' => 'Keamanan', 'result' => 'FAIL', 'notes' => 'Kabel terkelupas'],
        ], ['notes' => 'Perlu perbaikan kabel']);

        $this->assertSame('FAILED', $inspection->status?->value);
        $this->assertTrue((bool) $inspection->follow_up_required);
        $this->assertSame(50.0, (float) $inspection->score);
        $this->assertDatabaseHas('maintenance_requests', ['asset_id' => $asset->id, 'source' => 'INSPECTION']);
        $this->assertNotNull($asset->refresh()->last_inspection_at);
    }

    public function test_jadwal_preventif_idempoten(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $service = app(PreventiveMaintenanceService::class);

        $schedule = $service->createSchedule($this->admin, [
            'asset_id' => $asset->id,
            'title' => 'Servis AC berkala',
            'frequency' => 'DAYS_90',
            'next_due_at' => now()->subDay(),
            'checklist' => [['label' => 'Bersihkan filter'], ['label' => 'Periksa freon']],
        ]);

        $pertama = $service->generateDue($this->admin);
        $this->assertCount(1, $pertama['generated']);

        // Dijalankan ulang: TIDAK menggandakan work order.
        $kedua = $service->generateDue($this->admin);
        $this->assertCount(0, $kedua['generated']);

        $workOrder = MaintenanceWorkOrder::query()->where('id', $schedule->refresh()->last_work_order_id)->firstOrFail();
        $this->assertSame('PREVENTIVE', $workOrder->type?->value);
        $this->assertSame(2, $workOrder->tasks()->count());
        $this->assertTrue($schedule->next_due_at->isFuture());
    }

    public function test_work_order_terlambat_dinaikkan_prioritasnya(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $service = app(MaintenanceService::class);

        $request = $service->createRequest($this->admin, ['asset_id' => $asset->id, 'title' => 'Lampu mati', 'priority' => 'LOW']);
        $workOrder = $service->assign($this->admin, $request, $this->technician->id);
        $workOrder->forceFill(['due_at' => now()->subDay()])->save();

        $escalated = $service->escalateOverdue();

        $this->assertContains($workOrder->work_order_number, $escalated);
        $this->assertSame('NORMAL', $workOrder->refresh()->priority?->value);
        $this->assertDatabaseHas('maintenance_logs', ['work_order_id' => $workOrder->id, 'log_type' => 'ESCALATED']);
    }

    public function test_work_order_tidak_dapat_melompati_verifikasi(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $service = app(MaintenanceService::class);

        $request = $service->createRequest($this->admin, ['asset_id' => $asset->id, 'title' => 'Keyboard rusak']);
        $workOrder = $service->assign($this->admin, $request, $this->technician->id);

        $this->expectException(AssetDomainException::class);

        // Belum selesai, tidak boleh langsung diverifikasi.
        $service->verify($this->admin, $workOrder);
    }
}
