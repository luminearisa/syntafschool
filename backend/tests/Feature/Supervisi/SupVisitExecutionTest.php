<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitAnswer;
use Illuminate\Support\Facades\Storage;

/**
 * Pelaksanaan kegiatan supervisi: pembuatan, pengisian instrumen (skala &
 * angka), kelengkapan wajib, pengiriman, temuan, wawancara pasca-observasi,
 * finalisasi, dan pembekuan hasil.
 */
class SupVisitExecutionTest extends SupTestCase
{
    /**
     * Skenario standar: kepala sekolah (berwenang finalisasi) menilai satu guru.
     *
     * @return array{0: Employee, 1: User, 2: Employee, 3: User, 4: array<string,mixed>}
     */
    protected function scenario(string $kind = 'KEPALA_SEKOLAH'): array
    {
        $supervisor = $this->employee(['full_name' => 'Pak Supervisor', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $supervisor, 'kind' => $kind, 'can_finalize' => true]);
        $supervisorUser = $this->employeeWithAccount($supervisor, 'kepala-sekolah');

        $teacher = $this->employee(['full_name' => 'Bu Guru', 'position' => 'Guru Biologi']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');

        return [$supervisor, $supervisorUser, $teacher, $teacherUser, $this->publishedInstrument()];
    }

    /** Jawaban lengkap untuk instrumen uji. */
    protected function fullAnswers(array $instrument): array
    {
        $version = $instrument['version']->load('sections.indicators.options');
        $scale = $version->indicators->first(fn ($indicator) => $indicator->input_type->value === 'SCALE');
        $number = $version->indicators->first(fn ($i) => $i->input_type->value === 'NUMBER');
        $boolean = $version->indicators->first(fn ($i) => $i->input_type->value === 'BOOLEAN');
        $text = $version->indicators->first(fn ($i) => $i->input_type->value === 'LONG_TEXT');

        return [
            [
                'indicator_id' => $scale->id,
                'option_id' => $scale->options->firstWhere('score', 4.0)->id,
                'comment' => 'Tujuan disampaikan dengan contoh kontekstual.',
            ],
            ['indicator_id' => $number->id, 'value_number' => 8],
            ['indicator_id' => $boolean->id, 'value_text' => 'YES'],
            ['indicator_id' => $text->id, 'value_long_text' => 'Peserta didik aktif berdiskusi kelompok.'],
        ];
    }

    public function test_kegiatan_dibuat_diisi_dan_difinalkan(): void
    {
        [$supervisor, $supervisorUser, $teacher, $teacherUser, $instrument] = $this->scenario();

        // 1) Buat kegiatan dari instrumen yang sudah terbit.
        $created = $this->actingAsUser($supervisorUser)
            ->postJson('/api/v1/supervisi/visits', [
                'supervisee_employee_id' => $teacher->id,
                'instrument_version_id' => $instrument['version']->id,
                'activity_kind' => 'CLASS_OBSERVATION',
                'approach' => 'COLLABORATIVE',
                'lesson_topic' => 'Sistem Ekskresi',
                'observed_at' => now()->toIso8601String(),
            ])
            ->assertCreated();

        $visitId = $created->json('data.visit.id');
        $this->assertStringStartsWith('SUP/'.now()->year.'/', $created->json('data.visit.code'));
        $this->assertSame('SCHEDULED', $created->json('data.visit.status'));

        $visit = SupVisit::query()->findOrFail($visitId);
        $this->assertSame($instrument['version']->id, $visit->instrument_version_id);

        // 2) Isi seluruh indikator: skala rubrik + entri angka + ya/tidak + narasi.
        $saved = $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $this->fullAnswers($instrument)])
            ->assertOk();

        $this->assertSame(4, $saved->json('data.preview.summary.answered'));
        $this->assertEmpty($saved->json('data.preview.summary.missing_required'));
        // Maksimum = 4 (skala) + 10 (angka) + 1 (ya/tidak); narasi tidak berskor.
        $this->assertSame(15.0, (float) $saved->json('data.preview.summary.max'));

        // 3) Kirim untuk dianalisis.
        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/submit")
            ->assertOk()
            ->assertJsonPath('data.visit.status', 'ANALYZED');

        // 4) Wawancara pasca-observasi + temuan.
        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/conferences/POST", [
                'held_at' => now()->toIso8601String(),
                'agreements' => ['Menambah variasi pertanyaan pemantik'],
                'reflection' => 'Guru merasa perlu memperkuat asesmen formatif.',
                'answers' => [
                    ['question' => 'Bagaimana perasaan Anda setelah pembelajaran?', 'answer' => 'Lebih tenang karena alur jelas.'],
                ],
            ])
            ->assertOk();

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/findings", [
                'findings' => [
                    [
                        'component' => 'PELAKSANAAN',
                        'priority' => 'HIGH',
                        'strength' => 'Pengelolaan kelompok rapi.',
                        'weakness' => 'Pertanyaan pemantik masih terbatas pada ingatan.',
                        'problem' => 'Peserta didik cepat selesai dan menunggu.',
                        'cause' => 'Belum ada variasi tugas bertingkat.',
                        'recommendation' => 'Susun pertanyaan HOTS dan tugas bertingkat pada pertemuan berikutnya.',
                    ],
                ],
            ])
            ->assertOk();

        // 5) Finalisasi.
        $finalized = $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize", [
                'summary' => 'Pembelajaran berjalan efektif.',
                'strengths' => 'Struktur pembelajaran jelas.',
                'improvements' => 'Perkuat pertanyaan pemantik HOTS.',
            ])
            ->assertOk();

        $this->assertSame('COMPLETED', $finalized->json('data.visit.status'));
        $this->assertTrue($finalized->json('data.visit.is_finalized'));
        $this->assertNotNull($finalized->json('data.visit.grade_band'));
        $this->assertNotNull($finalized->json('data.visit.snapshot_checksum'));
        $this->assertTrue($finalized->json('data.acknowledgment.is_pending'));

        $visit = $visit->refresh();
        $this->assertSame(1, $visit->revision);
        // Skor mentah = 4 + 8 + 1 = 13 dari maksimum 15 (86,67 → predikat B).
        $this->assertSame(13.0, (float) $visit->raw_score);
        $this->assertNotEmpty($visit->snapshot['answers']);
        $this->assertSame('finalized', $visit->revisions()->first()->action);

        // 6) Hasil sudah beku: tidak bisa diisi lagi.
        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $this->fullAnswers($instrument)])
            ->assertStatus(422);
    }

    public function test_komentar_wajib_ketika_skor_di_bawah_ambang(): void
    {
        [$supervisor, $supervisorUser, $teacher, , $instrument] = $this->scenario();

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
        ])->assertCreated()->json('data.visit.id');

        $scale = $instrument['version']->load('sections.indicators.options')->indicators->first();

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", [
                'answers' => [[
                    'indicator_id' => $scale->id,
                    'option_id' => $scale->options->firstWhere('score', 1.0)->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.0.comment');

        // Dengan komentar, jawaban diterima.
        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", [
                'answers' => [[
                    'indicator_id' => $scale->id,
                    'option_id' => $scale->options->firstWhere('score', 1.0)->id,
                    'comment' => 'Tujuan tidak disebutkan sehingga peserta didik menebak.',
                ]],
            ])
            ->assertOk();
    }

    public function test_skor_dan_pilihan_dihitung_server_bukan_dari_klien(): void
    {
        [$supervisor, $supervisorUser, $teacher, , $instrument] = $this->scenario();

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
        ])->assertCreated()->json('data.visit.id');

        $version = $instrument['version']->load('sections.indicators.options');
        $scale = $version->indicators->first(fn ($i) => $i->input_type->value === 'SCALE');
        $number = $version->indicators->first(fn ($i) => $i->input_type->value === 'NUMBER');

        // Klien "menitip" skor 100 dan angka di luar rentang: harus ditolak/diabaikan.
        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", [
                'answers' => [[
                    'indicator_id' => $scale->id,
                    'option_id' => $scale->options->firstWhere('score', 3.0)->id,
                    'score' => 100,
                ]],
            ])
            ->assertOk();

        $stored = SupVisitAnswer::query()->where('visit_id', $visitId)->where('indicator_id', $scale->id)->firstOrFail();
        $this->assertSame(3.0, (float) $stored->score);

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", [
                'answers' => [['indicator_id' => $number->id, 'value_number' => 999]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.0.value_number');
    }

    public function test_indikator_wajib_baru_boleh_dikirim_setelah_lengkap(): void
    {
        [$supervisor, $supervisorUser, $teacher, , $instrument] = $this->scenario();

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
        ])->assertCreated()->json('data.visit.id');

        $version = $instrument['version']->load('sections.indicators.options');
        $scale = $version->indicators->first(fn ($i) => $i->input_type->value === 'SCALE');

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", [
                'answers' => [[
                    'indicator_id' => $scale->id,
                    'option_id' => $scale->options->firstWhere('score', 4.0)->id,
                ]],
            ])
            ->assertOk();

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');

        // Lengkapi lalu kirim.
        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $this->fullAnswers($instrument)])
            ->assertOk();

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/submit")
            ->assertOk()
            ->assertJsonPath('data.visit.status', 'ANALYZED');
    }

    public function test_finalisasi_menolak_bila_wawancara_pasca_belum_ada(): void
    {
        [$supervisor, $supervisorUser, $teacher, , $instrument] = $this->scenario();

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
            'activity_kind' => 'CLASS_OBSERVATION',
        ])->assertCreated()->json('data.visit.id');

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $this->fullAnswers($instrument)])
            ->assertOk();

        $response = $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")
            ->assertStatus(422);

        $this->assertStringContainsString('pasca-observasi', implode(' ', $response->json('errors.checklist')));
    }

    public function test_nilai_rendah_wajib_disertai_temuan(): void
    {
        [$supervisor, $supervisorUser, $teacher, , $instrument] = $this->scenario();

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
            'activity_kind' => 'WALKTHROUGH', // tidak mensyaratkan wawancara pasca
        ])->assertCreated()->json('data.visit.id');

        $version = $instrument['version']->load('sections.indicators.options');
        $scale = $version->indicators->first(fn ($i) => $i->input_type->value === 'SCALE');
        $boolean = $version->indicators->first(fn ($i) => $i->input_type->value === 'BOOLEAN');
        $number = $version->indicators->first(fn ($i) => $i->input_type->value === 'NUMBER');

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", [
                'answers' => [
                    [
                        'indicator_id' => $scale->id,
                        'option_id' => $scale->options->firstWhere('score', 1.0)->id,
                        'comment' => 'Tujuan tidak disampaikan.',
                    ],
                    ['indicator_id' => $number->id, 'value_number' => 2],
                    ['indicator_id' => $boolean->id, 'value_text' => 'NO'],
                ],
            ])
            ->assertOk();

        $blocked = $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")
            ->assertStatus(422);

        $this->assertStringContainsString('temuan', implode(' ', $blocked->json('errors.checklist')));

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/findings", [
                'findings' => [[
                    'component' => 'PERENCANAAN',
                    'priority' => 'URGENT',
                    'weakness' => 'Tujuan pembelajaran tidak dirumuskan.',
                    'recommendation' => 'Pendampingan penyusunan tujuan SMART.',
                ]],
            ])
            ->assertOk();

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")
            ->assertOk();
    }

    public function test_snapshot_tidak_berubah_walau_instrumen_direvisi(): void
    {
        [$supervisor, $supervisorUser, $teacher, , $instrument] = $this->scenario();

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
            'activity_kind' => 'WALKTHROUGH',
        ])->assertCreated()->json('data.visit.id');

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $this->fullAnswers($instrument)])
            ->assertOk();

        $finalized = $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")
            ->assertOk();

        $scoreBefore = (float) $finalized->json('data.visit.final_score');
        $checksumBefore = $finalized->json('data.visit.snapshot_checksum');
        $maxBefore = (float) $finalized->json('data.instrument.max_score');

        // Revisi instrumen: kloning versi baru, ubah bobot & maksimum, terbitkan.
        $builder = app(\App\Modules\Supervisi\Application\Services\InstrumentBuilderService::class);
        $draft = $builder->cloneVersion($instrument['version']->refresh(), $supervisorUser, 'Perubahan skala');

        $sections = $draft->load('sections.indicators.options')->sections->map(fn ($section) => [
            'title' => $section->title,
            'weight' => 5,
            'indicators' => $section->indicators->map(fn ($indicator) => [
                'statement' => $indicator->statement,
                'input_type' => $indicator->input_type->value,
                'guidance' => $indicator->guidance,
                'weight' => $indicator->weight,
                'score_max' => $indicator->score_max,
                'score_min' => $indicator->score_min,
                'score_yes' => $indicator->score_yes,
                'score_no' => $indicator->score_no,
                'min_comment_below' => $indicator->min_comment_below,
                'options' => $indicator->options->map(fn ($option) => [
                    'label' => $option->label,
                    'score' => $option->score * 2,
                    'tone' => $option->tone->value,
                ])->all(),
            ])->all(),
        ])->all();

        $builder->syncStructure($draft, $sections, $supervisorUser);
        $builder->publish($draft->refresh(), $supervisorUser);

        // Kegiatan lama tetap memakai versi & nilai yang dibekukan.
        $detail = $this->actingAsUser($supervisorUser)
            ->getJson("/api/v1/supervisi/visits/{$visitId}")
            ->assertOk();

        $this->assertSame($scoreBefore, (float) $detail->json('data.visit.final_score'));
        $this->assertSame($checksumBefore, $detail->json('data.visit.snapshot_checksum'));
        $this->assertSame($maxBefore, (float) $detail->json('data.instrument.max_score'));
        $this->assertSame((int) $instrument['version']->id, (int) SupVisit::query()->findOrFail($visitId)->instrument_version_id);
        $this->assertSame(1, (int) $detail->json('data.instrument.version')); // nomor versi beku saat finalisasi
    }

    public function test_pembukaan_ulang_memungkinkan_koreksi_nilai(): void
    {
        [$supervisor, $supervisorUser, $teacher, , $instrument] = $this->scenario();

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
            'activity_kind' => 'WALKTHROUGH',
        ])->assertCreated()->json('data.visit.id');

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $this->fullAnswers($instrument)])
            ->assertOk();

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")
            ->assertOk();

        $reopened = $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/reopen", ['reason' => 'Salah memilih opsi indikator pertama.'])
            ->assertOk();

        $this->assertSame('ANALYZED', $reopened->json('data.visit.status'));
        $this->assertFalse($reopened->json('data.visit.is_finalized'));
        $this->assertSame(2, $reopened->json('data.visit.revision'));

        $visit = SupVisit::query()->findOrFail($visitId);
        $this->assertSame('reopened', $visit->revisions()->first()->action);
        $this->assertSame('finalized', $visit->revisions()->reorder('id')->first()->action);

        // Setelah dibuka, jawaban dapat diperbaiki lagi.
        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $this->fullAnswers($instrument)])
            ->assertOk();
    }

    public function test_bukti_kegiatan_dapat_diunggah_dan_dihapus(): void
    {
        Storage::fake('public');

        [$supervisor, $supervisorUser, $teacher, , $instrument] = $this->scenario();

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
        ])->assertCreated()->json('data.visit.id');

        // Lingkungan uji tanpa ekstensi GD: cukup berkas gambar tiruan.
        $file = \Illuminate\Http\UploadedFile::fake()->create('kelas.jpg', 120, 'image/jpeg');

        $evidenceId = $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/evidence", [
                'file' => $file,
                'kind' => 'PHOTO',
                'caption' => 'Suasana diskusi kelompok',
            ])
            ->assertCreated()
            ->json('data.id');

        Storage::disk('public')->assertExists(
            \App\Modules\Supervisi\Models\SupVisitEvidence::query()->findOrFail($evidenceId)->path,
        );

        $this->actingAsUser($supervisorUser)
            ->deleteJson("/api/v1/supervisi/visits/{$visitId}/evidence/{$evidenceId}")
            ->assertOk();

        $this->assertDatabaseCount('sup_visit_evidence', 0);
    }
}
