<?php

namespace Tests\Feature\Bk;

use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Application\Services\BkCaseConferenceService;
use App\Modules\Bk\Application\Services\BkExternalReferralService;
use App\Modules\Bk\Application\Services\BkNoteService;
use App\Modules\Bk\Application\Services\BkSessionService;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\SessionMode;
use App\Modules\Bk\Domain\Enums\SessionStatus;
use App\Modules\Bk\Domain\Enums\SessionType;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseConference;
use App\Modules\Bk\Models\BkCaseNote;
use App\Modules\Bk\Models\BkCounselingSession;

/**
 * Matriks privasi Phase 2 (uji wajib §28).
 *
 * Yang diuji bukan tampilan, melainkan apakah server benar-benar menolak:
 * guru terhadap catatan kasus rahasia, wali terhadap konferensi kasus internal,
 * siswa terhadap catatan konselor, konselor A terhadap kasus di luar cakupannya,
 * dan unit sekolah A terhadap data unit B.
 */
class BkPhase2PrivacyMatrixTest extends BkPhase2TestCase
{
    public function test_guru_tidak_dapat_membuka_catatan_kasus_rahasia(): void
    {
        $family = $this->makeStudentWithFamily('Aisyah');
        $counselor = $this->makeCounselor();
        $teacher = $this->makeUserWithRole('guru', 'Ustadz Fauzan');

        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id, [
            'confidentiality_level' => ConfidentialityLevel::COUNSELOR_ONLY->value,
        ]);
        $this->assignCase($counselor, $case, $counselor);

        $session = BkCounselingSession::query()->create([
            'session_number' => 'BK-S-TEST-1',
            'case_id' => $case->id,
            'student_id' => $family['student']->id,
            'counselor_user_id' => $counselor->id,
            'session_type' => SessionType::INDIVIDUAL,
            'mode' => SessionMode::IN_PERSON,
            'status' => SessionStatus::COMPLETED,
            'scheduled_at' => now(),
            'confidentiality_level' => ConfidentialityLevel::COUNSELOR_ONLY,
            'counselor_note' => 'RAHASIA: membahas situasi keluarga.',
        ]);

        $note = BkCaseNote::query()->create([
            'case_id' => $case->id,
            'note_type' => 'COUNSELOR_NOTE',
            'body' => 'RAHASIA-KONSELOR: catatan pribadi konselor.',
            'confidentiality_level' => ConfidentialityLevel::COUNSELOR_ONLY,
            'is_sensitive' => true,
            'occurred_at' => now(),
            'created_by' => $counselor->id,
        ]);

        // Guru: ditolak pada kasus, sesi, dan catatan.
        $this->actAs($teacher);

        $this->getJson("/api/v1/bk/cases/{$case->id}")->assertForbidden();
        $this->getJson("/api/v1/bk/sessions/{$session->id}")->assertForbidden();
        $this->getJson("/api/v1/bk/cases/{$case->id}/notes")->assertForbidden();

        // Bahkan penolakan pun tercatat pada jejak audit.
        $this->actAs($counselor);
        $this->assertDatabaseHas('bk_audit_logs', ['action' => 'VIEW_CONFIDENTIAL', 'granted' => false]);
    }

    public function test_orang_tua_tidak_dapat_membuka_konferensi_kasus_internal(): void
    {
        $family = $this->makeStudentWithFamily('Bilal');
        $counselor = $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id);
        $this->assignCase($counselor, $case, $counselor);

        $conference = app(BkCaseConferenceService::class)->create($counselor, $case, [
            'title' => 'Konferensi internal kasus '.$case->case_number,
            'purpose' => 'Menyepakati bentuk dukungan.',
            'scheduled_start' => now()->addDay()->toDateTimeString(),
            'confidentiality_level' => ConfidentialityLevel::COUNSELOR_ONLY->value,
        ]);

        $this->actAs($family['guardian_user']);

        // Wali tidak diundang: daftar tidak memuatnya, rincian pun ditolak.
        $data = $this->getJson('/api/v1/bk/conferences')->assertOk()->json('data');
        $this->assertCount(0, $data, 'Wali tidak melihat konferensi internal.');

        $this->getJson("/api/v1/bk/conferences/{$conference->id}")->assertForbidden();
    }

    public function test_siswa_tidak_dapat_membuka_catatan_konselor(): void
    {
        $family = $this->makeStudentWithFamily('Tsabit');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id);
        $this->assignCase($counselor, $case, $counselor);

        $note = app(BkNoteService::class)->create($counselor, $case, [
            'note_type' => 'SENSITIVE',
            'body' => 'RAHASIA: isi catatan yang tidak boleh dibaca siswa.',
            'is_sensitive' => true,
        ]);

        $this->actAs($family['student_user']);

        $this->getJson("/api/v1/bk/cases/{$case->id}/notes")->assertForbidden();
        $this->assertFalse(app(\App\Modules\Bk\Application\Services\BkAccessService::class)
            ->canViewNote($family['student_user'], $note));
    }

    public function test_konselor_a_tidak_dapat_membuka_kasus_konselor_b(): void
    {
        $familyA = $this->makeStudentWithFamily('Hamzah');
        $familyB = $this->makeStudentWithFamily('Yusuf');

        $counselorA = $this->makeCounselor();
        $counselorB = $this->makeCounselor();

        $this->actAs($counselorB);
        $caseB = $this->makeOpenCase($counselorB, (int) $familyB['student']->id);
        $this->assignCase($counselorB, $caseB, $counselorB);

        $this->actAs($counselorA);

        // Kasus konselor lain tidak muncul pada daftar, dan rinciannya ditolak.
        $rows = $this->getJson('/api/v1/bk/cases')->assertOk()->json('data');
        $this->assertNotContains($caseB->id, array_column($rows, 'id'));

        $this->getJson("/api/v1/bk/cases/{$caseB->id}")->assertForbidden();
    }

    public function test_unit_sekolah_a_tidak_melihat_data_unit_sekolah_b(): void
    {
        $schoolA = $this->makeSchool('SMP Unit A');
        $schoolB = $this->makeSchool('SMP Unit B');

        $family = $this->makeStudentWithFamily('Salman');
        $coordinator = $this->makeCounselor('BK_ADMIN');

        $this->actAs($coordinator);

        $caseA = $this->asTenant((int) $schoolA->id, fn () => $this->makeOpenCase($coordinator, (int) $family['student']->id, ['title' => 'Kasus unit A']));
        $caseB = $this->asTenant((int) $schoolB->id, fn () => $this->makeOpenCase($coordinator, (int) $family['student']->id, ['title' => 'Kasus unit B']));

        $this->assertSame((int) $schoolA->id, (int) $caseA->fresh()->school_id);
        $this->assertSame((int) $schoolB->id, (int) $caseB->fresh()->school_id);

        // Dari unit A: hanya kasus unit A yang terlihat.
        $visibleForA = $this->asTenant((int) $schoolA->id, fn () => BkCase::query()->pluck('id')->all());
        $this->assertContains($caseA->id, $visibleForA);
        $this->assertNotContains($caseB->id, $visibleForA);

        // Dari unit B: sebaliknya.
        $visibleForB = $this->asTenant((int) $schoolB->id, fn () => BkCase::query()->pluck('id')->all());
        $this->assertContains($caseB->id, $visibleForB);
        $this->assertNotContains($caseA->id, $visibleForB);

        // Tanpa penyaringan unit, keduanya ada — membuktikan penyaringan bekerja,
        // bukan datanya yang tidak tersimpan.
        $all = BkCase::withoutSchoolScope()->pluck('id')->all();
        $this->assertContains($caseA->id, $all);
        $this->assertContains($caseB->id, $all);
    }

    public function test_konferensi_peserta_luar_tidak_memperoleh_akses_konseling(): void
    {
        $family = $this->makeStudentWithFamily('Rafiqa');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');
        $headTeacher = $this->makeUserWithRole('kepala-sekolah', 'Ustadz Kamil');
        $session = app(BkSessionService::class);

        $this->actAs($coordinator);
        $case = $this->makeOpenCase($coordinator, (int) $family['student']->id, [
            'confidentiality_level' => ConfidentialityLevel::CONFIDENTIAL->value,
        ]);
        $this->assignCase($coordinator, $case, $coordinator);

        $conference = app(BkCaseConferenceService::class)->create($coordinator, $case, [
            'title' => 'Konferensi lintas pihak',
            'participants' => [[
                'participant_type' => 'MANAGEMENT',
                'user_id' => $headTeacher->id,
            ]],
        ]);

        $participant = $conference->participants()->where('user_id', $headTeacher->id)->firstOrFail();

        // Hadir dalam rapat, tetapi tidak otomatis memegang catatan konseling.
        $this->assertFalse((bool) $participant->can_view_confidential);
        $this->assertNotSame(
            ConfidentialityLevel::CONFIDENTIAL->value,
            $participant->max_confidentiality->value,
            'Peserta dari manajemen tidak memperoleh langit-langit kerahasiaan kasus.',
        );
        $this->assertFalse(app(\App\Modules\Bk\Application\Services\BkAccessService::class)
            ->canViewCounselingContent($headTeacher, $case));

        // Peserta tetap dapat membuka konferensinya — itu memang haknya.
        $this->actAs($headTeacher);
        $this->getJson("/api/v1/bk/conferences/{$conference->id}")->assertOk();
    }
}
