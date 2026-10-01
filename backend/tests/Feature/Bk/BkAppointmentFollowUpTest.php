<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Application\Services\BkAppointmentService;
use App\Modules\Bk\Application\Services\BkFollowUpService;
use App\Modules\Bk\Domain\Enums\FollowUpType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCounselorAvailability;
use App\Modules\Bk\Models\BkFollowUp;
use Illuminate\Support\Facades\DB;

/**
 * Janji temu (deteksi bentrok) & tindak lanjut (ritme pemantauan).
 */
class BkAppointmentFollowUpTest extends BkTestCase
{
    public function test_bentrok_konselor_dan_siswa_terdeteksi_dan_ditolak(): void
    {
        $first = $this->makeStudentWithFamily('Nabil');
        $second = $this->makeStudentWithFamily('Rahma');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);

        $start = now()->addDay()->setTime(9, 0);
        $end = now()->addDay()->setTime(9, 45);

        app(BkAppointmentService::class)->create($counselor, [
            'student_id' => $first['student']->id,
            'counselor_id' => $counselor->id,
            'scheduled_start' => $start->toDateTimeString(),
            'scheduled_end' => $end->toDateTimeString(),
        ]);

        // Konselor sudah punya janji pada jam yang sama.
        $this->expectException(BkValidationException::class);

        app(BkAppointmentService::class)->create($counselor, [
            'student_id' => $second['student']->id,
            'counselor_id' => $counselor->id,
            'scheduled_start' => $start->toDateTimeString(),
            'scheduled_end' => $end->toDateTimeString(),
        ]);
    }

    public function test_bentrok_jadwal_pelajaran_terdeteksi(): void
    {
        $family = $this->makeStudentWithFamily('Salsabila');
        $counselor = $this->makeCounselor();

        $monday = now()->next('Monday');
        $dayId = DB::table('days')->insertGetId(['name' => 'Senin', 'created_at' => now(), 'updated_at' => now()]);

        $subject = \App\Models\Subject::query()->create([
            'name' => 'Matematika',
            'code' => 'MTK-'.random_int(100, 999),
        ]);

        DB::table('schedules')->insert([
            'school_class_id' => $family['class']->id,
            'subject_id' => $subject->id,
            'day_id' => $dayId,
            'start_time' => '08:00',
            'end_time' => '09:30',
            'semester_id' => $this->semester->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conflicts = app(BkAppointmentService::class)->detectConflicts(
            $family['student']->id,
            $counselor->id,
            $monday->copy()->setTime(8, 15)->toDateTimeString(),
            $monday->copy()->setTime(9, 0)->toDateTimeString(),
        );

        $this->assertNotEmpty($conflicts);
        $this->assertSame('ACADEMIC_SCHEDULE', $conflicts[0]['type']);
    }

    public function test_di_luar_jam_ketersediaan_konselor_terdeteksi(): void
    {
        $family = $this->makeStudentWithFamily('Tariq');
        $counselor = $this->makeCounselor();

        $counselorProfile = \App\Modules\Bk\Models\BkCounselor::query()->where('user_id', $counselor->id)->firstOrFail();

        $dayOfWeek = (int) now()->addDay()->format('N');
        BkCounselorAvailability::query()->create([
            'counselor_id' => $counselorProfile->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $conflicts = app(BkAppointmentService::class)->detectConflicts(
            $family['student']->id,
            $counselor->id,
            now()->addDay()->setTime(14, 0)->toDateTimeString(),
            now()->addDay()->setTime(14, 45)->toDateTimeString(),
        );

        $this->assertContains('OUTSIDE_AVAILABILITY', array_column($conflicts, 'type'));
    }

    public function test_hanya_koordinator_dapat_menembus_bentrok(): void
    {
        $family = $this->makeStudentWithFamily('Wahid');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);

        $start = now()->addDay()->setTime(10, 0);
        $end = now()->addDay()->setTime(10, 45);

        app(BkAppointmentService::class)->create($counselor, [
            'student_id' => $family['student']->id,
            'counselor_id' => $counselor->id,
            'scheduled_start' => $start->toDateTimeString(),
            'scheduled_end' => $end->toDateTimeString(),
        ]);

        // Konselor biasa: ditolak meski meminta `force`.
        $this->expectException(BkValidationException::class);

        try {
            app(BkAppointmentService::class)->create($counselor, [
                'student_id' => $family['student']->id,
                'counselor_id' => $counselor->id,
                'scheduled_start' => $start->toDateTimeString(),
                'scheduled_end' => $end->toDateTimeString(),
                'force' => true,
            ]);
        } finally {
            $coordinator = $this->makeCounselor('BK_COORDINATOR');
            $this->actAs($coordinator);

            $appointment = app(BkAppointmentService::class)->create($coordinator, [
                'student_id' => $family['student']->id,
                'counselor_id' => $counselor->id,
                'scheduled_start' => $start->toDateTimeString(),
                'scheduled_end' => $end->toDateTimeString(),
                'force' => true,
            ]);

            $this->assertSame('OVERRIDDEN', $appointment->conflict_state);
            $this->assertNotEmpty($appointment->conflict_details);
        }
    }

    public function test_pengingat_janji_terkirim_sekali(): void
    {
        $family = $this->makeStudentWithFamily('Yasmin');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);

        app(BkAppointmentService::class)->create($counselor, [
            'student_id' => $family['student']->id,
            'counselor_id' => $counselor->id,
            'scheduled_start' => now()->addHours(6)->toDateTimeString(),
            'scheduled_end' => now()->addHours(6)->addMinutes(45)->toDateTimeString(),
        ]);

        $sent = app(BkAppointmentService::class)->sendReminders(24);

        $this->assertSame(1, $sent);
        $this->assertSame(0, app(BkAppointmentService::class)->sendReminders(24), 'Pengingat tidak diulang.');
        $this->assertDatabaseHas('bk_notification_logs', ['event' => 'APPOINTMENT_REMINDER']);
    }

    public function test_tindak_lanjut_terlambat_ditandai_dan_diberitahukan(): void
    {
        $family = $this->makeStudentWithFamily('Zahra');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeCase($counselor, $family['student']);

        $followUp = app(BkFollowUpService::class)->create($counselor, $case, [
            'title' => 'Memeriksa kehadiran',
            'type' => FollowUpType::PROGRESS_REVIEW->value,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        $result = app(BkFollowUpService::class)->flagOverdue();

        $this->assertSame(1, $result['flagged']);
        $this->assertSame(1, $result['notified']);
        $this->assertTrue($followUp->refresh()->is_overdue);
        $this->assertSame('MISSED', $followUp->status->value);
        $this->assertDatabaseHas('bk_notification_logs', ['event' => 'FOLLOW_UP_OVERDUE']);
    }

    public function test_tindak_lanjut_dapat_dijadwalkan_ulang_dan_diselesaikan(): void
    {
        $family = $this->makeStudentWithFamily('Adnan');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeCase($counselor, $family['student']);

        $followUp = app(BkFollowUpService::class)->create($counselor, $case, [
            'title' => 'Menghubungi orang tua',
            'due_date' => now()->addDays(2)->toDateString(),
        ]);

        app(BkFollowUpService::class)->reschedule($counselor, $followUp, now()->addDays(9)->toDateString(), 'Orang tua sedang di luar kota.');
        $this->assertSame(1, $followUp->refresh()->reschedule_count);

        $completed = app(BkFollowUpService::class)->complete($counselor, $followUp->refresh(), ['outcome' => 'Orang tua bersedia datang.']);
        $this->assertSame('COMPLETED', $completed->status->value);
        $this->assertFalse($completed->is_overdue);
    }

    public function test_konselor_tidak_melihat_tindak_lanjut_konselor_lain(): void
    {
        $family = $this->makeStudentWithFamily('Bahira');
        $counselor = $this->makeCounselor();
        $other = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeCase($counselor, $family['student']);
        $this->assignCase($counselor, $case, $counselor);

        app(BkFollowUpService::class)->create($counselor, $case, [
            'title' => 'Cek perkembangan',
            'due_date' => now()->addDays(3)->toDateString(),
            'assigned_to' => $counselor->id,
        ]);

        $this->actAs($other);
        $this->assertSame(0, BkFollowUp::query()->count() - 1, 'Hanya satu tindak lanjut yang ada.');
        $this->assertFalse(app(\App\Modules\Bk\Application\Services\BkAccessService::class)->canViewFollowUp($other, BkFollowUp::query()->firstOrFail()));
    }
}
