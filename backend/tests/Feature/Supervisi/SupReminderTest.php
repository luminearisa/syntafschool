<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Application\Services\ReminderService;
use App\Modules\Supervisi\Models\SupActionItem;
use App\Modules\Supervisi\Models\SupActionPlan;
use App\Modules\Supervisi\Models\SupCoachingSession;
use App\Modules\Supervisi\Models\SupPipPlan;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Support\Carbon;

/**
 * Fase 4 — pengingat terjadwal: kegiatan, RTL, coaching, dan tinjauan PIP.
 *
 * Yang diuji bukan "notifikasi terkirim", melainkan janji perintahnya:
 * penerima tepat, arah tautan tepat, dan pengingat tidak berulang pada hari
 * yang sama (idempoten) walau penjadwal berjalan dua kali.
 */
class SupReminderTest extends SupTestCase
{
    /** @return array{0: User, 1: Employee, 2: Employee, 3: User} */
    protected function actors(): array
    {
        $head = $this->employee(['full_name' => 'Hj. Siti Rahmawati', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $head, 'kind' => 'KEPALA_SEKOLAH', 'can_finalize' => true]);
        $headUser = $this->employeeWithAccount($head, 'kepala-sekolah');

        $teacher = $this->employee(['full_name' => 'Ahmad Fauzi', 'position' => 'Guru Biologi']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');

        return [$headUser, $head, $teacher, $teacherUser];
    }

    public function test_perintah_menghitung_dan_mengirim_pengingat_lalu_idempoten(): void
    {
        [$headUser, $head, $teacher, $teacherUser] = $this->actors();

        // 1) Kegiatan terjadwal besok (pengingat H-3).
        SupVisit::query()->create([
            'code' => 'SUP/2026/000501',
            'supervisor_employee_id' => $head->id,
            'supervisee_employee_id' => $teacher->id,
            'activity_kind' => 'CLASS_OBSERVATION',
            'status' => 'SCHEDULED',
            'observed_at' => now()->addDay()->setTime(8, 0),
        ]);

        // 2) Kegiatan yang sudah lewat tetapi belum difinalkan.
        SupVisit::query()->create([
            'code' => 'SUP/2026/000502',
            'supervisor_employee_id' => $head->id,
            'supervisee_employee_id' => $teacher->id,
            'activity_kind' => 'WALKTHROUGH',
            'status' => 'OBSERVED',
            'observed_at' => now()->subDays(3)->setTime(9, 0),
        ]);

        // 3) Butir RTL terlambat dengan penanggung jawab guru.
        $plan = SupActionPlan::query()->create([
            'title' => 'RTL observasi kelas',
            'status' => 'ONGOING',
            'supervisee_employee_id' => $teacher->id,
            'created_by' => $headUser->id,
        ]);

        SupActionItem::query()->create([
            'action_plan_id' => $plan->id,
            'statement' => 'Menyusun perangkat pembelajaran lengkap',
            'owner_employee_id' => $teacher->id,
            'due_date' => now()->subDays(2)->toDateString(),
            'status' => 'IN_PROGRESS',
            'progress_percent' => 40,
        ]);

        // 4) Coaching yang tindak lanjutnya sudah jatuh tempo.
        SupCoachingSession::query()->create([
            'supervisor_employee_id' => $head->id,
            'supervisee_employee_id' => $teacher->id,
            'session_at' => now()->subDays(20),
            'follow_up_at' => now()->subDay(),
            'status' => 'OPEN',
            'focus' => 'Pengelolaan kelas',
        ]);

        // 5) PIP aktif dengan tinjauan akhir minggu ini.
        SupPipPlan::query()->create([
            'supervisee_employee_id' => $teacher->id,
            'status' => 'ACTIVE',
            'reason' => 'Nilai PKG di bawah standar',
            'start_date' => now()->subMonth()->toDateString(),
            'final_review_date' => now()->addDays(3)->toDateString(),
            'created_by' => $headUser->id,
        ]);

        // Pratinjau tidak mengirim apa pun.
        $pratinjau = app(ReminderService::class)->run(now(), true);
        $this->assertEquals(1, $pratinjau[ReminderService::TYPE_VISIT_SCHEDULED]);
        $this->assertEquals(1, $pratinjau[ReminderService::TYPE_VISIT_UNFINALIZED]);
        // Butir RTL: penanggung jawab (guru) + pencatat RTL (atasan).
        $this->assertEquals(2, $pratinjau[ReminderService::TYPE_ACTION_ITEM_DUE]);
        $this->assertEquals(1, $pratinjau[ReminderService::TYPE_COACHING_DUE]);
        $this->assertEquals(1, $pratinjau[ReminderService::TYPE_PIP_REVIEW_DUE]);
        $this->assertDatabaseCount('notifications', 0);

        // Menjalankan perintah sungguhan mengirim ke penerima yang tepat.
        $this->artisan('supervisi:remind')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 6);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $headUser->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $teacherUser->id]);

        // Butir RTL: guru (penanggung jawab) dan atasan (pengelola) sama-sama diberi tahu.
        $rtlNotification = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('data', 'like', '%action-item-overdue%')
            ->pluck('notifiable_id')
            ->all();
        $this->assertEqualsCanonicalizing([$teacherUser->id, $headUser->id], $rtlNotification);

        // Idempoten: menjalankan ulang pada hari yang sama tidak menambah baris.
        $this->artisan('supervisi:remind')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 6);

        // Keesokan hari pengingat dikirim lagi (tenggatnya masih berjalan).
        $besok = Carbon::parse(now()->addDay()->toDateString());
        $this->artisan('supervisi:remind', ['--date' => $besok->toDateString()])->assertSuccessful();

        $kedua = app(ReminderService::class)->run($besok, true);
        $this->assertGreaterThan(0, array_sum($kedua));
        $this->assertGreaterThan(6, \Illuminate\Support\Facades\DB::table('notifications')->count());
    }

    public function test_pengingat_tidak_dikirim_bila_tidak_ada_pekerjaan_terbuka(): void
    {
        $this->actors();

        $this->artisan('supervisi:remind', ['--dry-run' => true])
            ->expectsOutputToContain('0 pengingat siap dikirim.')
            ->assertSuccessful();

        $this->artisan('supervisi:remind')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, app(ReminderService::class)->run(now(), true)[ReminderService::TYPE_ACTION_ITEM_DUE]);
    }
}
