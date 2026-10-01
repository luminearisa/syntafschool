<?php

namespace Tests\Feature\Activity;

use App\Modules\Activity\Application\Services\ActivityAttendanceService;
use App\Modules\Activity\Application\Services\ActivityProgramService;
use App\Modules\Activity\Application\Services\ActivityRegistrationService;
use App\Modules\Activity\Application\Services\ActivityScheduleService;
use App\Modules\Activity\Domain\Contracts\FacilityProviderInterface;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityAuditLog;
use App\Modules\Activity\Models\ActivityIntegrationLog;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivitySession;

/**
 * Keamanan (cakupan baris) dan integrasi dengan modul lain (\\u00a783, \\u00a788, \\u00a7log integrasi).
 */
class ActivitySecurityIntegrationTest extends ActivityTestCase
{
    public function test_endpoint_modul_menolak_akses_tanpa_autentikasi(): void
    {
        $this->getJson('/api/v1/activities/programs')->assertStatus(401);
        $this->getJson('/api/v1/activities/dashboard')->assertStatus(401);
    }

    public function test_peran_tanpa_izin_modul_tidak_dapat_membuka_katalog(): void
    {
        $this->actingAsRole('staf');

        $this->getJson('/api/v1/activities/programs')->assertStatus(403);
    }

    public function test_siswa_hanya_melihat_pendaftarannya_sendiri(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'ISO-1', 'name' => 'Basket']);

        $first = $this->makeStudentWithGuardian('Vito', null, true);
        $second = $this->makeStudentWithGuardian('Wulan', null, true);

        app(ActivityRegistrationService::class)->start($manager, $program, (int) $first['student']->id);
        app(ActivityRegistrationService::class)->start($manager, $program, (int) $second['student']->id);

        $this->actAs($first['student_user']);

        $response = $this->getJson('/api/v1/activities/registrations/mine');

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame((int) $first['student']->id, $response->json('data.0.student_id'));
    }

    public function test_orang_tua_tidak_dapat_membuka_portofolio_anak_orang_lain(): void
    {
        $first = $this->makeStudentWithGuardian('Xander');
        $second = $this->makeStudentWithGuardian('Yasmin');

        $this->actAs($first['guardian_user']);

        $this->getJson("/api/v1/activities/students/{$first['student']->id}/portfolio")->assertOk();
        $this->getJson("/api/v1/activities/students/{$second['student']->id}/portfolio")->assertStatus(403);
    }

    public function test_pembina_tidak_dapat_mencatat_kehadiran_di_luar_lingkupnya(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');

        $programA = $this->makeProgram(['code' => 'A-SCOPE', 'name' => 'Futsal']);
        $this->makeSchedule($programA, ['day_of_week' => 1]);
        $programB = $this->makeProgram(['code' => 'B-SCOPE', 'name' => 'Robotics']);
        $this->makeSchedule($programB, ['day_of_week' => 2]);

        $coach = $this->makeCoach('Pembina Futsal');
        $programA->forceFill(['coach_employee_id' => $coach['employee']->id])->save();

        app(ActivityRegistrationService::class)->start($manager, $programB, (int) $this->makeStudent('Zulaikha')->id);
        app(ActivityScheduleService::class)->generateSessions($programB, '2026-07-21', '2026-07-27', $manager);

        $session = ActivitySession::query()->where('program_id', $programB->id)->firstOrFail();
        $studentId = (int) ActivityMembership::query()->where('program_id', $programB->id)->firstOrFail()->student_id;

        $this->actAs($coach['user']);

        $this->expectException(ActivityValidationException::class);

        app(ActivityAttendanceService::class)->record($session, $studentId, ['status' => 'PRESENT'], $coach['user']);
    }

    public function test_setiap_keputusan_pending_meninggalkan_jejak_audit(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $student = $this->makeStudent('Adit');

        $program = $this->makeProgram(['code' => 'AUD-1', 'name' => 'Futsal', 'selection_method' => 'APPROVAL']);
        $registration = app(ActivityRegistrationService::class)->start($manager, $program, (int) $student->id);

        app(ActivityRegistrationService::class)->decide($registration, $manager, 'APPROVE', ['notes' => 'Kuota tersedia.']);

        $log = ActivityAuditLog::query()->where('action', 'REGISTRATION_APPROVED')->latest('id')->firstOrFail();

        $this->assertSame((int) $manager->id, (int) $log->actor_user_id);
        $this->assertSame((int) $registration->id, (int) $log->subject_id);
        $this->assertSame((int) $student->id, (int) $log->student_id);
        $this->assertSame('APPROVED', $log->after['status']);
    }

    public function test_panggilan_integrasi_yang_gagal_tidak_menjatuhkan_alur_dan_tercatat(): void
    {
        // Modul tetangga bermasalah: pemesanan fasilitas selalu melempar.
        app()->bind(FacilityProviderInterface::class, fn () => new class implements FacilityProviderInterface
        {
            public function room(int $roomId): ?array { return null; }
            public function rooms(array $filters = []): array { return []; }
            public function availability(int $roomId, string $date, string $startTime, string $endTime, ?int $ignoreBookingId = null): bool { return true; }
            public function book(array $payload): array { throw new \RuntimeException('Layanan sarpras sedang tidak tersedia.'); }
            public function cancel(string $reference): bool { return false; }
        });

        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'FAC-1', 'name' => 'Futsal', 'room_id' => $this->field->id]);

        $schedule = app(ActivityScheduleService::class)->createSchedule($program, [
            'day_of_week' => 1,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'room_id' => $this->field->id,
            'book_facility' => true,
            'facility_date' => '2026-07-20',
        ], $manager);

        // Jadwal tetap dibuat \\u2014 kegagalan modul lain tidak membatalkan jadwal,
        // tetapi sekolah dapat melihatnya di log integrasi.
        $this->assertNotNull($schedule->id);
        $this->assertDatabaseHas('activity_facility_bookings', [
            'schedule_id' => $schedule->id,
            'status' => 'FAILED',
            'conflict_reason' => null,
        ]);
        $this->assertDatabaseHas('activity_integration_logs', [
            'integration' => 'FACILITY',
            'action' => 'book',
            'status' => 'FAILED',
        ]);
    }

    public function test_inventaris_dan_fasilitas_terhubung_lewat_kontrak(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'INV-1', 'name' => 'Futsal']);

        $item = \App\Models\InventoryItem::query()->create([
            'sku' => 'BOLA-1',
            'name' => 'Bola Futsal',
            'quantity' => 5,
            'minimum_quantity' => 1,
            'unit_cost' => 150000,
            'is_active' => true,
        ]);

        $integrations = app(\App\Modules\Activity\Application\Services\ActivityIntegrationService::class);

        $issued = $integrations->inventory()->issue([
            'item_id' => $item->id,
            'quantity' => 2,
            'actor_user_id' => $manager->id,
            'reference' => 'activity:program:'.$program->id,
        ]);

        $this->assertSame('ISSUED', $issued['status']);
        $this->assertSame(3, $issued['remaining_stock']);
        $this->assertDatabaseHas('inventory_movements', ['inventory_item_id' => $item->id, 'movement_type' => 'OUT', 'quantity' => 2]);

        $returned = $integrations->inventory()->receive([
            'item_id' => $item->id,
            'quantity' => 2,
            'actor_user_id' => $manager->id,
        ]);

        $this->assertSame('RETURNED', $returned['status']);
        $this->assertSame(5, $returned['stock']);

        // Fasilitas: bentrok dilaporkan apa adanya, beserta pemesanan yang menghalangi.
        $facilities = $integrations->facilities();

        $first = $facilities->book([
            'room_id' => $this->field->id,
            'date' => '2026-07-21',
            'start_time' => '15:00',
            'end_time' => '17:00',
            'title' => 'Latihan Basket',
        ]);

        $this->assertSame('CONFIRMED', $first['status']);

        $second = $facilities->book([
            'room_id' => $this->field->id,
            'date' => '2026-07-21',
            'start_time' => '16:00',
            'end_time' => '18:00',
            'title' => 'Latihan Voli',
        ]);

        $this->assertSame('CONFLICT', $second['status']);
        $this->assertStringContainsString('Latihan Basket', $second['conflict']['reason']);
        $this->assertFalse($facilities->availability($this->field->id, '2026-07-21', '16:30', '17:30'));
    }

    public function test_modul_yang_belum_terpasang_dilaporkan_apa_adanya(): void
    {
        $integrations = app(\App\Modules\Activity\Application\Services\ActivityIntegrationService::class);

        $this->assertFalse($integrations->events()->isAvailable());
        $this->assertSame('SKIPPED', $integrations->events()->create(['name' => 'Pentas Seni'])['status']);
        $this->assertSame('SKIPPED', $integrations->transportation()->request(['destination' => 'Bandung'])['status']);

        $health = $integrations->health();
        $this->assertArrayHasKey('finance_mode', $health);
        $this->assertSame('WARN', $health['unpaid_policy']['policy']);
    }

    public function test_riwayat_keanggotaan_dan_penarikan_pengunduran_diri_terpisah(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $student = $this->makeStudent('Bimo');

        $program = $this->makeProgram(['code' => 'WD-1', 'name' => 'Pramuka']);
        $registration = app(ActivityRegistrationService::class)->start($manager, $program, (int) $student->id);

        $membership = ActivityMembership::query()->where('registration_id', $registration->id)->firstOrFail();

        $this->postJson("/api/v1/activities/memberships/{$membership->id}/withdraw", [
            'reason' => 'Jadwal bentrok dengan les.',
        ])->assertStatus(201);

        $withdrawal = \App\Modules\Activity\Models\ActivityWithdrawal::query()->firstOrFail();

        // Belum disetujui: permohonannya menggantung, keanggotaan belum berubah.
        $this->assertSame('PENDING', $withdrawal->status->value);
        $this->assertSame('ACTIVE', $membership->refresh()->status->value);

        $this->postJson("/api/v1/activities/withdrawals/{$withdrawal->id}/decide", [
            'decision' => 'APPROVE',
            'notes' => 'Disetujui pengelola.',
        ])->assertOk();

        $this->assertSame('WITHDRAWN', $membership->refresh()->status->value);
        $this->assertNotNull($membership->ended_at);
        $this->assertDatabaseHas('activity_membership_histories', [
            'membership_id' => $membership->id,
            'action' => 'WITHDRAWN',
        ]);
    }

    public function test_keanggotaan_dan_program_terlihat_sesuai_peran_pembina(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'COACH-1', 'name' => 'Robotics Club']);
        $coach = $this->makeCoach('Mentor Robotik');
        $program->forceFill(['coach_employee_id' => $coach['employee']->id])->save();

        app(ActivityRegistrationService::class)->start($manager, $program->refresh(), (int) $this->makeStudent('Cakra')->id);

        $this->actAs($coach['user']);

        $response = $this->getJson('/api/v1/activities/my-programs');

        $response->assertOk();
        $this->assertSame('Robotics Club', $response->json('data.0.name'));

        $dashboard = $this->getJson('/api/v1/activities/dashboard');
        $dashboard->assertOk();
        $this->assertSame(1, $dashboard->json('data.coach.programs.0.members'));

        $this->assertTrue(app(ActivityProgramService::class)->capacity($program->refresh())['active'] >= 1);
    }

    public function test_pengaturan_dan_kesehatan_modul_hanya_untuk_pemegang_izin(): void
    {
        $this->actingAsRole('ACTIVITY_ADMIN');

        $this->getJson('/api/v1/activities/settings')->assertOk();
        $this->getJson('/api/v1/activities/health')->assertOk();

        $this->actingAsRole('ACTIVITY_MANAGER');

        $this->getJson('/api/v1/activities/health')->assertStatus(403);
    }
}
