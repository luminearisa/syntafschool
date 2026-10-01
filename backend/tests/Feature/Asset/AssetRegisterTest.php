<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Application\Services\AssetRegisterService;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetAttributeValue;

class AssetRegisterTest extends AssetTestCase
{
    public function test_pendaftaran_aset_menghasilkan_kode_qr_barcode_dan_label(): void
    {
        $this->actAs($this->admin);

        $asset = $this->makeAsset(['name' => 'Proyektor Epson']);

        $this->assertStringStartsWith('AST-IT-', $asset->asset_code);
        $this->assertNotEmpty($asset->barcode);
        $this->assertNotEmpty($asset->qr_token);
        $this->assertSame('PENDING_TAGGING', $asset->tag_status?->value);
        $this->assertDatabaseHas('asset_identifiers', ['asset_id' => $asset->id, 'type' => 'QR', 'value' => $asset->qr_token]);
        $this->assertDatabaseHas('asset_tags', ['asset_id' => $asset->id]);

        // Perolehan dicatatkan ke Keuangan (atau masuk outbox bila modulnya belum ada).
        $this->assertDatabaseHas('asset_audit_logs', ['asset_id' => $asset->id, 'action' => 'ASSET_CREATED']);
    }

    public function test_barang_habis_pakai_tidak_menjadi_aset(): void
    {
        $this->actAs($this->admin);

        $atk = \App\Modules\Asset\Models\AssetCategory::query()->where('code', 'ATK')->firstOrFail();

        $this->expectException(AssetValidationException::class);

        app(AssetRegisterService::class)->register($this->admin, [
            'name' => 'Kertas HVS',
            'category_id' => $atk->id,
            'acquisition_cost' => '75000',
        ]);
    }

    public function test_barang_murah_diperlakukan_sebagai_beban(): void
    {
        $register = app(AssetRegisterService::class);

        $decision = $register->recognize($this->categoryElektronik, null, '50000');

        $this->assertSame('EXPENSE', $decision->value);
        $this->assertSame('ASSET', $register->recognize($this->categoryElektronik, null, '8500000')->value);
    }

    public function test_label_harus_cocok_saat_diverifikasi(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $lain = $this->makeAsset(['name' => 'Laptop lain']);

        // Label milik aset LAIN: penempelan harus ditolak, bukan diterima diam-diam.
        try {
            app(\App\Modules\Asset\Application\Services\AssetTagService::class)
                ->attach($this->admin, $asset, (string) $lain->tags()->first()->tag_code);
            $this->fail('Label aset lain seharusnya ditolak.');
        } catch (AssetValidationException $exception) {
            $this->assertArrayHasKey('tag_code', $exception->errors());
        }

        $tag = $asset->tags()->first();
        $updated = app(\App\Modules\Asset\Application\Services\AssetTagService::class)
            ->attach($this->admin, $asset, (string) $tag->tag_code);

        $this->assertSame('TAGGED', $updated->tag_status?->value);
        $this->assertNotNull($updated->tagged_at);
    }

    public function test_penempatan_membuka_riwayat_pemegang_dan_tidak_menimpa_yang_lama(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $custodianA = $this->makeCustodian('Pemegang A');
        $custodianB = $this->makeCustodian('Pemegang B');

        $register = app(AssetRegisterService::class);

        $register->place($this->admin, $asset, $this->ruangB->id, $custodianA->id);
        $register->place($this->admin, $asset->refresh(), $this->ruangA->id, $custodianB->id);

        $asset->refresh();

        $this->assertSame($this->ruangA->id, $asset->location_id);

        $terbuka = $asset->custodianHistory()->whereNull('released_at')->first();
        $tertutup = $asset->custodianHistory()->whereNotNull('released_at')->first();

        $this->assertSame($custodianB->id, $terbuka?->custodian_id);
        $this->assertSame($custodianA->id, $tertutup?->custodian_id);

        // Dua baris riwayat: satu tertutup, satu terbuka.
        $this->assertSame(2, $asset->custodianHistory()->count());
        $this->assertSame(1, $asset->custodianHistory()->whereNull('released_at')->count());
        $this->assertSame(1, $asset->custodianHistory()->whereNotNull('released_at')->count());
    }

    public function test_transisi_status_tidak_sah_ditolak(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $this->expectException(AssetDomainException::class);

        // ACTIVE tidak boleh melompat ke RETIRED tanpa melewati DISPOSED.
        app(AssetRegisterService::class)->changeStatus($this->admin, $asset, \App\Modules\Asset\Domain\Enums\AssetStatus::RETIRED, 'coba lompat');
    }

    public function test_perubahan_bersamaan_ditolak_oleh_pengunci_versi(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $version = $asset->version;

        app(AssetRegisterService::class)->update($this->admin, $asset, ['name' => 'Nama baru', 'version' => $version]);

        $this->expectException(AssetDomainException::class);

        // Versi lama dipakai lagi (mis. dua petugas menyimpan bersamaan).
        app(AssetRegisterService::class)->update($this->admin, $asset->refresh(), ['name' => 'Nama lain', 'version' => $version]);
    }

    public function test_data_keuangan_memerlukan_izin_keuangan(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $this->actAs($this->guru);

        $this->expectException(AssetDomainException::class);

        app(AssetRegisterService::class)->financialSummary($this->guru, $asset);
    }

    public function test_kondisi_buruk_mengirim_pemberitahuan_tanpa_mengubah_status(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $updated = app(AssetRegisterService::class)->changeCondition(
            $this->admin, $asset, \App\Modules\Asset\Domain\Enums\AssetCondition::POOR, 'Layar berkedip.',
        );

        $this->assertSame('POOR', $updated->condition?->value);
        $this->assertDatabaseHas('asset_notifications', ['event' => 'ASSET_CONDITION_BAD']);
        $this->assertDatabaseHas('asset_audit_logs', ['asset_id' => $asset->id, 'action' => 'ASSET_CONDITION_CHANGED']);
    }

    public function test_qr_publik_tidak_memuat_informasi_keuangan(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $response = $this->getJson('/api/v1/assets/lookup/'.$asset->qr_token)->assertOk();

        $data = $response->json('data');

        $this->assertSame($asset->asset_code, $data['asset_code']);
        $this->assertArrayNotHasKey('acquisition_cost', $data);
        $this->assertArrayNotHasKey('current_book_value', $data);
        $this->assertArrayNotHasKey('serial_number', $data);
    }
}
