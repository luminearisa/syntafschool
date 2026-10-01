<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Application\Services\FacilityBookingService;
use App\Modules\Asset\Application\Services\FacilityService;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;

class FacilityBookingTest extends AssetTestCase
{
    public function test_booking_lengkap_sampai_selesai_dan_tercatat_pemanfaatannya(): void
    {
        $this->actAs($this->guru);
        $facility = $this->makeFacility();
        $service = app(FacilityBookingService::class);

        $booking = $service->book($this->guru, $facility, [
            'title' => 'Rapat wali kelas',
            'attendee_count' => 20,
            'start_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => now()->addDay()->setTime(11, 0)->toDateTimeString(),
        ]);

        $this->assertSame('REQUESTED', $booking->status?->value);
        $this->assertNotNull($booking->conflict_checked_at);

        $booking = $service->approve($this->admin, $booking);
        $this->assertSame('RESERVED', $booking->status?->value);

        $booking = $service->checkIn($this->guru, $booking);
        $booking = $service->complete($this->guru, $booking);

        $this->assertSame('COMPLETED', $booking->status?->value);
        $this->assertDatabaseHas('facility_utilization_daily', ['facility_id' => $facility->id]);
        $this->assertDatabaseHas('asset_audit_logs', ['action' => 'FACILITY_BOOKED']);
    }

    public function test_benturan_jadwal_ditolak(): void
    {
        $this->actAs($this->guru);
        $facility = $this->makeFacility();
        $service = app(FacilityBookingService::class);

        $service->book($this->guru, $facility, [
            'title' => 'Kelas tambahan',
            'start_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => now()->addDay()->setTime(11, 0)->toDateTimeString(),
        ]);

        $this->expectException(AssetDomainException::class);

        $service->book($this->kepalaSekolah, $facility, [
            'title' => 'Rapat bersamaan',
            'start_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
            'end_at' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
        ]);
    }

    public function test_fasilitas_yang_ditutup_tidak_dapat_dibooking(): void
    {
        $this->actAs($this->admin);
        $facility = $this->makeFacility();
        $facilities = app(FacilityService::class);

        $result = $facilities->close($this->admin, $facility, 'Perbaikan lantai', now(), now()->addWeek());

        $this->assertSame('MAINTENANCE', $result['facility']->status?->value);

        $this->expectException(AssetDomainException::class);

        app(FacilityBookingService::class)->book($this->guru, $result['facility'], [
            'title' => 'Mencoba memesan ruang yang ditutup',
            'start_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        ]);
    }

    public function test_penutupan_fasilitas_melaporkan_dan_memberi_tahu_booking_terdampak(): void
    {
        $this->actAs($this->guru);
        $facility = $this->makeFacility();

        $booking = app(FacilityBookingService::class)->book($this->guru, $facility, [
            'title' => 'Ujian praktik',
            'start_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => now()->addDay()->setTime(11, 0)->toDateTimeString(),
        ]);

        $result = app(FacilityService::class)->close($this->admin, $facility, 'Kebocoran atap', now(), now()->addWeek());

        $this->assertCount(1, $result['affected']);
        $this->assertSame($booking->booking_number, $result['affected'][0]['booking_number']);
        $this->assertTrue(
            \App\Modules\Asset\Models\AssetNotification::query()
                ->where('event', 'FACILITY_CLOSED')
                ->where('recipient_id', $this->guru->id)
                ->exists(),
        );
    }

    public function test_durasi_di_luar_batas_dan_di_luar_jam_operasional_ditolak(): void
    {
        $this->actAs($this->guru);
        $facility = $this->makeFacility();
        $service = app(FacilityBookingService::class);

        // Terlalu singkat (min 30 menit).
        try {
            $service->book($this->guru, $facility, [
                'title' => 'Terlalu singkat',
                'start_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
                'end_at' => now()->addDay()->setTime(9, 10)->toDateTimeString(),
            ]);
            $this->fail('Durasi di bawah minimum seharusnya ditolak.');
        } catch (AssetValidationException $exception) {
            $this->assertArrayHasKey('end_at', $exception->errors());
        }

        // Melewati jam tutup (18:00).
        $this->expectException(AssetValidationException::class);

        $service->book($this->guru, $facility, [
            'title' => 'Malam hari',
            'start_at' => now()->addDay()->setTime(17, 0)->toDateTimeString(),
            'end_at' => now()->addDay()->setTime(20, 0)->toDateTimeString(),
        ]);
    }

    public function test_ketersediaan_menyebutkan_alasan_penolakan(): void
    {
        $this->actAs($this->guru);
        $facility = $this->makeFacility();

        app(FacilityBookingService::class)->book($this->guru, $facility, [
            'title' => 'Kegiatan pagi',
            'start_at' => now()->addDay()->setTime(8, 0)->toDateTimeString(),
            'end_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        ]);

        $hasil = app(FacilityService::class)->availability(
            $facility,
            now()->addDay()->setTime(9, 0),
            now()->addDay()->setTime(11, 0),
        );

        $this->assertFalse($hasil['available']);
        $this->assertNotEmpty($hasil['reasons']);
        $this->assertStringContainsString('dibooking', implode(' ', $hasil['reasons']));
    }
}
