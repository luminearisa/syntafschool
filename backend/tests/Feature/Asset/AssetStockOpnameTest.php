<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Application\Services\StockOpnameService;
use App\Modules\Asset\Domain\Enums\VarianceDecision;
use App\Modules\Asset\Models\AssetStockOpname;

class AssetStockOpnameTest extends AssetTestCase
{
    protected function makeOpname(): AssetStockOpname
    {
        $this->actAs($this->admin);

        // Tiga aset di Ruang 101, satu di lab.
        $this->makeAsset(['name' => 'Laptop 1']);
        $this->makeAsset(['name' => 'Laptop 2']);
        $this->makeAsset(['name' => 'Laptop 3']);
        $this->makeAsset(['name' => 'Mikroskop', 'location_id' => $this->ruangB->id]);

        return app(StockOpnameService::class)->create($this->admin, ['location_id' => $this->ruangA->id], ['title' => 'Opname Ruang 101']);
    }

    public function test_sesi_menyusun_daftar_harapan_sesuai_lingkup(): void
    {
        $opname = $this->makeOpname();

        $this->assertSame(3, $opname->expected_count);
        $this->assertSame('PLANNED', $opname->status?->value);
        $this->assertDatabaseHas('asset_audit_logs', ['action' => 'STOCK_OPNAME_CREATED']);
    }

    public function test_pindai_mencatat_hasil_dan_selisih_tanpa_mengubah_aset(): void
    {
        $opname = $this->makeOpname();
        $service = app(StockOpnameService::class);
        $opname = $service->start($this->admin, $opname);

        $assets = \App\Modules\Asset\Models\Asset::query()->where('location_id', $this->ruangA->id)->get();

        // Dua ditemukan, satu hilang, satu barang tak dikenal.
        $service->scan($this->admin, $opname, (string) $assets[0]->asset_code);
        $service->scan($this->admin, $opname, (string) $assets[1]->asset_code);
        $service->scan($this->admin, $opname, 'KODE-TIDAK-DIKENAL');

        $hasil = $service->summary($opname->refresh());

        $this->assertSame(2, $hasil['found']);
        $this->assertSame(1, $hasil['unidentified'], 'Barang tak dikenal dihitung terpisah.');
        $this->assertSame(1, $hasil['pending_decisions'], 'Selisih barang tak dikenal menunggu keputusan.');

        // Setelah penghitungan DITUTUP, aset yang tidak pernah dipindai menjadi
        // selisih tersendiri — inilah selisih terpenting dalam sebuah opname.
        $service->moveToVerification($this->admin, $opname->refresh());

        $hasilTutup = $service->summary($opname->refresh());
        $this->assertSame(2, $hasilTutup['pending_decisions']);
        $this->assertSame(1, $hasilTutup['missing']);

        // Aset yang diharapkan tetapi belum dipindai tetap MISSING — dan statusnya
        // TIDAK berubah sebelum selisihnya diputuskan.
        $this->assertDatabaseHas('asset_stock_opname_items', ['opname_id' => $opname->id, 'scan_result' => 'MISSING']);
        $this->assertSame('ACTIVE', $assets[2]->refresh()->status?->value);
    }

    public function test_selisih_lokasi_dan_kondisi_terdeteksi(): void
    {
        $opname = $this->makeOpname();
        $service = app(StockOpnameService::class);
        $opname = $service->start($this->admin, $opname);

        $asset = \App\Modules\Asset\Models\Asset::query()->where('location_id', $this->ruangA->id)->first();

        // Ditemukan di ruang lain dan dalam kondisi rusak.
        $item = $service->scan($this->admin, $opname, (string) $asset->asset_code, [
            'location_id' => $this->ruangB->id,
            'condition' => 'DAMAGED',
        ]);

        $this->assertSame('DAMAGED', $item->scan_result?->value);
        $this->assertTrue((bool) $item->is_variance);
        $this->assertDatabaseHas('asset_stock_opname_variances', ['opname_id' => $opname->id, 'variance_type' => 'DAMAGED']);
    }

    public function test_selisih_hanya_diterapkan_setelah_disetujui(): void
    {
        $opname = $this->makeOpname();
        $service = app(StockOpnameService::class);
        $opname = $service->start($this->admin, $opname);

        $asset = \App\Modules\Asset\Models\Asset::query()->where('location_id', $this->ruangA->id)->first();

        $service->scan($this->admin, $opname, (string) $asset->asset_code, ['location_id' => $this->ruangB->id]);
        $service->moveToVerification($this->admin, $opname);

        $variance = $opname->variances()->firstOrFail();

        // Diterima sebagai selisih sah yang perlu ditindaklanjuti.
        $service->decideVariance($this->admin, $opname, $variance, VarianceDecision::ACCEPTED);

        $service->complete($this->admin, $opname->refresh());

        $this->assertSame($this->ruangB->id, $asset->refresh()->location_id, 'Penyesuaian diterapkan setelah disetujui.');
        $this->assertDatabaseHas('asset_stock_opname_variances', ['id' => $variance->id, 'decision' => 'ADJUSTED']);
        $this->assertDatabaseHas('asset_audit_logs', ['action' => 'VARIANCE_ADJUSTED']);
    }

    public function test_barang_tidak_ditemukan_tidak_otomatis_menjadi_hilang(): void
    {
        $opname = $this->makeOpname();
        $service = app(StockOpnameService::class);
        $opname = $service->start($this->admin, $opname);

        $asset = \App\Modules\Asset\Models\Asset::query()->where('location_id', $this->ruangA->id)->first();

        $service->scan($this->admin, $opname, (string) $asset->asset_code, ['location_id' => $this->ruangB->id]);
        $service->moveToVerification($this->admin, $opname);

        $variance = $opname->variances()->firstOrFail();
        $service->decideVariance($this->admin, $opname, $variance, VarianceDecision::ACCEPTED);
        $service->complete($this->admin, $opname->refresh());

        $this->assertNotSame('LOST', $asset->refresh()->status?->value);
    }
}
