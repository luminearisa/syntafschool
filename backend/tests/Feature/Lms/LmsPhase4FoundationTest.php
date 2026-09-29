<?php

namespace Tests\Feature\Lms;

use App\Models\User;
use App\Modules\LMS\Application\Services\BadgeService;
use App\Modules\LMS\Application\Services\CertificateService;
use App\Modules\LMS\Application\Services\GamificationService;
use App\Modules\LMS\Application\Services\LiveSessionService;
use App\Modules\LMS\Domain\Enums\BadgeAwardSource;
use App\Modules\LMS\Domain\Enums\BadgeRuleType;
use App\Modules\LMS\Domain\Enums\GamificationEventType;
use App\Modules\LMS\Domain\Enums\LiveAttendanceStatus;
use App\Modules\LMS\Domain\Enums\LiveSessionType;
use App\Modules\LMS\Models\LmsBadgeAward;
use App\Modules\LMS\Models\LmsCertificate;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGamificationPoint;
use App\Modules\LMS\Models\LmsLearningStreak;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Phase 4 — fondasi layanan: gamifikasi, badge, sertifikat, kelas langsung
 * (§21–§30). Tes ini sengaja berada di level service (bukan HTTP) karena
 * controller/route Phase 4 menyusul; yang diuji adalah aturannya.
 */
class LmsPhase4FoundationTest extends LmsTestCase
{
    /** Course dengan gamifikasi & sertifikat aktif — prasyarat modul Phase 4. */
    private function gamifiedCourse(array $world, array $attributes = []): LmsCourse
    {
        config(['lms.gamification.enabled' => true]);

        $course = $this->publishCourse($this->makeCourse($world), $world);

        $course->forceFill(array_merge([
            'gamification_enabled' => true,
            'certificate_enabled' => true,
            'completion_rule' => 'ALL_REQUIRED_ACTIVITIES',
        ], $attributes))->save();

        return $course->fresh();
    }

    // ==================================================================
    // Gamifikasi (§28–§30)
    // ==================================================================

    public function test_gamifikasi_nonaktif_tidak_mencatat_poin_apa_pun(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world); // gamification_enabled = false

        $points = app(GamificationService::class)->award(
            GamificationEventType::LESSON_COMPLETED,
            (int) $world['studentUsers'][0]->id,
            $course,
            'lesson:global:1',
        );

        $this->assertNull($points, 'Modul gamifikasi mati harus mengembalikan null tanpa error.');
        $this->assertSame(0, LmsGamificationPoint::query()->count());
    }

    public function test_xp_dedupe_mencegah_poin_ganda(): void
    {
        $world = $this->world();
        $course = $this->gamifiedCourse($world);
        $enrollment = $this->enroll($course, $world['studentUsers'][0]);

        $gamification = app(GamificationService::class);
        $key = 'lesson:'.$world['studentUsers'][0]->id.':1';

        $first = $gamification->award(GamificationEventType::LESSON_COMPLETED, (int) $world['studentUsers'][0]->id, $course, $key);
        $second = $gamification->award(GamificationEventType::LESSON_COMPLETED, (int) $world['studentUsers'][0]->id, $course, $key);

        $this->assertNotNull($first);
        $this->assertNull($second, 'Peristiwa yang sama tidak boleh menghasilkan XP dua kali (§72).');
        $this->assertSame(1, LmsGamificationPoint::query()->count());
        $this->assertSame(10, $gamification->totalFor((int) $world['studentUsers'][0]->id, (int) $course->id));
        $this->assertSame(10, (int) $enrollment->fresh()->xp_points);
    }

    public function test_rentetan_belajar_menambah_hari_dan_memberi_bonus_mingguan(): void
    {
        $world = $this->world();
        $course = $this->gamifiedCourse($world);
        $userId = (int) $world['studentUsers'][0]->id;

        config(['lms.gamification.streak.bonus_per_week' => 5]);

        $gamification = app(GamificationService::class);
        $start = Carbon::parse('2026-08-01 09:00:00');

        for ($day = 0; $day < 7; $day++) {
            $gamification->touchStreak($userId, $course, $start->copy()->addDays($day));
        }

        $streak = LmsLearningStreak::query()->where('user_id', $userId)->where('course_id', $course->id)->firstOrFail();

        $this->assertSame(7, (int) $streak->current_streak);
        $this->assertSame(7, (int) $streak->longest_streak);
        $this->assertSame(7, (int) $streak->total_active_days);

        $bonus = LmsGamificationPoint::query()
            ->where('user_id', $userId)
            ->where('event_type', GamificationEventType::STREAK_BONUS->value)
            ->get();

        $this->assertCount(1, $bonus, 'Hari ke-7 menghasilkan satu bonus rentetan.');
        $this->assertSame(5, (int) $bonus->first()->points);

        // Hari yang sama dipanggil ulang tidak menambah apa pun.
        $gamification->touchStreak($userId, $course, $start->copy()->addDays(6));

        $this->assertSame(7, (int) $streak->fresh()->current_streak);
        $this->assertSame(1, LmsGamificationPoint::query()->where('user_id', $userId)->where('event_type', GamificationEventType::STREAK_BONUS->value)->count());
    }

    public function test_papan_peringkat_nonaktif_default_dan_tampil_bila_diizinkan(): void
    {
        $world = $this->world();
        $course = $this->gamifiedCourse($world);

        $gamification = app(GamificationService::class);

        foreach ($world['studentUsers'] as $index => $user) {
            $this->enroll($course, $user);
            $gamification->award(GamificationEventType::QUIZ_PASSED, (int) $user->id, $course, 'quiz:'.$user->id, 30 + $index);
        }

        $disabled = $gamification->leaderboardForCourse($course);
        $this->assertFalse($disabled['enabled'], 'Leaderboard default NONAKTIF (§29).');
        $this->assertSame([], $disabled['entries']);

        config(['lms.gamification.leaderboard_enabled' => true, 'lms.gamification.display' => 'initials']);
        $course->forceFill(['leaderboard_enabled' => true])->save();

        $board = $gamification->leaderboardForCourse($course->fresh(), 0, (int) $world['studentUsers'][2]->id);

        $this->assertTrue($board['enabled']);
        $this->assertCount(3, $board['entries']);
        $this->assertSame(32, $board['entries'][0]['points'], 'Poin tertinggi menempati peringkat 1.');
        $this->assertSame('S. 3.', $board['entries'][0]['name'], 'Mode initials menyembunyikan nama lengkap.');
        $this->assertSame(1, $board['me']['rank']);
    }

    // ==================================================================
    // Badge (§26–§27)
    // ==================================================================

    public function test_badge_otomatis_diberikan_saat_aturan_terpenuhi(): void
    {
        $world = $this->world();
        $course = $this->gamifiedCourse($world);
        $enrollment = $this->enroll($course, $world['studentUsers'][0]);
        $actor = $this->actorFor($this->actingAsRole('guru'));

        $badge = app(BadgeService::class)->create([
            'code' => 'tuntas-matematika',
            'name' => 'Tuntas Matematika',
            'rule_type' => BadgeRuleType::COMPLETION->value,
            'points' => 15,
            'rules' => [
                ['type' => BadgeRuleType::COMPLETION->value, 'threshold' => 100],
            ],
        ], $actor);

        $this->assertSame('TUNTAS-MATEMATIKA', $badge->code, 'Kode badge dinormalisasi.');

        // Progress belum tuntas → belum ada badge.
        $this->assertSame([], app(BadgeService::class)->evaluateEnrollment($enrollment, $actor));

        $enrollment->forceFill(['progress_percent' => 100])->save();

        $first = app(BadgeService::class)->evaluateEnrollment($enrollment->fresh(), $actor);
        $second = app(BadgeService::class)->evaluateEnrollment($enrollment->fresh(), $actor);

        $this->assertCount(1, $first);
        $this->assertSame('TUNTAS-MATEMATIKA', $first[0]['code']);
        $this->assertSame([], $second, 'Badge yang sama tidak diberikan dua kali (§27).');
        $this->assertSame(1, LmsBadgeAward::query()->count());

        // Poin badge masuk ke ledger (BADGE_AWARDED) dan dedupe-nya stabil.
        $this->assertSame(15, app(GamificationService::class)->totalFor((int) $enrollment->user_id, (int) $course->id));
    }

    public function test_badge_manual_diberikan_guru_dan_dapat_dicabut(): void
    {
        $world = $this->world();
        $course = $this->gamifiedCourse($world);
        $enrollment = $this->enroll($course, $world['studentUsers'][0]);
        $actor = $this->actorFor($this->actingAsRole('guru'));

        $badge = app(BadgeService::class)->create([
            'code' => 'PARTISIPASI',
            'name' => 'Partisipasi Aktif',
            'rule_type' => BadgeRuleType::MANUAL->value,
        ], $actor);

        $result = app(BadgeService::class)->awardManual($badge, [(int) $enrollment->user_id], $course, $actor, 'Aktif bertanya');

        $this->assertCount(1, $result['awarded']);
        $this->assertSame([], $result['skipped']);

        $award = LmsBadgeAward::query()->firstOrFail();
        $this->assertSame(BadgeAwardSource::MANUAL, $award->source);

        // Aturan MANUAL tidak pernah dipicu sistem otomatis.
        $this->assertSame([], app(BadgeService::class)->evaluateEnrollment($enrollment->fresh(), $actor));

        app(BadgeService::class)->revoke($award, $actor, 'Salah siswa');

        $this->assertSame(0, LmsBadgeAward::query()->count());
        $this->assertSame([], app(BadgeService::class)->forEnrollment($enrollment->fresh()));
    }

    // ==================================================================
    // Sertifikat (§21–§25)
    // ==================================================================

    public function test_sertifikat_terbit_idempoten_dan_dapat_diverifikasi(): void
    {
        $world = $this->world();
        $course = $this->gamifiedCourse($world, ['certificate_min_progress' => 50]);
        $enrollment = $this->enroll($course, $world['studentUsers'][0], ['student_id' => $world['students'][0]->id]);
        $actor = $this->actorFor($this->actingAsRole('admin-sekolah'));

        $certificates = app(CertificateService::class);

        // Belum memenuhi progress minimum → ditolak dengan daftar syarat.
        $eligibility = $certificates->eligibility($enrollment);
        $this->assertFalse($eligibility['eligible']);
        $this->assertNotEmpty($eligibility['missing']);

        $enrollment->forceFill(['progress_percent' => 100, 'status' => 'COMPLETED', 'completed_at' => now()])->save();

        $eligibility = $certificates->eligibility($enrollment->fresh());
        $this->assertTrue($eligibility['eligible'], 'Semua syarat terpenuhi: '.implode(', ', $eligibility['missing']));

        $first = $certificates->issue($enrollment->fresh(), $actor);
        $second = $certificates->issue($enrollment->fresh(), $actor);

        $this->assertSame($first->id, $second->id, 'Penerbitan bersifat idempoten (§23).');
        $this->assertSame(1, LmsCertificate::query()->count());
        $this->assertStringStartsWith('CERT-', $first->certificate_number);
        $this->assertSame('Siswa 1', $first->holderName(), 'Snapshot menyimpan nama saat terbit.');

        $verification = $certificates->verify($first->verification_token);

        $this->assertTrue($verification['valid']);
        $this->assertSame('VALID', $verification['result']);
        $this->assertDatabaseHas('lms_certificate_verifications', ['result' => 'VALID']);

        // Verifikasi token asing tetap tercatat sebagai NOT_FOUND (§24).
        $this->assertSame('NOT_FOUND', $certificates->verify('token-palsu')['result']);

        $certificates->revoke($first, $actor, 'Kesalahan data');

        $this->assertSame('REVOKED', $certificates->verify($first->fresh()->verification_token)['result']);
        $this->assertNotNull($first->fresh()->revoked_at);
    }

    public function test_sertifikat_mengganti_placeholder_dengan_snapshot(): void
    {
        $world = $this->world();
        $course = $this->gamifiedCourse($world);
        $enrollment = $this->enroll($course, $world['studentUsers'][0]);
        $actor = $this->actorFor($this->actingAsRole('admin-sekolah'));

        $enrollment->forceFill(['progress_percent' => 100, 'status' => 'COMPLETED', 'completed_at' => now()])->save();

        $certificates = app(CertificateService::class);
        $certificate = $certificates->issue($enrollment->fresh(), $actor);

        $rendered = $certificates->render($certificate);

        $this->assertStringNotContainsString('{{', $rendered['body_html'], 'Semua placeholder harus terisi.');
        $this->assertStringContainsString('Siswa 1', $rendered['body_html']);
        $this->assertStringContainsString($course->name, $rendered['body_html']);
        $this->assertStringContainsString($certificate->certificate_number, $rendered['body_html']);
    }

    public function test_penerbitan_massal_melewati_siswa_yang_belum_memenuhi_syarat(): void
    {
        $world = $this->world();
        $course = $this->gamifiedCourse($world, ['certificate_min_progress' => 100]);
        $actor = $this->actorFor($this->actingAsRole('admin-sekolah'));

        $first = $this->enroll($course, $world['studentUsers'][0]);
        $this->enroll($course, $world['studentUsers'][1]);

        $first->forceFill(['progress_percent' => 100, 'status' => 'COMPLETED', 'completed_at' => now()])->save();

        $result = app(CertificateService::class)->issueForCourse($course, $actor);

        $this->assertCount(1, $result['issued']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($first->id, $result['issued'][0]['enrollment_id']);
    }

    // ==================================================================
    // Kelas langsung (§13–§16)
    // ==================================================================

    public function test_jendela_gabung_terbuka_mendekati_waktu_mulai(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $session = $live->create($course, [
            'title' => 'Kelas Perdana',
            'type' => LiveSessionType::LIVE_CLASS->value,
            'start_at' => now()->addDays(2)->toIso8601String(),
            'end_at' => now()->addDays(2)->addHour()->toIso8601String(),
            'meeting_url' => 'https://meet.example.com/abc-defg',
        ], $teacher);

        $this->assertNull($session->published_at, 'Sesi baru belum terlihat siswa.');

        $live->publish($session, $teacher);

        $student = $this->actorFor($world['studentUsers'][0]);

        // Dua hari lagi: jendela gabung (default 15 menit sebelum mulai) belum dibuka.
        $early = $live->joinInfo($session->fresh(), $student);
        $this->assertFalse($early['joinable']);
        $this->assertStringContainsString('Tautan gabung aktif', (string) $early['reason']);
        $this->assertNull($early['url']);

        // Waktu dimajukan sampai sesi berjalan → tautan terbuka tanpa membocorkan host_url.
        $session->forceFill([
            'start_at' => now()->subMinutes(5),
            'end_at' => now()->addMinutes(55),
        ])->save();

        $info = $live->joinInfo($session->fresh(), $student);

        $this->assertTrue($info['joinable']);
        $this->assertSame('https://meet.example.com/abc-defg', $info['url']);
        $this->assertSame('LIVE', $info['status']);
    }

    public function test_gabung_di_luar_jendela_ditolak(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $session = $live->create($course, [
            'title' => 'Kelas Besok',
            'start_at' => now()->addDay()->toIso8601String(),
            'end_at' => now()->addDay()->addHour()->toIso8601String(),
            'meeting_url' => 'https://meet.example.com/besok',
        ], $teacher);

        $live->publish($session, $teacher);

        $this->expectException(ValidationException::class);

        $live->join($session->fresh(), $this->actorFor($world['studentUsers'][0]));
    }

    public function test_kehadiran_manual_dan_gabung_siswa_tercatat_dengan_sumber_yang_tepat(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $first = $this->enroll($course, $world['studentUsers'][0]);
        $second = $this->enroll($course, $world['studentUsers'][1]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $session = $live->create($course, [
            'title' => 'Konsultasi Aljabar',
            'type' => LiveSessionType::OFFICE_HOUR->value,
            'start_at' => now()->subMinutes(5)->toIso8601String(),
            'end_at' => now()->addMinutes(55)->toIso8601String(),
            'meeting_url' => 'https://meet.example.com/konsultasi',
        ], $teacher);

        $live->publish($session, $teacher);

        $this->assertSame('LIVE', $live->refreshStatus($session->fresh())->status->value, 'Sesi berjalan otomatis berstatus LIVE.');

        // Siswa pertama bergabung sendiri.
        $join = $live->join($session->fresh(), $this->actorFor($world['studentUsers'][0]));

        $this->assertTrue($join['join']['joinable']);
        $this->assertSame('PRESENT', $join['attendance']['status']);
        $this->assertSame('SELF', $join['attendance']['source']);

        // Guru mencatat kehadiran siswa kedua secara manual.
        $marked = $live->markAttendance($session->fresh(), [
            ['user_id' => (int) $second->user_id, 'status' => LiveAttendanceStatus::LATE->value, 'note' => 'Terlambat 10 menit'],
        ], $teacher);

        $this->assertCount(1, $marked['saved']);
        $this->assertSame(2, $marked['summary']['total_recorded']);
        $this->assertSame(2, $marked['summary']['present'], 'PRESENT dan LATE sama-sama dihitung hadir.');
        $this->assertSame(100.0, $marked['summary']['attendance_rate']);

        $this->assertDatabaseHas('lms_live_session_attendance', [
            'live_session_id' => $session->id,
            'user_id' => $second->user_id,
            'status' => 'LATE',
            'source' => 'MANUAL',
        ]);

        $this->assertDatabaseHas('lms_learning_events', [
            'live_session_id' => $session->id,
            'user_id' => $first->user_id,
            'event_type' => 'live_session_joined',
        ]);
    }

    public function test_rekaman_menandai_selesai_setelah_melewati_ambang(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $recording = $live->addCourseRecording($course, [
            'title' => 'Rekaman Kelas Perdana',
            'url' => 'https://video.example.com/rekaman-1',
            'duration_seconds' => 3600,
            'completion_threshold' => 80,
        ], $teacher);

        $student = $this->actorFor($world['studentUsers'][0]);

        $partial = $live->recordWatch($recording, $student, 40);
        $this->assertFalse($partial['completed']);

        $finished = $live->recordWatch($recording, $student, 90);
        $this->assertTrue($finished['completed']);
        $this->assertSame(80, $finished['threshold']);

        $this->assertDatabaseHas('lms_learning_events', [
            'user_id' => $world['studentUsers'][0]->id,
            'event_type' => 'video_completed',
        ]);
    }

    public function test_sesi_yang_dibatalkan_tidak_dapat_digabung(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $session = $live->create($course, [
            'title' => 'Webinar Batal',
            'type' => LiveSessionType::WEBINAR->value,
            'start_at' => now()->subMinutes(5)->toIso8601String(),
            'end_at' => now()->addHour()->toIso8601String(),
            'meeting_url' => 'https://meet.example.com/batal',
        ], $teacher);

        $live->publish($session, $teacher);
        $live->cancel($session->fresh(), 'Guru berhalangan', $teacher);

        $info = $live->joinInfo($session->fresh(), $this->actorFor($world['studentUsers'][0]));

        $this->assertFalse($info['joinable']);
        $this->assertStringContainsString('dibatalkan', (string) $info['reason']);
        $this->assertNull($info['url']);
        $this->assertSame('CANCELLED', $session->fresh()->status->value);
    }

    public function test_sesi_belum_terbit_tidak_terlihat_siswa(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $live->create($course, [
            'title' => 'Draf Sesi',
            'start_at' => now()->addDay()->toIso8601String(),
        ], $teacher);

        $this->assertSame([], $live->forCourse($course, false));
        $this->assertCount(1, $live->forCourse($course, true));
    }

    public function test_provider_pertemuan_tanpa_kredensial_tetap_menyimpan_tautan(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));

        $session = app(LiveSessionService::class)->create($course, [
            'title' => 'Kelas via Zoom',
            'provider' => 'ZOOM',
            'start_at' => now()->addHour()->toIso8601String(),
            'meeting_url' => 'https://zoom.example.com/j/123',
            'host_url' => 'https://zoom.example.com/s/123',
            'passcode' => 'rahasia',
        ], $teacher);

        app(LiveSessionService::class)->publish($session, $teacher);

        $this->assertSame('ZOOM', $session->provider->value);

        // Provider bawaan hanya menyimpan tautan — tidak ada klaim kehadiran otomatis.
        $sync = app(LiveSessionService::class)->syncProviderAttendance($session->fresh(), $teacher);
        $this->assertFalse($sync['supported']);
        $this->assertSame([], $sync['saved']);

        $live = app(LiveSessionService::class);

        $this->assertCount(1, $live->forCourse($course, false));

        $reason = (string) $live->joinInfo($session->fresh(), $this->actorFor($world['studentUsers'][0]))['reason'];

        $this->assertStringContainsString('Tautan gabung aktif', $reason, 'Tautan baru terbuka pada jendela gabung.');
        $this->assertStringNotContainsString('rahasia', json_encode($live->forCourse($course, false)), 'Passcode tidak pernah masuk payload siswa.');
    }

    public function test_sesi_yang_belum_diisi_tautan_tidak_dapat_digabung(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $session = $live->create($course, [
            'title' => 'Sesi Tanpa Tautan',
            'start_at' => now()->subMinute()->toIso8601String(),
            'end_at' => now()->addHour()->toIso8601String(),
        ], $teacher);

        $live->publish($session, $teacher);

        $info = $live->joinInfo($session->fresh(), $this->actorFor($world['studentUsers'][0]));

        $this->assertFalse($info['joinable']);
        $this->assertStringContainsString('Tautan', (string) $info['reason']);
    }

    public function test_kehadiran_guru_tidak_pernah_diberikan_oleh_siswa_lain(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $this->enroll($course, $world['studentUsers'][0]);
        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $session = $live->create($course, [
            'title' => 'Sesi Terbatas',
            'start_at' => now()->subMinute()->toIso8601String(),
            'end_at' => now()->addHour()->toIso8601String(),
            'meeting_url' => 'https://meet.example.com/terbatas',
        ], $teacher);

        $live->publish($session, $teacher);

        // Siswa yang tidak terdaftar pada course tidak boleh bergabung.
        $outsider = $this->actorFor($world['studentUsers'][2]);
        $info = $live->joinInfo($session->fresh(), $outsider);

        $this->assertFalse($info['joinable']);
        $this->assertStringContainsString('tidak terdaftar', (string) $info['reason']);
    }

    public function test_publisher_mengirim_pemberitahuan_ke_peserta(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));

        $session = app(LiveSessionService::class)->create($course, [
            'title' => 'Sesi Pemberitahuan',
            'start_at' => now()->addHours(3)->toIso8601String(),
            'meeting_url' => 'https://meet.example.com/notif',
        ], $teacher);

        app(LiveSessionService::class)->publish($session, $teacher);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $world['studentUsers'][0]->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_sesi_lampau_ditutup_otomatis_dan_rekap_kehadiran_kosong_dapat_dideteksi(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $live = app(LiveSessionService::class);

        $session = $live->create($course, [
            'title' => 'Sesi Lampau',
            'start_at' => now()->subHours(3)->toIso8601String(),
            'end_at' => now()->subHours(2)->toIso8601String(),
            'meeting_url' => 'https://meet.example.com/lampau',
        ], $teacher);

        $live->publish($session, $teacher);

        $this->assertSame('COMPLETED', $live->refreshStatus($session->fresh())->status->value);
        $this->assertSame(1, $live->pendingAttendanceCount($course), 'Sesi selesai tanpa rekap terdeteksi sebagai tugas guru.');
    }
}
