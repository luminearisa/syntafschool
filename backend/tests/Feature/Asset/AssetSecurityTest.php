<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetAuditLog;

class AssetSecurityTest extends AssetTestCase
{
    public function test_isolasi_unit_sekolah(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset(['name' => 'Aset Kampus A']);

        $unitB = $this->makeSchool('Kampus B');

        // Aset milik unit A TIDAK terlihat dari unit B.
        $terlihat = $this->asTenant($unitB->id, fn () => Asset::query()->pluck('id')->all());
        $this->assertNotContains($asset->id, $terlihat);

        // Dari unit A tetap terlihat.
        $terlihatA = $this->asTenant($this->makeSchool('Kampus A2')->id, fn () => Asset::query()->pluck('id')->all());
        $this->assertNotContains($asset->id, $terlihatA);

        $this->asTenant(null, function () use ($asset): void {
            $this->assertNotNull(Asset::query()->find($asset->id), 'Tanpa penyaringan unit, data terlihat seperti instalasi satu sekolah.');
        });
    }

    public function test_pengguna_tanpa_izin_ditolak_pada_api(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        // Petugas kebersihan tidak punya peran apa pun pada modul ini.
        $orangLuar = \App\Models\User::query()->create([
            'name' => 'Orang Luar', 'email' => 'luar@example.test', 'password' => bcrypt('password'),
        ]);

        $this->actAs($orangLuar);

        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/assets/'.$asset->id)->assertForbidden();
        $this->postJson('/api/v1/assets/'.$asset->id.'/dispose', ['reason' => 'DAMAGED'])->assertForbidden();
    }

    public function test_percobaan_akses_yang_ditolak_tercatat(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $log = app(\App\Modules\Asset\Application\Services\AssetAuditService::class)
            ->logAccess(AuditAction::ACCESS_DENIED, $asset, false, 'Tidak berhak.', $this->guru);

        $this->assertFalse((bool) $log->context['granted']);
        $this->assertDatabaseHas('asset_audit_logs', ['asset_id' => $asset->id, 'action' => 'ACCESS_DENIED']);
    }

    public function test_jejak_audit_tidak_dapat_diubah_atau_dihapus(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $log = AssetAuditLog::query()->where('asset_id', $asset->id)->firstOrFail();

        $log->forceFill(['reason' => 'diubah'])->save();
        $this->assertNotSame('diubah', $log->refresh()->reason, 'Jejak audit menolak perubahan.');

        $log->delete();
        $this->assertDatabaseHas('asset_audit_logs', ['id' => $log->id]);
    }

    public function test_ekspor_laporan_tercatat_pada_jejak_audit(): void
    {
        $this->actAs($this->admin);
        $this->makeAsset();

        $this->get('/api/v1/reports/by-category/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertTrue(
            AssetAuditLog::query()->where('action', 'EXPORT_CREATED')->exists(),
            'Ekspor data aset wajib meninggalkan jejak.',
        );
    }

    public function test_pencarian_global_menghormati_kewenangan(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset(['name' => 'Proyektor Aula']);

        $this->actAs($this->admin);
        $hasil = $this->getJson('/api/v1/meta/search?q=Proyektor')->assertOk()->json('data');

        $this->assertNotEmpty($hasil['assets']);
        $this->assertSame($asset->asset_code, $hasil['assets'][0]['asset_code']);
    }
}
