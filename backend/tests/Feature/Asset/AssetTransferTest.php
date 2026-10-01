<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Application\Services\AssetLoanService;
use App\Modules\Asset\Application\Services\AssetTransferService;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\AssetTransfer;

class AssetTransferTest extends AssetTestCase
{
    protected function makeTransfer(array $data = []): AssetTransfer
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        return app(AssetTransferService::class)->request($this->admin, array_merge([
            'to_location_id' => $this->ruangB->id,
            'type' => 'ROOM_TRANSFER',
            'reason' => 'Dipindahkan ke laboratorium',
        ], $data), [$asset->id]);
    }

    public function test_pemindahan_lengkap_mengubah_lokasi_dan_menutup_riwayat_pemegang(): void
    {
        $this->actAs($this->admin);
        $custodian = $this->makeCustodian('Pemegang Lab');
        $transfer = $this->makeTransfer(['to_custodian_id' => $custodian->id]);
        $service = app(AssetTransferService::class);

        $transfer = $service->approve($this->admin, $transfer);
        $transfer = $service->handover($this->admin, $transfer);

        // Konfirmasi penerimaan oleh pengguna LAIN (bukan yang menyerahkan).
        $transfer = $service->receive($this->guru, $transfer->refresh(), ['recipient_condition' => 'GOOD']);

        $this->assertSame('COMPLETED', $transfer->status?->value);

        $asset = $transfer->items()->first()->asset->refresh();
        $this->assertSame($this->ruangB->id, $asset->location_id);
        $this->assertSame($custodian->user_id, $asset->custodian_id);
        $this->assertSame(1, $asset->custodianHistory()->whereNull('released_at')->count());
        $this->assertDatabaseHas('asset_audit_logs', ['asset_id' => $asset->id, 'action' => 'ASSET_TRANSFERRED']);
    }

    public function test_tujuan_pemindahan_wajib_dan_tidak_boleh_lokasi_yang_sama(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        try {
            app(AssetTransferService::class)->request($this->admin, ['reason' => 'tanpa tujuan'], [$asset->id]);
            $this->fail('Pemindahan tanpa tujuan seharusnya ditolak.');
        } catch (AssetValidationException $exception) {
            $this->assertArrayHasKey('to_location_id', $exception->errors());
        }

        $this->expectException(AssetValidationException::class);

        app(AssetTransferService::class)->request($this->admin, ['to_location_id' => $asset->location_id], [$asset->id]);
    }

    public function test_konfirmasi_penerimaan_tidak_boleh_oleh_pengirim_sendiri(): void
    {
        $this->actAs($this->admin);
        $transfer = $this->makeTransfer();
        $service = app(AssetTransferService::class);

        // Yang menyerahkan adalah guru (bukan pengelola aset), lalu ia mencoba
        // mengonfirmasi penerimaannya sendiri.
        $transfer = $service->handover($this->guru, $service->approve($this->admin, $transfer));

        $this->expectException(AssetDomainException::class);

        $service->receive($this->guru, $transfer->refresh());
    }

    public function test_aset_yang_sedang_dipinjam_tidak_dapat_dipindahkan(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();
        $loanService = app(AssetLoanService::class);

        $loan = $loanService->request($this->admin, [
            'purpose' => 'Dipinjam dulu',
            'start_at' => now()->toDateTimeString(),
            'due_at' => now()->addDays(2)->toDateTimeString(),
        ], [$asset->id]);

        $loanService->activate($this->admin, $loanService->handover($this->admin, $loanService->reserve($this->admin, $loanService->approve($this->admin, $loan))));

        $this->expectException(AssetDomainException::class);

        app(AssetTransferService::class)->request($this->admin, ['to_location_id' => $this->ruangB->id], [$asset->id]);
    }
}
