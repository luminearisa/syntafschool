<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Application\Services\AssetLoanService;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\AssetLoan;

class AssetLoanTest extends AssetTestCase
{
    protected function makeLoan(array $overrides = []): AssetLoan
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        return app(AssetLoanService::class)->request($this->admin, array_merge([
            'borrower_id' => $this->guru->id,
            'borrower_type' => 'TEACHER',
            'purpose' => 'Presentasi kelas',
            'start_at' => now()->addDay()->toDateTimeString(),
            'due_at' => now()->addDays(3)->toDateTimeString(),
        ], $overrides), [$asset->id]);
    }

    public function test_alur_peminjaman_lengkap_sampai_pengembalian(): void
    {
        $this->actAs($this->admin);
        $loan = $this->makeLoan();
        $service = app(AssetLoanService::class);

        $loan = $service->approve($this->admin, $loan);
        $loan = $service->reserve($this->admin, $loan);
        $loan = $service->handover($this->admin, $loan);
        $loan = $service->activate($this->admin, $loan);

        $this->assertSame('ACTIVE', $loan->status?->value);

        $asset = $loan->items()->first()->asset->refresh();
        $this->assertSame('ON_LOAN', $asset->status?->value);
        $this->assertSame($loan->id, $asset->active_loan_id);

        $returned = $service->return($this->admin, $loan->refresh(), ['condition' => 'GOOD']);

        $this->assertSame('RETURNED', $returned->status?->value);
        $this->assertNotNull($returned->returned_at);
        $this->assertSame(1, $returned->items()->where('returned', true)->count());

        $asset->refresh();
        $this->assertSame('ACTIVE', $asset->status?->value);
        $this->assertNull($asset->active_loan_id);

        $this->assertDatabaseHas('asset_audit_logs', ['action' => 'ASSET_RETURNED']);
        $this->assertDatabaseHas('asset_returns', ['loan_id' => $loan->id, 'condition' => 'GOOD']);
    }

    public function test_aset_tidak_dapat_dipinjam_dua_kali_berjalan_bersamaan(): void
    {
        $this->actAs($this->admin);
        $loan = $this->makeLoan();
        $asset = $loan->items()->first()->asset;

        $service = app(AssetLoanService::class);
        $service->approve($this->admin, $loan);
        $service->reserve($this->admin, $loan);
        $service->handover($this->admin, $loan);
        $service->activate($this->admin, $loan->refresh());

        // Pinjaman kedua atas aset yang sama, dengan jadwal yang BENTROK:
        // tertahan sejak tahap reservasi (penolakan paling awal, paling jelas).
        $bentrok = $service->request($this->admin, [
            'borrower_id' => $this->kepalaSekolah->id,
            'purpose' => 'Acara sekolah',
            'start_at' => now()->toDateTimeString(),
            'due_at' => now()->addDays(2)->toDateTimeString(),
        ], [$asset->id]);

        $service->approve($this->admin, $bentrok);

        try {
            $service->reserve($this->admin, $bentrok);
            $this->fail('Reservasi yang bentrok seharusnya ditolak.');
        } catch (AssetDomainException) {
            $this->assertTrue(true);
        }

        // Jadwal yang TIDAK bentrok lolos reservasi, tetapi tetap ditolak pada
        // saat serah terima: kunci basis data `active_loan_id` adalah pengaman
        // terakhir yang tidak dapat dilewati apa pun.
        $kedua = $service->request($this->admin, [
            'borrower_id' => $this->kepalaSekolah->id,
            'purpose' => 'Acara sekolah bulan depan',
            'start_at' => now()->addDays(10)->toDateTimeString(),
            'due_at' => now()->addDays(12)->toDateTimeString(),
        ], [$asset->id]);

        $service->approve($this->admin, $kedua);
        $service->reserve($this->admin, $kedua);

        $this->expectException(AssetDomainException::class);

        $service->handover($this->admin, $kedua->refresh());
    }

    public function test_pinjaman_melebihi_batas_hari_ditolak(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        $this->expectException(AssetValidationException::class);

        app(AssetLoanService::class)->request($this->admin, [
            'purpose' => 'Terlalu lama',
            'start_at' => now()->toDateTimeString(),
            'due_at' => now()->addDays(45)->toDateTimeString(),
        ], [$asset->id]);
    }

    public function test_aset_dalam_perbaikan_tidak_dapat_dipinjam(): void
    {
        $this->actAs($this->admin);
        $asset = $this->makeAsset();

        app(\App\Modules\Asset\Application\Services\AssetRegisterService::class)
            ->changeStatus($this->admin, $asset, \App\Modules\Asset\Domain\Enums\AssetStatus::IN_MAINTENANCE, 'Servis rutin.');

        $loan = app(AssetLoanService::class)->request($this->admin, [
            'purpose' => 'Mencoba meminjam barang yang diservis',
            'start_at' => now()->toDateTimeString(),
            'due_at' => now()->addDay()->toDateTimeString(),
        ], [$asset->id]);

        $service = app(AssetLoanService::class);
        $service->approve($this->admin, $loan);
        $service->reserve($this->admin, $loan);

        $this->expectException(AssetDomainException::class);

        $service->handover($this->admin, $loan->refresh());
    }

    public function test_pengembalian_rusak_melahirkan_laporan_dan_permintaan_pemeliharaan(): void
    {
        $this->actAs($this->admin);
        $loan = $this->makeLoan();
        $service = app(AssetLoanService::class);

        $loan = $service->activate($this->admin, $service->handover($this->admin, $service->reserve($this->admin, $service->approve($this->admin, $loan))));

        $returned = $service->return($this->admin, $loan->refresh(), [
            'condition' => 'DAMAGED',
            'damage_found' => true,
            'notes' => 'Layar pecah saat dipinjam.',
        ]);

        $this->assertSame('DAMAGED', $returned->status?->value);
        $this->assertNotNull($returned->damage_report_id);
        $this->assertDatabaseHas('damage_reports', ['id' => $returned->damage_report_id, 'severity' => 'MAJOR']);
        $this->assertDatabaseHas('maintenance_requests', ['damage_report_id' => $returned->damage_report_id]);
        $this->assertDatabaseHas('asset_returns', ['loan_id' => $loan->id, 'damage_found' => true]);
    }

    public function test_pinjaman_terlambat_ditandai_dan_diingatkan_sekali(): void
    {
        $this->actAs($this->admin);
        $loan = $this->makeLoan(['start_at' => now()->subDays(5)->toDateTimeString(), 'due_at' => now()->subDays(2)->toDateTimeString()]);
        $service = app(AssetLoanService::class);

        $loan = $service->activate($this->admin, $service->handover($this->admin, $service->reserve($this->admin, $service->approve($this->admin, $loan))));

        $marked = $service->markOverdue();
        $this->assertContains($loan->loan_number, $marked);
        $this->assertSame('OVERDUE', $loan->refresh()->status?->value);
        $query = fn () => \App\Modules\Asset\Models\AssetNotification::query()
            ->where('event', 'ASSET_LOAN_OVERDUE')
            ->where('recipient_id', $loan->borrower_id);

        $this->assertSame(1, $query()->count(), 'Peminjam diberi tahu sekali.');

        // Dijalankan lagi pada hari yang sama: tidak menambah pemberitahuan.
        $service->markOverdue();
        $this->assertSame(1, $query()->count());
    }
}
