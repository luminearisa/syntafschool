<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Database\Seeders\SupInstrumentSeeder;

/**
 * Pengembangan kompetensi, coaching, dan PIP: rantai pembinaan setelah
 * penilaian kinerja rendah.
 */
class SupPipAndGrowthTest extends SupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupInstrumentSeeder::class);
    }

    /** @return array{0: Employee, 1: User, 2: Employee, 3: User} */
    protected function actors(): array
    {
        $head = $this->employee(['full_name' => 'Hj. Siti Rahmawati', 'position' => 'Kepala Sekolah']);
        $headUser = $this->employeeWithAccount($head, 'kepala-sekolah');

        $teacher = $this->employee(['full_name' => 'Ahmad Fauzi', 'position' => 'Guru Biologi']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');

        return [$head, $headUser, $teacher, $teacherUser];
    }

    public function test_pencatatan_pengembangan_kompetensi_dan_ringkasan_jam(): void
    {
        [, $headUser, $teacher] = $this->actors();

        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/developments', [
            'supervisee_employee_id' => $teacher->id,
            'kind' => 'DIKLAT',
            'title' => 'Diklat Pembelajaran Berdiferensiasi',
            'provider' => 'BBGP Jawa Barat',
            'hours' => 32,
            'started_at' => now()->subDays(30)->toDateString(),
            'finished_at' => now()->subDays(27)->toDateString(),
            'cost' => 750000,
            'certificate_no' => 'BBGP/2026/1234',
            'status' => 'DONE',
        ])->assertCreated();

        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/developments', [
            'supervisee_employee_id' => $teacher->id,
            'kind' => 'WORKSHOP',
            'title' => 'Workshop Asesmen Formatif',
            'hours' => 8,
            'status' => 'PLANNED',
        ])->assertCreated();

        $list = $this->actingAsUser($headUser)
            ->getJson("/api/v1/supervisi/developments?employee_id={$teacher->id}")
            ->assertOk();

        $this->assertEquals(40.0, $list->json('data.summary.total_hours'));
        $this->assertEquals(32.0, $list->json('data.summary.hours_by_kind.DIKLAT'));
        $this->assertEquals(8.0, $list->json('data.summary.hours_by_kind.WORKSHOP'));
        $this->assertEquals(1, $list->json('data.summary.certified'));
        $this->assertEquals(1, $list->json('data.summary.completed'));
        $this->assertEquals(1, $list->json('data.summary.planned'));
        $this->assertEquals(750000.0, $list->json('data.summary.total_cost'));

        // Jenis pengembangan tidak dikenal ditolak.
        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/developments', [
            'supervisee_employee_id' => $teacher->id,
            'kind' => 'LIBURAN',
            'title' => 'Bukan pengembangan',
        ])->assertStatus(422);
    }

    public function test_pengembangan_hanya_terlihat_oleh_pemilik_dan_pencatat(): void
    {
        [$head, $headUser, $teacher, $teacherUser] = $this->actors();

        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/developments', [
            'supervisee_employee_id' => $teacher->id,
            'kind' => 'MANDIRI',
            'title' => 'Belajar mandiri literasi digital',
            'hours' => 6,
            'status' => 'DONE',
        ])->assertCreated();

        // Guru yang bersangkutan melihat catatannya.
        $milik = $this->actingAsUser($teacherUser)
            ->getJson("/api/v1/supervisi/developments?employee_id={$teacher->id}")
            ->assertOk();

        $this->assertEquals(6.0, $milik->json('data.summary.total_hours'));

        // Guru lain tidak melihat catatan tersebut.
        $lain = $this->employee(['full_name' => 'Pak Guru Lain']);
        $lainUser = $this->employeeWithAccount($lain, 'guru');

        $asing = $this->actingAsUser($lainUser)
            ->getJson('/api/v1/supervisi/developments')
            ->assertOk();

        $this->assertSame([], $asing->json('data.rows'));
        $this->assertEquals(0.0, $asing->json('data.summary.total_hours'));

        // Guru juga tidak boleh mencatat pengembangan untuk pegawai lain.
        $this->actingAsUser($lainUser)->postJson('/api/v1/supervisi/developments', [
            'supervisee_employee_id' => $teacher->id,
            'kind' => 'MANDIRI',
            'title' => 'Mencatat atas nama orang lain',
        ])->assertStatus(403);

        $this->assertNotSame($head->id, $lain->id);
    }

    public function test_coaching_mencatat_komitmen_dan_tenggat_tindak_lanjut(): void
    {
        [$head, $headUser, $teacher, $teacherUser] = $this->actors();

        $sessionId = (int) $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/coachings', [
            'supervisee_employee_id' => $teacher->id,
            'focus' => 'Pengelolaan kelas pada jam terakhir',
            'commitments' => ['Menyiapkan ice breaking', 'Membuat kesepakatan kelas'],
        ])->assertCreated()->json('data.id');

        $list = $this->actingAsUser($headUser)
            ->getJson("/api/v1/supervisi/coachings?employee_id={$teacher->id}")
            ->assertOk();

        $row = $list->json('data.rows.0');
        $this->assertSame('OPEN', $row['status']);
        $this->assertCount(2, $row['commitments']);
        $this->assertSame($head->id, $row['supervisor']['id']);

        // Tenggat tindak lanjut bawaan 14 hari (coaching_follow_up_days).
        $this->assertSame(
            now()->addDays(14)->toDateString(),
            substr((string) $row['follow_up_at'], 0, 10),
        );

        $this->assertEquals(1, $list->json('data.open'));

        // Peserta boleh membaca sesinya sendiri, tetapi tidak boleh menutupnya.
        $this->actingAsUser($teacherUser)
            ->getJson('/api/v1/supervisi/coachings')
            ->assertOk();

        $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/coachings/{$sessionId}/close", ['note' => 'Saya tutup sendiri.'])
            ->assertStatus(403);

        $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/coachings/{$sessionId}/close", [
                'note' => 'Komitmen dijalankan, kelas lebih tenang.',
            ])
            ->assertOk();

        $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/coachings/{$sessionId}/close", ['note' => 'Dua kali.'])
            ->assertStatus(422);

        // Pembina tidak boleh menjadi peserta sesinya sendiri.
        $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/coachings', [
            'supervisee_employee_id' => $head->id,
            'focus' => 'Diri sendiri',
        ])->assertStatus(422);
    }

    public function test_pip_dari_penilaian_rendah_sampai_hasil_akhir(): void
    {
        [$head, $headUser, $teacher, $teacherUser] = $this->actors();

        // PKG dengan seluruh kompetensi bernilai 1 → 14/56 → 25 (Kurang).
        $appraisalId = (int) $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/appraisals', [
            'supervisee_employee_id' => $teacher->id,
            'appraisal_type' => 'PKG',
            'period_type' => 'SEMESTER',
            'period_start' => now()->startOfYear()->toDateString(),
            'period_end' => now()->endOfYear()->toDateString(),
            'assessor_employee_id' => $head->id,
        ])->assertCreated()->json('data.appraisal.id');

        $indicators = $this->actingAsUser($headUser)
            ->getJson("/api/v1/supervisi/appraisals/{$appraisalId}")
            ->assertOk()
            ->json('data.indicators');

        $this->actingAsUser($headUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", [
                'scores' => array_map(fn (array $row) => ['indicator_id' => $row['id'], 'score' => 1], $indicators),
            ])
            ->assertOk();

        $nilai = $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate")
            ->assertOk();

        $this->assertEquals(14.0, $nilai->json('data.appraisal.raw_score'));
        $this->assertEquals(25.0, $nilai->json('data.appraisal.final_score'));
        $this->assertSame('Kurang', $nilai->json('data.appraisal.grade_label'));

        // PIP dibuat dari dokumen penilaian tersebut; alasan terisi otomatis.
        $pipId = (int) $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/pips', [
            'appraisal_id' => $appraisalId,
            'expected_standard' => 'Seluruh kompetensi minimal skor 3 (Baik).',
            'support_provided' => 'Pendampingan mingguan dan contoh perangkat pembelajaran.',
            'final_review_date' => now()->addMonths(3)->toDateString(),
            'review_cadence' => 'WEEKLY',
            'items' => [[
                'statement' => 'Menyusun perangkat pembelajaran dengan pendampingan',
                'measure' => 'Jumlah perangkat tervalidasi',
                'target_value' => 4,
                'target_unit' => 'dokumen',
            ]],
        ])->assertCreated()->json('data.id');

        $detail = $this->actingAsUser($headUser)
            ->getJson("/api/v1/supervisi/pips/{$pipId}")
            ->assertOk();

        $this->assertSame('DRAFT', $detail->json('data.status'));
        $this->assertStringContainsStringIgnoringCase('Kurang', (string) $detail->json('data.reason'));
        $this->assertCount(1, $detail->json('data.items'));

        $this->actingAsUser($headUser)->postJson("/api/v1/supervisi/pips/{$pipId}/items", [
            'statement' => 'Mengikuti pendampingan pengelolaan kelas',
            'measure' => 'Jumlah sesi diikuti',
            'target_value' => 4,
            'target_unit' => 'sesi',
        ])->assertCreated();

        // Tinjauan pertama mengaktifkan PIP.
        $this->actingAsUser($headUser)->postJson("/api/v1/supervisi/pips/{$pipId}/reviews", [
            'progress_note' => 'Dua perangkat sudah tervalidasi.',
            'next_step' => 'Lanjut sesi pendampingan kelas',
        ])->assertCreated();

        $this->assertSame('ACTIVE', $this->actingAsUser($headUser)
            ->getJson("/api/v1/supervisi/pips/{$pipId}")
            ->assertOk()
            ->json('data.status'));

        // Diperpanjang tanpa tanggal tinjauan akhir baru → ditolak.
        $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/pips/{$pipId}/conclude", ['outcome' => 'EXTENDED'])
            ->assertStatus(422);

        // Dinyatakan membaik padahal butir belum selesai → ditolak.
        $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/pips/{$pipId}/conclude", ['outcome' => 'IMPROVED'])
            ->assertStatus(422);

        // Selesaikan seluruh butir.
        foreach ($this->actingAsUser($headUser)->getJson("/api/v1/supervisi/pips/{$pipId}")->json('data.items') as $item) {
            $this->actingAsUser($headUser)
                ->patchJson("/api/v1/supervisi/pips/items/{$item['id']}", [
                    'status' => 'DONE',
                    'result_note' => 'Selesai sesuai target.',
                ])
                ->assertOk();
        }

        $selesai = $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/pips/{$pipId}/conclude", [
                'outcome' => 'IMPROVED',
                'note' => 'Seluruh sasaran tercapai sebelum tenggat.',
            ])
            ->assertOk();

        $this->assertSame('IMPROVED', $selesai->json('data.outcome'));
        $this->assertSame('CLOSED', $selesai->json('data.status'));

        // Tanda tangan pemahaman oleh yang bersangkutan.
        $ack = $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/pips/{$pipId}/acknowledge")
            ->assertOk();

        $this->assertNotNull($ack->json('data.acknowledged_at'));

        // PIP tertutup tidak dapat diubah lagi.
        $this->actingAsUser($headUser)
            ->patchJson("/api/v1/supervisi/pips/{$pipId}", ['reason' => 'Diubah lagi'])
            ->assertStatus(422);
    }

    public function test_pip_orang_lain_tidak_dapat_dibuka(): void
    {
        [, $headUser, $teacher] = $this->actors();

        $pipId = (int) $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/pips', [
            'supervisee_employee_id' => $teacher->id,
            'reason' => 'Perlu perbaikan kedisiplinan administrasi.',
        ])->assertCreated()->json('data.id');

        $lain = $this->employee(['full_name' => 'Pak Guru Lain']);
        $lainUser = $this->employeeWithAccount($lain, 'guru');

        $this->actingAsUser($lainUser)
            ->getJson("/api/v1/supervisi/pips/{$pipId}")
            ->assertStatus(403);

        $list = $this->actingAsUser($lainUser)->getJson('/api/v1/supervisi/pips')->assertOk();
        $this->assertSame([], $list->json('data.rows'));
    }
}
