<?php

namespace Tests\Feature\Activity;

use App\Modules\Activity\Application\Services\ActivityAchievementService;
use App\Modules\Activity\Application\Services\ActivityCertificateService;
use App\Modules\Activity\Application\Services\ActivityCompetitionService;
use App\Modules\Activity\Application\Services\ActivityPortfolioService;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Models\ActivityAchievement;
use App\Modules\Activity\Models\ActivityCompetition;
use App\Modules\Activity\Models\ActivityCompetitionMatch;
use App\Modules\Activity\Models\ActivityCompetitionParticipant;
use App\Modules\Activity\Models\ActivityCompetitionRound;
use App\Modules\Activity\Models\ActivityCompetitionScore;
use App\Modules\Activity\Models\ActivityCompetitionTeam;

/**
 * Kompetisi, juri, skor terkunci, hasil, prestasi, dan portofolio (\\u00a746\\u201351, \\u00a742\\u201345, \\u00a7102\\u2013103).
 */
class ActivityCompetitionAchievementTest extends ActivityTestCase
{
    /** @return array{competition: ActivityCompetition, category: int, service: ActivityCompetitionService, manager: \App\Models\User} */
    protected function competitionWithCategory(): array
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $service = app(ActivityCompetitionService::class);

        $competition = $service->create([
            'name' => 'Turnamen Futsal Antar Kelas',
            'level' => 'SCHOOL',
            'participant_type' => 'INDIVIDUAL',
            'organizer' => 'OSIS',
            'categories' => [
                ['code' => 'PUTRA', 'name' => 'Putra', 'maximum_participants' => 8],
            ],
        ], $manager);

        return [
            'competition' => $competition,
            'category' => (int) $competition->categories()->firstOrFail()->id,
            'service' => $service,
            'manager' => $manager,
        ];
    }

    public function test_pendaftaran_peserta_dibatasi_kuota_kategori(): void
    {
        $world = $this->competitionWithCategory();

        $world['service']->openRegistration($world['competition'], $world['manager']);

        $first = $this->makeStudent('Gandi');
        $this->makeStudent('Hendra');

        $participant = $world['service']->registerIndividual(
            $world['competition']->refresh(),
            (int) $first->id,
            $world['category'],
            $world['manager'],
        );

        $this->assertSame('REGISTERED', $participant->status->value);
        $this->assertSame(1, ActivityCompetitionParticipant::query()->where('competition_id', $world['competition']->id)->count());
    }

    public function test_pendaftaran_di_luar_jendela_waktu_ditolak(): void
    {
        $world = $this->competitionWithCategory(); // status masih DRAFT
        $student = $this->makeStudent('Indra');

        $this->expectException(ActivityConflictException::class);

        $world['service']->registerIndividual($world['competition'], (int) $student->id, $world['category'], $world['manager']);
    }

    public function test_tim_tidak_boleh_melebihi_batas_anggota_kategori(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $service = app(ActivityCompetitionService::class);

        $competition = $service->create([
            'name' => 'Lomba Cerdas Cermat',
            'participant_type' => 'TEAM',
            'categories' => [['code' => 'SMP', 'name' => 'SMP', 'maximum_team_size' => 2]],
        ], $manager);

        $category = $competition->categories()->firstOrFail();

        $members = [
            ['student_id' => $this->makeStudent('Jaka')->id],
            ['student_id' => $this->makeStudent('Kiki')->id],
            ['student_id' => $this->makeStudent('Lulu')->id],
        ];

        $this->expectException(\App\Modules\Activity\Domain\Exceptions\ActivityValidationException::class);

        $service->createTeam($competition, [
            'name' => 'Tim A',
            'category_id' => $category->id,
            'members' => $members,
        ], $manager);
    }

    public function test_skor_juri_mengunci_hasil_dan_menolak_penyuntingan_diam_diam(): void
    {
        $world = $this->competitionWithCategory();
        $world['service']->openRegistration($world['competition'], $world['manager']);

        $studentA = $this->makeStudent('Mira');
        $studentB = $this->makeStudent('Nino');

        $participantA = $world['service']->registerIndividual($world['competition']->refresh(), (int) $studentA->id, $world['category'], $world['manager']);
        $participantB = $world['service']->registerIndividual($world['competition']->refresh(), (int) $studentB->id, $world['category'], $world['manager']);

        $round = $world['service']->createRound($world['competition'], ['type' => 'FINAL', 'name' => 'Final'], $world['manager']);
        $match = $world['service']->createMatch($round, [
            'participant_a_id' => $participantA->id,
            'participant_b_id' => $participantB->id,
        ], $world['manager']);

        $judgeUser = $this->actingAsRole('ACTIVITY_JUDGE');
        $world['service']->assignJudge($world['competition'], ['user_id' => $judgeUser->id, 'name' => 'Juri 1'], $world['manager']);

        $this->actAs($judgeUser);
        $world['service']->submitScore($match, $judgeUser, ['participant_id' => $participantA->id, 'total' => 90, 'comment' => 'Kontrol bola baik.']);
        $world['service']->submitScore($match, $judgeUser, ['participant_id' => $participantB->id, 'total' => 80]);

        $locked = $world['service']->lockMatchScores($match->refresh(), $world['manager']);
        $this->assertSame(2, $locked);

        $match->refresh();
        $this->assertSame(90.0, (float) $match->score_a);
        $this->assertSame(80.0, (float) $match->score_b);
        $this->assertSame((int) $participantA->id, (int) $match->winner_participant_id);
        $this->assertSame('COMPLETED', $match->status->value);

        // Menyunting skor terkunci ditolak \\u2014 koreksi harus bersebab.
        $this->actAs($judgeUser);
        $score = ActivityCompetitionScore::query()->where('match_id', $match->id)->where('participant_id', $participantA->id)->firstOrFail();

        try {
            $world['service']->submitScore($match, $judgeUser, ['participant_id' => $participantA->id, 'total' => 95]);
            $this->fail('Skor terkunci seharusnya tidak dapat ditimpa.');
        } catch (ActivityConflictException $exception) {
            $this->assertStringContainsString('dikunci', $exception->getMessage());
        }

        $correction = $world['service']->correctScore($score, $world['manager'], [
            'reason' => 'Salah memasukkan angka.',
            'total' => 95,
        ]);

        // Satu baris skor tetap satu; nilai sebelum dan alasannya tersimpan di audit.
        $this->assertSame($score->id, $correction->id);
        $this->assertSame(95.0, (float) $correction->total);
        $this->assertSame('Salah memasukkan angka.', $correction->correction_reason);
        $this->assertSame(1, ActivityCompetitionScore::query()->where('match_id', $match->id)->where('participant_id', $participantA->id)->count());

        $audit = \App\Modules\Activity\Models\ActivityAuditLog::query()
            ->where('action', 'COMPETITION_SCORE_CORRECTED')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(90.0, (float) $audit->before['total']);
        $this->assertSame(95.0, (float) $audit->after['total']);
        $this->assertSame('Salah memasukkan angka.', $audit->metadata['reason']);
    }

    public function test_hasil_diterbitkan_lalu_menjadi_prestasi_dan_sertifikat(): void
    {
        $world = $this->competitionWithCategory();
        $world['service']->openRegistration($world['competition'], $world['manager']);

        // Kompetisi dilekatkan pada program berketerampilan agar prestasi
        // menghasilkan bukti keterampilan (bukan sekadar piagam).
        $program = $this->makeProgram(['code' => 'FUTSAL-COMP', 'name' => 'Futsal']);
        $skill = \App\Modules\Activity\Models\ActivitySkill::query()->where('code', 'TEAMWORK')->firstOrFail();
        $program->skills()->sync([$skill->id => ['is_required' => true, 'weight' => 1]]);
        $world['competition']->forceFill(['program_id' => $program->id])->save();

        $studentA = $this->makeStudent('Oki');
        $studentB = $this->makeStudent('Pandu');

        $participantA = $world['service']->registerIndividual($world['competition']->refresh(), (int) $studentA->id, $world['category'], $world['manager']);
        $participantB = $world['service']->registerIndividual($world['competition']->refresh(), (int) $studentB->id, $world['category'], $world['manager']);

        $round = $world['service']->createRound($world['competition'], ['type' => 'FINAL'], $world['manager']);
        $match = $world['service']->createMatch($round, [
            'participant_a_id' => $participantA->id,
            'participant_b_id' => $participantB->id,
        ], $world['manager']);

        $judgeUser = $this->actingAsRole('ACTIVITY_JUDGE');
        $world['service']->assignJudge($world['competition'], ['user_id' => $judgeUser->id], $world['manager']);

        $this->actAs($judgeUser);
        $world['service']->submitScore($match, $judgeUser, ['participant_id' => $participantA->id, 'total' => 88]);
        $world['service']->submitScore($match, $judgeUser, ['participant_id' => $participantB->id, 'total' => 70]);

        $this->actAs($world['manager']);
        $world['service']->lockMatchScores($match->refresh(), $world['manager']);

        $results = $world['service']->buildResults($world['competition']->refresh(), $world['manager']);
        $this->assertCount(2, $results);

        $published = $world['service']->publishResults($world['competition']->refresh(), $world['manager']);

        $this->assertSame(2, $published['results']);
        $this->assertCount(2, $published['achievements']);

        $achievement = ActivityAchievement::query()->where('student_id', $studentA->id)->firstOrFail();
        $this->assertSame('FIRST', $achievement->rank->value);
        $this->assertSame('SCHOOL', $achievement->level->value);

        // Verifikasi kepala sekolah, lalu sertifikat prestasi.
        $head = $this->actingAsRole('kepala-sekolah');
        $achievements = app(ActivityAchievementService::class);
        $achievements->verify($achievement, $head, 'VERIFIED', 'Sesuai berita acara.');

        $this->assertDatabaseHas('activity_skill_evidence', ['student_id' => $studentA->id, 'source' => 'ACHIEVEMENT']);

        $admin = $this->actingAsRole('ACTIVITY_ADMIN');
        $certificates = app(ActivityCertificateService::class)->issueForAchievement($achievement->refresh(), $admin);

        $this->assertCount(1, $certificates);
        $this->assertSame($achievement->title, $certificates[0]->title);

        // Portofolio siswa memuat prestasi & sertifikat tersebut.
        $portfolio = app(ActivityPortfolioService::class)->build((int) $studentA->id);

        $this->assertSame(1, $portfolio['summary']['achievements']);
        $this->assertSame(1, $portfolio['summary']['certificates']);
    }

    public function test_prestasi_tim_memberi_kredit_kepada_seluruh_anggota(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $service = app(ActivityCompetitionService::class);

        $competition = $service->create([
            'name' => 'Lomba Hadroh',
            'participant_type' => 'TEAM',
            'level' => 'CITY',
            'categories' => [['code' => 'UMUM', 'name' => 'Umum']],
        ], $manager);

        $category = $competition->categories()->firstOrFail();

        $members = [
            ['student_id' => $this->makeStudent('Qori')->id, 'role' => 'CAPTAIN'],
            ['student_id' => $this->makeStudent('Rafi')->id],
            ['student_id' => $this->makeStudent('Sasa')->id, 'receives_achievement' => false],
        ];

        $team = $service->createTeam($competition, [
            'name' => 'Tim Hadroh A',
            'category_id' => $category->id,
            'members' => $members,
        ], $manager);

        $achievements = app(ActivityAchievementService::class);

        $achievement = $achievements->create([
            'title' => 'Juara 2 Lomba Hadroh',
            'ownership' => 'TEAM',
            'team_id' => $team->id,
            'type' => 'ART',
            'level' => 'CITY',
            'rank' => 'SECOND',
            'awarded_date' => '2026-08-20',
        ], $manager);

        $this->assertSame(2, $achievement->members()->where('receives_credit', true)->count());
        $this->assertSame(3, $achievement->members()->count());

        $head = $this->actingAsRole('kepala-sekolah');
        $achievements->verify($achievement, $head, 'VERIFIED');

        // Anggota yang diberi kredit melihat prestasi ini di daftar prestasinya.
        $listed = $achievements->forStudent((int) $members[0]['student_id']);
        $this->assertSame('Juara 2 Lomba Hadroh', $listed[0]['title']);

        // Pemain cadangan yang tidak diberi kredit tidak melihatnya.
        $this->assertSame([], $achievements->forStudent((int) $members[2]['student_id']));
    }

    public function test_juri_hanya_melihat_penampilan_yang_ditugaskan_kepadanya(): void
    {
        $world = $this->competitionWithCategory();
        $world['service']->openRegistration($world['competition'], $world['manager']);

        $roundOne = $world['service']->createRound($world['competition'], ['type' => 'SEMI_FINAL', 'name' => 'Semifinal'], $world['manager']);
        $roundTwo = $world['service']->createRound($world['competition'], ['type' => 'FINAL', 'name' => 'Final'], $world['manager']);

        $world['service']->createMatch($roundOne, ['match_number' => 1], $world['manager']);
        $world['service']->createMatch($roundTwo, ['match_number' => 2], $world['manager']);

        $judgeUser = $this->actingAsRole('ACTIVITY_JUDGE');
        $judge = $world['service']->assignJudge($world['competition'], [
            'user_id' => $judgeUser->id,
            'round_id' => $roundTwo->id,
        ], $world['manager']);

        $assignments = $world['service']->matchesForJudge($judge->refresh());

        $this->assertCount(1, $assignments);
        $this->assertSame('Final', $assignments[0]['round']);
    }

    public function test_peserta_tidak_dapat_menilai_pertandingannya_sendiri(): void
    {
        $world = $this->competitionWithCategory();
        $world['service']->openRegistration($world['competition'], $world['manager']);

        $student = $this->makeStudent('Tono');
        $participant = $world['service']->registerIndividual($world['competition']->refresh(), (int) $student->id, $world['category'], $world['manager']);

        $round = $world['service']->createRound($world['competition'], ['type' => 'FINAL'], $world['manager']);
        $match = $world['service']->createMatch($round, ['participant_a_id' => $participant->id], $world['manager']);

        $outsider = $this->actingAsRole('ACTIVITY_JUDGE');

        $this->expectException(\App\Modules\Activity\Domain\Exceptions\ActivityValidationException::class);

        $world['service']->submitScore($match, $outsider, ['participant_id' => $participant->id, 'total' => 99]);
    }

    public function test_transkrip_aktivitas_berbeda_dari_transkrip_akademik(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $student = $this->makeStudent('Ujang', $this->makeClass('VIII A'));

        $program = $this->makeProgram(['code' => 'TRX-1', 'name' => 'English Club']);

        \App\Modules\Activity\Models\ActivityMembership::query()->create([
            'program_id' => $program->id,
            'period_id' => $this->period->id,
            'student_id' => $student->id,
            'role' => 'CAPTAIN',
            'status' => 'COMPLETED',
            'joined_at' => now()->subMonths(3)->toDateString(),
            'ended_at' => now()->toDateString(),
        ]);

        $transcript = app(ActivityPortfolioService::class)->transcript((int) $student->id);

        $this->assertSame('English Club', $transcript['rows'][0]['activity']);
        $this->assertSame('Ketua', $transcript['rows'][0]['role']);
        $this->assertSame(1, $transcript['totals']['completed']);
        $this->assertNotNull($manager->id);
    }
}
