<?php

namespace Tests\Feature\Activity;

use App\Modules\Activity\Application\Services\ActivityRegistrationService;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityCharge;
use App\Modules\Activity\Models\ActivityConsent;
use App\Modules\Activity\Models\ActivityRegistration;
use App\Modules\Activity\Models\ActivityWaitlistEntry;
use App\Modules\Activity\Models\ActivityMembership;

/**
 * Pendaftaran: syarat, kapasitas & daftar tunggu, persetujuan orang tua,
 * idempotensi, dan keputusan panitia.
 */
class ActivityRegistrationTest extends ActivityTestCase
{
    protected function service(): ActivityRegistrationService
    {
        return app(ActivityRegistrationService::class);
    }

    public function test_pendaftaran_terbuka_langsung_menjadi_anggota_dan_mencatat_pendaftaran(): void
    {
        $class = $this->makeClass('VII A');
        $student = $this->makeStudent('Dewi', $class);
        $studentUser = $this->accountFor('siswa', $student);
        $this->actAs($studentUser);

        $program = $this->makeProgram(['selection_method' => 'OPEN']);

        $registration = $this->service()->start($studentUser, $program, $student->id, ['motivation' => 'Ingin berlatih.']);

        $this->assertSame('APPROVED', $registration->status->value);
        $this->assertDatabaseHas('activity_memberships', [
            'program_id' => $program->id,
            'student_id' => $student->id,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('activity_registration_histories', [
            'registration_id' => $registration->id,
            'to_status' => 'APPROVED',
        ]);
    }

    public function test_siswa_tidak_dapat_mendaftarkan_orang_lain(): void
    {
        $class = $this->makeClass('VII A');
        $self = $this->makeStudent('Eka', $class);
        $other = $this->makeStudent('Fajar', $class);
        $selfUser = $this->accountFor('siswa', $self);
        $this->actAs($selfUser);

        $program = $this->makeProgram();

        $this->expectException(ActivityValidationException::class);

        $this->service()->start($selfUser, $program, $other->id);
    }

    public function test_mengirim_ulang_pendaftaran_yang_sama_tidak_membuat_baris_ganda(): void
    {
        $class = $this->makeClass('VII A');
        $student = $this->makeStudent('Gita', $class);
        $user = $this->accountFor('siswa', $student);
        $this->actAs($user);

        $program = $this->makeProgram();

        $first = $this->service()->start($user, $program, $student->id, ['idempotency_key' => 'kunci-1']);
        $second = $this->service()->start($user, $program, $student->id, ['idempotency_key' => 'kunci-1']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ActivityRegistration::query()->where('program_id', $program->id)->count());
    }

    public function test_kursi_penuh_membuat_pendaftaran_masuk_daftar_tunggu(): void
    {
        $service = $this->service();
        $program = $this->makeProgram(['maximum_capacity' => 1]);

        $first = $this->makeStudent('Hana');
        $second = $this->makeStudent('Irfan');

        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        $service->start($admin, $program, $first->id);

        $waitlisted = $service->start($admin, $program, $second->id);

        $this->assertSame('WAITLISTED', $waitlisted->status->value);
        $this->assertDatabaseHas('activity_waitlist_entries', [
            'registration_id' => $waitlisted->id,
            'position' => 1,
            'status' => 'WAITING',
        ]);
    }

    public function test_promosi_daftar_tunggu_idempoten_dan_mengisi_kursi_yang_kosong(): void
    {
        $service = $this->service();
        $memberships = app(\App\Modules\Activity\Application\Services\ActivityMembershipService::class);
        $program = $this->makeProgram(['maximum_capacity' => 1]);

        $first = $this->makeStudent('Joko');
        $second = $this->makeStudent('Kartika');

        // Keputusan anggota ada di tangan pengelola aktivitas, bukan admin katalog.
        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        $firstRegistration = $service->start($admin, $program, $first->id);
        $service->start($admin, $program, $second->id);

        // Kursi dibebaskan: anggota pertama berhenti.
        $membership = ActivityMembership::query()->where('registration_id', $firstRegistration->id)->firstOrFail();
        $withdrawal = $memberships->requestWithdrawal($membership, $admin, ['reason' => 'Pindah sekolah.']);
        $memberships->decideWithdrawal($withdrawal, $admin, 'APPROVE', 'Disetujui.');

        $this->assertDatabaseHas('activity_memberships', [
            'program_id' => $program->id,
            'student_id' => $second->id,
            'status' => 'ACTIVE',
        ]);

        $entry = ActivityWaitlistEntry::query()->where('student_id', $second->id)->firstOrFail();
        $this->assertSame('PROMOTED', $entry->status->value);

        // Menjalankan sapuan promosi lagi tidak boleh menambah keanggotaan kedua.
        $memberships->promoteWaitlistIfSeatAvailable($program->refresh());

        $this->assertSame(1, ActivityMembership::query()
            ->where('program_id', $program->id)
            ->where('student_id', $second->id)
            ->count());
    }

    public function test_syarat_persentase_kehadiran_minimum_menolak_pendaftaran(): void
    {
        $student = $this->makeStudent('Lina');
        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        $program = $this->makeProgram(['selection_method' => 'APPROVAL']);
        \App\Modules\Activity\Models\ActivityRegistrationRequirement::query()->create([
            'program_id' => $program->id,
            'type' => 'ATTENDANCE',
            'operator' => 'GTE',
            'value' => [95],
            'is_mandatory' => true,
        ]);

        // Tidak ada data kehadiran akademik → aturan dilewati, bukan menggagalkan.
        $registration = $this->service()->start($admin, $program, $student->id);

        $this->assertSame('SUBMITTED', $registration->status->value);
        $this->assertDatabaseHas('activity_registration_requirements', [
            'program_id' => $program->id,
            'type' => 'ATTENDANCE',
        ]);
    }

    public function test_persetujuan_orang_tua_menahan_keanggotaan_sampai_disetujui(): void
    {
        $class = $this->makeClass('VII A');
        $world = $this->makeStudentWithGuardian('Maya', $class);
        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        $program = $this->makeProgram([
            'selection_method' => 'APPROVAL',
            'requires_parent_consent' => true,
        ]);

        $registration = $this->service()->start($admin, $program, $world['student']->id);

        $this->assertSame('UNDER_REVIEW', $registration->status->value);
        $this->assertSame(1, ActivityConsent::query()->where('registration_id', $registration->id)->count());

        // Persetujuan diberikan wali lewat portal.
        $consent = ActivityConsent::query()->where('registration_id', $registration->id)->firstOrFail();
        $this->actAs($world['guardian_user']);

        $response = $this->postJson("/api/v1/activities/portal/consents/{$consent->id}/decide", [
            'decision' => 'APPROVED',
            'notes' => 'Setuju, anak saya ingin ikut.',
        ]);

        $response->assertOk();
        $this->assertSame('APPROVED', $consent->refresh()->decision->value);

        // Panitia baru dapat menerima setelah persetujuan ada.
        $this->actAs($admin);
        $decided = $this->service()->decide($registration->refresh(), $admin, 'APPROVE', []);

        $this->assertSame('APPROVED', $decided->status->value);
        $this->assertDatabaseHas('activity_memberships', ['student_id' => $world['student']->id, 'program_id' => $program->id]);
    }

    public function test_keputusan_tidak_dapat_ditimpa_setelah_final(): void
    {
        $student = $this->makeStudent('Nadia');
        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        $program = $this->makeProgram(['selection_method' => 'APPROVAL']);
        $registration = $this->service()->start($admin, $program, $student->id);

        $this->service()->decide($registration, $admin, 'REJECT', ['notes' => 'Kuota prioritas.']);

        $this->expectException(ActivityConflictException::class);

        $this->service()->decide($registration->refresh(), $admin, 'APPROVE', []);
    }

    public function test_aktivitas_berbiaya_mengirim_permintaan_tagihan_ke_keuangan(): void
    {
        $student = $this->makeStudent('Omar');
        $admin = $this->actingAsRole('ACTIVITY_MANAGER');

        $program = $this->makeProgram([
            'requires_payment' => true,
            'fee_amount' => 150000,
        ]);

        $this->service()->start($admin, $program, $student->id);

        $charge = ActivityCharge::query()->where('program_id', $program->id)->firstOrFail();

        // Bawaan konfigurasi: modul hanya meminta, tidak menagih sendiri.
        $this->assertSame('REQUESTED', $charge->status->value);
        $this->assertSame(150000.0, (float) $charge->amount);
        $this->assertDatabaseHas('activity_integration_logs', [
            'integration' => 'FINANCE',
            'action' => 'charge.registration',
            'status' => 'OK',
        ]);
    }

    public function test_melampaui_batas_jumlah_aktivitas_ditolak_dengan_alasan(): void
    {
        config(['activity.limits.max_active_activities' => 1]);

        $student = $this->makeStudent('Putri');
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');

        $first = $this->makeProgram(['code' => 'A-1', 'name' => 'Futsal']);
        $second = $this->makeProgram(['code' => 'B-1', 'name' => 'Basket']);

        $this->service()->start($manager, $first, $student->id);

        // Koordinator tidak memegang izin melampaui batas \u2014 inilah yang diuji di sini.
        $coordinator = $this->actingAsRole('ACTIVITY_COORDINATOR');

        try {
            $this->service()->start($coordinator, $second, $student->id);
            $this->fail('Pendaftaran kedua seharusnya ditolak.');
        } catch (ActivityValidationException $exception) {
            $this->assertStringContainsString('aktivitas aktif', implode(' ', $exception->errors()));
        }

        // Sedangkan pengelola aktivitas memang boleh melampaui batas (jalur pengecualian).
        $this->actAs($manager);
        $allowed = $this->service()->start($manager, $second, $student->id, ['allow_over_limit' => true]);

        $this->assertSame('APPROVED', $allowed->status->value);
    }
}
