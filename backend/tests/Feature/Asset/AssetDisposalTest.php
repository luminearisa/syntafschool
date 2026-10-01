<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Application\Services\AssetDisposalService;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;

class AssetDisposalTest extends AssetTestCase
{
    public function test_penghapusan_lengkap_tidak_pernah_menghapus_baris_aset(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset(['condition' => 'DAMAGED']);
        $service = app(AssetDisposalService::class);

        $request = $service->submit($this->admin, [
            'asset_id' => $asset->id,
            'reason' => 'DAMAGED',
            'method' => 'SCRAP',
            'description' => 'Layar pecah dan biaya perbaikan tidak ekonomis.',
        ]);

        $this->assertSame('SUBMITTED', $request->status?->value);

        $request = $service->inspect($this->admin, $request);
        $this->assertSame('INSPECTED', $request->status?->value);

        $request = $service->value($this->admin, $request, ['estimated_value' => '0']);
        $this->assertSame('VALUED', $request->status?->value);

        $request = $service->approve($this->admin, $request);
        $this->assertSame('APPROVED', $request->status?->value);

        $disposal = $service->execute($this->admin, $request, ['proceeds' => '0']);

        $this->assertSame('EXECUTED', $request->refresh()->status?->value);
        $this->assertSame('DISPOSED', $asset->refresh()->status?->value);
        $this->assertDatabaseHas('asset_disposals', ['id' => $disposal->id, 'method' => 'SCRAP']);
        $this->assertDatabaseHas('asset_audit_logs', ['asset_id' => $asset->id, 'action' => 'ASSET_DISPOSED']);

        $request = $service->retire($this->admin, $request->refresh());
        $asset->refresh();

        $this->assertSame('RETIRED', $request->status?->value);
        $this->assertSame('RETIRED', $asset->status?->value);
        $this->assertTrue((bool) $asset->is_archived);

        // Baris aset MASIH ADA dan masih dapat dicari — inilah bedanya dari menghapus.
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
        $this->assertNotNull(\App\Modules\Asset\Models\Asset::query()->find($asset->id));
    }

    public function test_penghapusan_tidak_dapat_dilaksanakan_tanpa_persetujuan(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $service = app(AssetDisposalService::class);

        $request = $service->submit($this->admin, ['asset_id' => $asset->id, 'reason' => 'OBSOLETE']);
        $request = $service->inspect($this->admin, $request);
        $request = $service->value($this->admin, $request, ['estimated_value' => '100000']);

        $this->expectException(AssetDomainException::class);

        $service->execute($this->admin, $request);
    }

    public function test_aset_yang_sedang_dipinjam_tidak_dapat_diusulkan_penghapusan(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $loanService = app(\App\Modules\Asset\Application\Services\AssetLoanService::class);
        $loan = $loanService->request($this->admin, [
            'purpose' => 'Dipinjam',
            'start_at' => now()->toDateTimeString(),
            'due_at' => now()->addDay()->toDateTimeString(),
        ], [$asset->id]);
        $loanService->activate($this->admin, $loanService->handover($this->admin, $loanService->reserve($this->admin, $loanService->approve($this->admin, $loan))));

        $this->expectException(AssetDomainException::class);

        app(AssetDisposalService::class)->submit($this->admin, ['asset_id' => $asset->id, 'reason' => 'DAMAGED']);
    }

    public function test_usulan_penggantian_adalah_rekomendasi_bukan_pembelian(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset(['condition' => 'POOR']);

        // Aset berumur tua: perolehan 5 tahun lalu dengan umur manfaat 48 bulan.
        $asset->forceFill([
            'purchase_date' => now()->subYears(5)->toDateString(),
            'commission_date' => now()->subYears(5)->toDateString(),
        ])->save();

        $service = app(\App\Modules\Asset\Application\Services\AssetReplacementService::class);
        $result = $service->evaluate($asset);

        $this->assertSame('REPLACE_REVIEW', $result['recommendation']->value);
        $this->assertNotEmpty($result['reasons']);

        $record = $service->recommendFor($asset)['record'];
        $forwarded = $service->forwardToProcurement($this->admin, $record);

        // Diteruskan sebagai PERMINTAAN ke pengadaan (outbox), bukan purchase order.
        $this->assertSame('CONVERTED', $forwarded->status);
        $this->assertNotNull($forwarded->procurement_reference);
        $this->assertDatabaseHas('asset_integration_outbox', ['module' => 'procurement', 'action' => 'request', 'status' => 'PENDING']);
    }
}
