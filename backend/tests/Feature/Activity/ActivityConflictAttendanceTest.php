<?php

namespace Tests\Feature\Activity;

use App\Modules\Activity\Application\Services\ActivityAttendanceService;
use App\Modules\Activity\Application\Services\ActivityMembershipService;
use App\Modules\Activity\Application\Services\ActivityRegistrationService;
use App\Modules\Activity\Application\Services\ActivityScheduleService;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityAttendance;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivitySession;

/**
 * Deteksi bentrok jadwal (\\u00a770) dan kehadiran (\\u00a733\\u201335).
 */
class ActivityConflictAttendanceTest extends ActivityTestCase
{
    /** @return array{student: \App\Models\Student, membership: ActivityMembership, program: \App\Modules\Activity\Models\ActivityProgram} */
    protected function enroll(ActivityProgramFactory|array $config = []): array
    {
        return [];
    }

    public function test_bentrok_antar_aktivitas_menggagalkan_pendaftaran(): void
    {
        $class = $this->makeClass('VII A');
        $student = $this->makeStudent('Rani', $class);
        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        $futsal = $this->makeProgram(['code' => 'FUTSAL', 'name' => 'Futsal Senin']);
        $this->makeSchedule($futsal, ['day_of_week' => 1, 'start_time' => '15:00', 'end_time' => '17:00']);

        $robotics = $this->makeProgram(['code' => 'ROBOT', 'name' => 'Robotics Senin']);
        $this->makeSchedule($robotics, ['day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '18:00']);

        app(ActivityRegistrationService::class)->start($admin, $futsal, $student->id);

        try {
            app(ActivityRegistrationService::class)->start($admin, $robotics, $student->id);
            $this->fail('Pendaftaran bentrok seharusnya ditolak.');
        } catch (ActivityValidationException $exception) {
            $this->assertStringContainsString('Jadwal bentrok', implode(' ', $exception->errors()));
            $this->assertDatabaseMissing('activity_memberships', [
                'program_id' => $robotics->id,
                'student_id' => $student->id,
            ]);
        }
    }

    public function test_bentrok_dengan_pelajaran_akademik_terdeteksi(): void
    {
        $class = $this->makeClass('VII B');
        $student = $this->makeStudent('Sari', $class);
        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        // Pelajaran hari Senin 15:00–16:30 (kelas siswa) beririsan dengan jadwal aktivitas.
        $this->seedAcademicSchedule($class->id, 'Senin', '15:00', '16:30');

        $program = $this->makeProgram(['code' => 'TARI', 'name' => 'Seni Tari']);
        $this->makeSchedule($program, ['day_of_week' => 1, 'start_time' => '15:30', 'end_time' => '17:00']);

        $this->expectException(ActivityValidationException::class);

        app(ActivityRegistrationService::class)->start($admin, $program, $student->id);
    }

    public function test_bentrok_dengan_kegiatan_asrama_terdeteksi_dan_dapat_dilonggarkan_lewat_konfigurasi(): void
    {
        $class = $this->makeClass('VII C');
        $student = $this->makeStudent('Taufik', $class);
        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        $this->seedBoardingObligation(1, '15:00', '17:00', 'Kajian Ba\'da Ashar');

        $program = $this->makeProgram(['code' => 'KALIG', 'name' => 'Kaligrafi']);
        $this->makeSchedule($program, ['day_of_week' => 1, 'start_time' => '15:30', 'end_time' => '16:30']);

        // Tegas: bentrok menggagalkan pendaftaran.
        try {
            app(ActivityRegistrationService::class)->start($admin, $program, $student->id);
            $this->fail('Bentrok asrama seharusnya terdeteksi.');
        } catch (ActivityValidationException $exception) {
            $this->assertStringContainsString('asrama', implode(' ', $exception->errors()));
        }

        // Longgar: sekolah dapat memilih hanya memperingatkan.
        config(['activity.conflict.block_enrollment' => false]);

        $registration = app(ActivityRegistrationService::class)->start($admin, $program, $student->id);

        $this->assertSame('APPROVED', $registration->status->value);
        $this->assertNotEmpty($registration->validation_result['warnings'] ?? []);
    }

    public function test_kehadiran_idempoten_dan_tercermin_ke_modul_attendance_bila_diaktifkan(): void
    {
        config(['activity.attendance.mirror' => true]);

        // Master status kehadiran akademik diperlukan agar cerminan punya rujukan.
        \Illuminate\Support\Facades\DB::table('attendance_statuses')->insert([
            'code' => 'HADIR',
            'name' => 'Hadir',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $class = $this->makeClass('VII D');
        $student = $this->makeStudent('Umar', $class);
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');

        $program = $this->makeProgram(['code' => 'BASKET', 'name' => 'Basket']);
        $this->makeSchedule($program, ['day_of_week' => 1]);

        $registration = app(ActivityRegistrationService::class)->start($manager, $program, $student->id);

        $session = app(ActivityScheduleService::class)->generateSessions($program, '2026-07-20', '2026-07-26', $manager);

        $this->assertSame(1, $session['created'], 'Satu pertemuan Senin seharusnya dibuat.');

        $model = ActivitySession::query()->firstOrFail();

        $service = app(ActivityAttendanceService::class);

        $service->record($model, $student->id, [
            'status' => 'PRESENT',
            'idempotency_key' => 'qr-1',
            'source' => 'QR',
        ], $manager);

        // Pengiriman ulang dari aplikasi lapangan: tidak boleh menjadi dua catatan.
        $service->record($model, $student->id, [
            'status' => 'PRESENT',
            'idempotency_key' => 'qr-1',
            'source' => 'QR',
        ], $manager);

        $this->assertSame(1, ActivityAttendance::query()->where('session_id', $model->id)->count());

        $mirrorLog = \App\Modules\Activity\Models\ActivityIntegrationLog::query()->where('action', 'mirror')->latest('id')->first();
        $this->assertNotNull($mirrorLog, 'Panggilan cermin kehadiran seharusnya tercatat.');
        $this->assertNull($mirrorLog->error, 'Cermin kehadiran gagal: '.$mirrorLog->error);
        $this->assertNotNull(ActivityAttendance::query()->first()->attendance_reference, 'Rujukan cermin seharusnya tersimpan.');
        $this->assertDatabaseHas('activity_integration_logs', [
            'integration' => 'ATTENDANCE',
            'action' => 'mirror',
            'status' => 'OK',
        ]);

        $this->assertDatabaseHas('activity_memberships', [
            'student_id' => $student->id,
            'program_id' => $program->id,
        ]);

        $this->assertNotNull($registration->id);
    }

    public function test_persentase_kehadiran_dihitung_dan_mempengaruhi_progres(): void
    {
        $class = $this->makeClass('VII E');
        $student = $this->makeStudent('Vina', $class);
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');

        $program = $this->makeProgram(['code' => 'HADROH', 'name' => 'Hadroh', 'minimum_attendance_percentage' => 75]);
        $this->makeSchedule($program, ['day_of_week' => 1]);

        app(ActivityRegistrationService::class)->start($manager, $program, $student->id);

        // Senin: 20 Juli, 27 Juli, 3 Agustus \u2014 tiga pertemuan yang sudah berlangsung.
        app(ActivityScheduleService::class)->generateSessions($program, '2026-07-20', '2026-08-05', $manager);

        $sessions = ActivitySession::query()->orderBy('date')->get();
        $this->assertCount(3, $sessions);

        $service = app(ActivityAttendanceService::class);

        // Hadir, terlambat, lalu alpa → 2 dari 3 = 66.67%.
        $service->record($sessions[0], $student->id, ['status' => 'PRESENT'], $manager);
        $service->record($sessions[1], $student->id, ['status' => 'LATE', 'minutes_late' => 10], $manager);
        $service->record($sessions[2], $student->id, ['status' => 'ABSENT'], $manager);

        $summary = $service->studentSummary($student->id, $program->id);

        $this->assertSame(3, $summary['sessions_recorded']);
        $this->assertSame(2, $summary['present']);
        $this->assertSame(66.67, $summary['percentage']);

        $membership = ActivityMembership::query()
            ->where('student_id', $student->id)
            ->where('program_id', $program->id)
            ->firstOrFail();

        $progress = app(\App\Modules\Activity\Application\Services\ActivityAssessmentService::class)
            ->recalculateProgress((int) $membership->id);

        $this->assertSame(66.67, (float) $progress->attendance_percentage);
        $this->assertSame(2, $progress->sessions_attended);

        // Pelanggaran kehadiran minimum menghasilkan peringatan berjejak, bukan sanksi otomatis.
        $this->assertDatabaseHas('activity_notification_logs', [
            'event' => 'activity.ATTENDANCE_MISSING',
            'student_id' => $student->id,
        ]);
    }

    public function test_daftar_hadir_menolak_siswa_yang_bukan_anggota(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'OBSERVE', 'name' => 'Programming Club']);
        $this->makeSchedule($program, ['day_of_week' => 2]);

        app(ActivityRegistrationService::class)->start($manager, $program, $this->makeStudent('Wawan')->id);
        app(ActivityScheduleService::class)->generateSessions($program, '2026-07-21', '2026-07-27', $manager);

        $session = ActivitySession::query()->firstOrFail();
        $outsider = $this->makeStudent('Xenia');

        $this->expectException(ActivityValidationException::class);

        app(ActivityAttendanceService::class)->record($session, $outsider->id, ['status' => 'PRESENT'], $manager);
    }

    public function test_pembina_tidak_dapat_mencatat_kehadiran_program_yang_bukan_tugasnya(): void
    {
        $program = $this->makeProgram(['code' => 'MINE', 'name' => 'Futsal Saya']);
        $this->makeSchedule($program, ['day_of_week' => 3]);

        $otherCoach = $this->makeCoach('Pembina Lain');
        $this->actAs($otherCoach['user']);

        app(ActivityRegistrationService::class)->start(
            $this->actingAsRole('ACTIVITY_MANAGER'),
            $program,
            $this->makeStudent('Yudi')->id,
        );

        app(ActivityScheduleService::class)->generateSessions($program, '2026-07-22', '2026-07-23', $otherCoach['user']);

        $session = ActivitySession::query()->firstOrFail();

        $this->actAs($otherCoach['user']);

        $this->expectException(ActivityValidationException::class);

        app(ActivityAttendanceService::class)->record(
            $session,
            (int) \App\Modules\Activity\Models\ActivityMembership::query()->firstOrFail()->student_id,
            ['status' => 'PRESENT'],
            $otherCoach['user'],
        );
    }

    public function test_anggota_yang_ditangguhkan_tetap_tercatat_di_riwayat(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $student = $this->makeStudent('Zaki');
        $program = $this->makeProgram(['code' => 'SUSP', 'name' => 'Nasyid']);

        $registration = app(ActivityRegistrationService::class)->start($manager, $program, $student->id);
        $membership = ActivityMembership::query()->where('registration_id', $registration->id)->firstOrFail();

        $memberships = app(ActivityMembershipService::class);
        $memberships->suspend($membership, $manager, 'Melanggar aturan latihan.');
        $memberships->reactivate($membership->refresh(), $manager, 'Sudah berjanji mematuhi aturan.');

        $histories = $memberships->histories($membership->refresh());

        $this->assertSame(['CREATED', 'SUSPENDED', 'REACTIVATED'], array_column($histories, 'action'));
        $this->assertSame('ACTIVE', $membership->refresh()->status->value);
    }
}
