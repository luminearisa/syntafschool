<?php

namespace Tests\Feature\Activity;

use App\Modules\Activity\Application\Services\ActivityAttendanceService;
use App\Modules\Activity\Application\Services\ActivityIntegrationService;
use App\Modules\Activity\Application\Services\ActivityRegistrationService;
use App\Modules\Activity\Application\Services\ActivityScheduleService;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Models\ActivityCharge;
use App\Modules\Activity\Models\ActivityEquipmentRequest;
use App\Modules\Activity\Models\ActivityFacilityBooking;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivitySession;

/**
 * Tata kelola: izin yang benar-benar dipakai, bukan sekadar tercatat.
 *
 * Sebelum berkas ini ada, empat izin tidak dipegang peran mana pun sehingga
 * kemampuannya mati: membatalkan pendaftaran oleh petugas, melampaui batas
 * jumlah aktivitas, mengoreksi kehadiran yang sudah terkunci, dan membaca
 * ringkasan pembinaan siswa oleh kepala sekolah.
 */
class ActivityGovernanceTest extends ActivityTestCase
{
    public function test_petugas_dapat_membatalkan_pendaftaran_atas_nama_siswa(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');

        // Persetujuan orang tua menahan pendaftaran pada status menunggu \u2014
        // inilah saat wajar pembatalan oleh petugas (siswa berubah pikiran).
        $program = $this->makeProgram(['code' => 'GOV-1', 'name' => 'Futsal', 'requires_parent_consent' => true]);
        $student = $this->makeStudent('Yuni');

        $registration = app(ActivityRegistrationService::class)->start($manager, $program, (int) $student->id);

        $this->assertNotSame('APPROVED', $registration->status->value);

        $this->postJson("/api/v1/activities/registrations/{$registration->id}/cancel", ['reason' => 'Siswa pindah sekolah.'])
            ->assertOk();

        $this->assertSame('CANCELLED', $registration->refresh()->status->value);
    }

    public function test_batas_jumlah_aktivitas_dapat_dilampaui_hanya_dengan_izin_khusus(): void
    {
        config(['activity.limits.max_active_activities' => 1]);

        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $student = $this->makeStudent('Zaki');

        $first = $this->makeProgram(['code' => 'GOV-2A', 'name' => 'Futsal']);
        $second = $this->makeProgram(['code' => 'GOV-2B', 'name' => 'Basket']);

        app(ActivityRegistrationService::class)->start($manager, $first, (int) $student->id);
        $this->assertSame(1, ActivityMembership::query()->where('student_id', $student->id)->where('status', 'ACTIVE')->count());

        // Koordinator dapat mendaftarkan siswa, tetapi tidak memegang izin
        // melampaui batas: pendaftaran kedua ditolak.
        $coordinator = $this->actingAsRole('ACTIVITY_COORDINATOR');
        $this->assertFalse($coordinator->can('activity.registration.override'));

        try {
            app(ActivityRegistrationService::class)->start($coordinator, $second, (int) $student->id);
            $this->fail('Pendaftaran kedua seharusnya ditolak oleh batas aktivitas.');
        } catch (\App\Modules\Activity\Domain\Exceptions\ActivityValidationException $exception) {
            $this->assertStringContainsString('aktivitas', $exception->getMessage());
        }

        // Pengelola aktivitas memegang izin melampaui batas: diterima, tetap tercatat.
        $this->actAs($manager);
        $this->assertTrue($manager->can('activity.registration.override'));

        $registration = app(ActivityRegistrationService::class)->start($manager, $second, (int) $student->id, [
            'allow_over_limit' => true,
        ]);

        $this->assertSame('APPROVED', $registration->status->value);
        $this->assertSame(2, ActivityMembership::query()->where('student_id', $student->id)->where('status', 'ACTIVE')->count());
    }

    public function test_kehadiran_terkunci_hanya_dapat_dikoreksi_pemegang_izin(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'GOV-3', 'name' => 'Futsal']);
        $this->makeSchedule($program, ['day_of_week' => 1]);

        $coach = $this->makeCoach('Pembina Futsal');
        $program->forceFill(['coach_employee_id' => $coach['employee']->id])->save();

        $student = $this->makeStudent('Aldi');
        app(ActivityRegistrationService::class)->start($manager, $program->refresh(), (int) $student->id);
        app(ActivityScheduleService::class)->generateSessions($program, '2026-09-07', '2026-09-14', $manager);

        $session = ActivitySession::query()->where('program_id', $program->id)->orderBy('date')->firstOrFail();
        $attendance = app(ActivityAttendanceService::class);
        $attendance->record($session, (int) $student->id, ['status' => 'ABSENT'], $manager);

        $attendance->completeSession($session->refresh(), $manager);
        $this->assertNotNull($session->refresh()->attendance_locked_at);

        // Pembina tidak dapat menyunting kehadiran yang sudah dikunci.
        $this->actAs($coach['user']);

        try {
            $attendance->record($session->refresh(), (int) $student->id, ['status' => 'PRESENT'], $coach['user']);
            $this->fail('Kehadiran terkunci seharusnya tidak dapat disunting pembina.');
        } catch (ActivityConflictException $exception) {
            $this->assertStringContainsString('dikunci', $exception->getMessage());
        }

        // Pengelola memegang izin koreksi kehadiran: dapat memperbaiki.
        $this->actAs($manager);
        $corrected = $attendance->record($session->refresh(), (int) $student->id, ['status' => 'PRESENT'], $manager);

        $this->assertSame('PRESENT', $corrected->status->value);
        $this->assertDatabaseHas('activity_audit_logs', ['action' => 'ATTENDANCE_OVERRIDDEN']);
    }

    public function test_jejak_audit_dapat_dibaca_dan_disaring(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'GOV-4', 'name' => 'Futsal']);
        $student = $this->makeStudent('Bila');

        app(ActivityRegistrationService::class)->start($manager, $program, (int) $student->id);

        // Pembina tidak berwenang membaca jejak audit.
        $this->actingAsRole('COACH');
        $this->getJson('/api/v1/activities/audit')->assertStatus(403);

        $this->actingAsRole('auditor');

        $response = $this->getJson('/api/v1/activities/audit?action=REGISTRATION_SUBMITTED');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));

        $row = $response->json('data.0');
        $this->assertSame('REGISTRATION_SUBMITTED', $row['action']);
        $this->assertNotNull($row['created_at']);

        // Penyaring per anak: hanya jejak anak itu.
        $this->getJson("/api/v1/activities/audit?student_id={$student->id}")->assertOk()->assertJsonPath('data.0.student_id', (int) $student->id);
        $this->getJson('/api/v1/activities/audit?student_id=999999')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_sekolah_dapat_melihat_persetujuan_yang_masih_menggantung(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $world = $this->makeStudentWithGuardian('Cinta');

        $program = $this->makeProgram([
            'code' => 'GOV-5',
            'name' => 'Kemah Bakti',
            'requires_parent_consent' => true,
        ]);

        app(ActivityRegistrationService::class)->start($manager, $program, (int) $world['student']->id);

        $this->actingAsRole('ACTIVITY_MANAGER');

        $response = $this->getJson('/api/v1/activities/consents?pending=1');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));
        $this->assertSame((int) $world['student']->id, $response->json('data.0.student_id'));

        // Pembina tidak berwenang membuka daftar persetujuan sama sekali.
        $this->actingAsRole('COACH');
        $this->getJson('/api/v1/activities/consents')->assertStatus(403);

        // Pendamping program memiliki izinnya, tetapi cakupan barisnya hanya anak
        // yang ia dampingi \u2014 di sini tidak ada satu pun.
        $this->actingAsRole('ACTIVITY_ADVISOR');
        $this->getJson('/api/v1/activities/consents')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_permintaan_tagihan_aktivitas_dapat_dilihat_dan_dibatalkan_beralasan(): void
    {
        config(['activity.finance.enabled' => true]);

        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'GOV-6', 'name' => 'Renang', 'requires_payment' => true, 'fee_amount' => 150000]);
        $student = $this->makeStudent('Damar');

        app(ActivityRegistrationService::class)->start($manager, $program, (int) $student->id);

        $charge = ActivityCharge::query()->firstOrFail();
        $this->assertSame('REQUESTED', $charge->status->value);

        $this->getJson('/api/v1/activities/integration/finance/charges')->assertOk()->assertJsonPath('meta.total', 1);

        $integration = app(ActivityIntegrationService::class);
        $cancelled = $integration->cancelCharge($charge, $manager, 'Siswa batal ikut, biaya tidak jadi ditagihkan.');

        $this->assertSame('CANCELLED', $cancelled->status->value);
        $this->assertDatabaseHas('activity_audit_logs', ['action' => 'CHARGE_CANCELLED']);

        // Sekali dibatalkan, tidak dapat dibatalkan lagi (dan tidak menghapus baris).
        $this->expectException(ActivityConflictException::class);
        $integration->cancelCharge($charge->refresh(), $manager, 'Lagi');
    }

    public function test_permintaan_perlengkapan_dan_pemesanan_ruang_dapat_dirapikan(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'GOV-7', 'name' => 'Futsal', 'room_id' => null]);

        $item = \App\Models\InventoryItem::query()->create([
            'sku' => 'KON-1',
            'name' => 'Kerucut Latihan',
            'quantity' => 10,
            'minimum_quantity' => 1,
            'unit_cost' => 25000,
            'is_active' => true,
        ]);

        $request = ActivityEquipmentRequest::query()->create([
            'program_id' => $program->id,
            'inventory_item_id' => $item->id,
            'description' => 'Kerucut untuk latihan dribling',
            'quantity' => 4,
            'status' => 'REQUESTED',
            'idempotency_key' => 'gov|equipment|1',
            'requested_by' => $manager->id,
        ]);

        $integration = app(ActivityIntegrationService::class);

        $this->getJson('/api/v1/activities/integration/inventory/requests')->assertOk()->assertJsonPath('meta.total', 1);

        // Pengelola aktivitas tidak berwenang menyelaraskan status barang.
        try {
            $integration->updateEquipmentRequest($request, 'ISSUED', $manager, 'Coba dari aktivitas.');
            $this->fail('Pengelola aktivitas seharusnya tidak dapat menyelaraskan status barang.');
        } catch (\App\Modules\Activity\Domain\Exceptions\ActivityValidationException $exception) {
            $this->assertStringContainsString('perlengkapan', $exception->getMessage());
        }

        $officer = $this->actingAsRole('petugas-inventaris');
        $issued = $integration->updateEquipmentRequest($request, 'ISSUED', $officer, 'Diserahkan petugas gudang.');
        $this->assertSame('ISSUED', $issued->status->value);
        $this->assertNotNull($issued->issued_at);
        $this->assertDatabaseHas('activity_audit_logs', ['action' => 'EQUIPMENT_REQUEST_UPDATED']);

        // Pemesanan ruang aktivitas: dibuat lewat jadwal, lalu dibatalkan dan
        // pembatalannya diteruskan ke modul Sarpras.
        $booking = app(ActivityScheduleService::class)->requestFacility(
            app(ActivityScheduleService::class)->createSchedule($program, [
                'day_of_week' => 2,
                'start_time' => '15:00',
                'end_time' => '17:00',
                'room_id' => $this->field->id,
            ], $manager),
            $program,
            $manager,
            '2026-09-08',
        );

        $this->assertSame('CONFIRMED', $booking->status->value);

        $cancelled = $integration->cancelFacilityBooking($booking, $manager, 'Kegiatan diganti ke lapangan luar.');

        $this->assertSame('CANCELLED', $cancelled->status->value);
        $this->assertDatabaseHas('room_bookings', ['id' => (int) substr((string) $booking->external_reference, strlen('room_booking:')), 'status' => 'cancelled']);
        $this->assertDatabaseHas('activity_audit_logs', ['action' => 'FACILITY_BOOKING_CANCELLED']);
    }

    public function test_pembina_tidak_dapat_membatalkan_pemesanan_ruang(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'GOV-8', 'name' => 'Futsal']);

        $schedule = app(ActivityScheduleService::class)->createSchedule($program, [
            'day_of_week' => 3,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'room_id' => $this->field->id,
        ], $manager);

        $booking = app(ActivityScheduleService::class)->requestFacility($schedule, $program, $manager, '2026-09-09');

        $coach = $this->actingAsRole('COACH');

        $this->expectException(\App\Modules\Activity\Domain\Exceptions\ActivityValidationException::class);

        app(ActivityIntegrationService::class)->cancelFacilityBooking($booking, $coach, 'Alasan pribadi');
    }
}
