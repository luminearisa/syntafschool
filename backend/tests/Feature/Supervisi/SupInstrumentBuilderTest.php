<?php

namespace Tests\Feature\Supervisi;

use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupInstrument;
use App\Modules\Supervisi\Models\SupInstrumentVersion;

/**
 * Pembuat instrumen — inti permintaan “skala bisa, angka juga bisa”.
 */
class SupInstrumentBuilderTest extends SupTestCase
{
    public function test_metadata_menawarkan_tipe_input_skala_dan_angka(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $response = $this->getJson('/api/v1/supervisi/meta/instruments');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['value' => 'SCALE', 'is_scored' => true])
            ->assertJsonFragment(['value' => 'NUMBER', 'is_scored' => true])
            ->assertJsonFragment(['value' => 'LONG_TEXT', 'is_scored' => false]);

        $inputTypes = collect($response->json('data.input_types'))->pluck('value');
        $this->assertTrue($inputTypes->contains('BOOLEAN'));
        $this->assertTrue($inputTypes->contains('FILE'));

        $presets = collect($response->json('data.scale_presets'))->pluck('code');
        $this->assertTrue($presets->contains('SCALE_0_2'));
        $this->assertTrue($presets->contains('YES_NO'));
    }

    public function test_instrumen_dapat_mencampur_indikator_skala_dan_angka(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $created = $this->postJson('/api/v1/supervisi/instruments', [
            'name' => 'Instrumen Campuran',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'scoring_mode' => 'PERCENT',
        ])->assertCreated();

        $versionId = $created->json('data.version.id');

        $this->putJson("/api/v1/supervisi/instrument-versions/{$versionId}/structure", [
            'sections' => [[
                'code' => 'A',
                'title' => 'Bagian Utama',
                'indicators' => [
                    [
                        'statement' => 'Kualitas pembukaan pembelajaran',
                        'guidance' => 'Nilai 1–4 sesuai deskriptor rubrik.',
                        'input_type' => 'SCALE',
                        'options' => [
                            ['label' => 'Kurang', 'score' => 1, 'tone' => 'RED'],
                            ['label' => 'Cukup', 'score' => 2, 'tone' => 'AMBER'],
                            ['label' => 'Baik', 'score' => 3, 'tone' => 'GREEN'],
                            ['label' => 'Amat Baik', 'score' => 4, 'tone' => 'GREEN'],
                        ],
                    ],
                    [
                        'statement' => 'Ketepatan waktu penyelesaian administrasi (hari)',
                        'guidance' => 'Semakin sedikit semakin baik.',
                        'input_type' => 'NUMBER',
                        'score_min' => 0,
                        'score_max' => 20,
                        'score_step' => 1,
                        'score_unit' => 'hari',
                        'direction' => 'LOWER_BETTER',
                    ],
                ],
            ]],
        ])->assertOk();

        // Skor maksimum = 4 (skala) + 20 (angka), dihitung server.
        $this->assertSame(24.0, (float) SupInstrumentVersion::query()->findOrFail($versionId)->max_score);

        $checklist = $this->getJson("/api/v1/supervisi/instrument-versions/{$versionId}/checklist")->assertOk();
        $this->assertTrue($checklist->json('data.ready'), implode(' | ', $checklist->json('data.issues') ?? []));

        $published = $this->postJson("/api/v1/supervisi/instrument-versions/{$versionId}/publish")->assertOk();
        $this->assertSame('PUBLISHED', $published->json('data.status'));
        $this->assertSame(24.0, (float) $published->json('data.max_score'));
        $this->assertNotEmpty($published->json('data.checksum'));
    }

    public function test_versi_yang_sudah_terbit_tidak_bisa_diubah(): void
    {
        [$version] = $this->publishedScaleInstrument();

        $this->actingAsRole('kepala-sekolah');

        $this->putJson("/api/v1/supervisi/instrument-versions/{$version->id}/structure", [
            'sections' => [[
                'title' => 'Perubahan',
                'indicators' => [[
                    'statement' => 'Indikator baru',
                    'guidance' => 'Panduan',
                    'input_type' => 'SCALE',
                    'options' => [['label' => 'Ya', 'score' => 1]],
                ]],
            ]],
        ])->assertStatus(422);
    }

    public function test_checklist_menolak_instrumen_tanpa_indikator_berskor(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $versionId = $this->postJson('/api/v1/supervisi/instruments', [
            'name' => 'Instrumen Naratif',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
        ])->json('data.version.id');

        $this->putJson("/api/v1/supervisi/instrument-versions/{$versionId}/structure", [
            'sections' => [[
                'title' => 'Catatan',
                'indicators' => [[
                    'statement' => 'Catatan kunjungan',
                    'input_type' => 'LONG_TEXT',
                ]],
            ]],
        ])->assertOk();

        $this->postJson("/api/v1/supervisi/instrument-versions/{$versionId}/publish")
            ->assertStatus(422);

        $checklist = $this->getJson("/api/v1/supervisi/instrument-versions/{$versionId}/checklist");
        $this->assertFalse($checklist->json('data.ready'));
    }

    public function test_indikator_skala_wajib_memiliki_panduan_penilaian(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $versionId = $this->postJson('/api/v1/supervisi/instruments', [
            'name' => 'Tanpa Panduan',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
        ])->json('data.version.id');

        $this->putJson("/api/v1/supervisi/instrument-versions/{$versionId}/structure", [
            'sections' => [[
                'title' => 'Bagian',
                'indicators' => [[
                    'statement' => 'Indikator tanpa panduan',
                    'input_type' => 'SCALE',
                    'options' => [
                        ['label' => 'Kurang', 'score' => 1],
                        ['label' => 'Baik', 'score' => 2],
                    ],
                ]],
            ]],
        ])->assertOk();

        $checklist = $this->getJson("/api/v1/supervisi/instrument-versions/{$versionId}/checklist")->assertOk();
        $this->assertFalse($checklist->json('data.ready'));
        $this->assertStringContainsString('panduan penilaian', implode(' ', $checklist->json('data.issues')));
    }

    public function test_kloning_versi_membuat_draf_baru_dengan_struktur_sama(): void
    {
        [$version] = $this->publishedScaleInstrument();
        $this->actingAsRole('kepala-sekolah');

        $cloned = $this->postJson("/api/v1/supervisi/instrument-versions/{$version->id}/clone", [
            'change_note' => 'Revisi indikator tahun ini',
        ])->assertCreated();

        $this->assertSame('DRAFT', $cloned->json('data.status'));
        $this->assertSame(2, $cloned->json('data.version'));
        $this->assertSame((float) $version->max_score, (float) $cloned->json('data.max_score'));

        $structure = $this->getJson('/api/v1/supervisi/instrument-versions/'.$cloned->json('data.id'))->assertOk();
        $this->assertCount(1, $structure->json('data.structure'));
        $this->assertCount(1, $structure->json('data.structure.0.indicators'));
    }

    public function test_guru_tidak_boleh_mengubah_instrumen(): void
    {
        $this->actingAsRole('guru');

        $this->postJson('/api/v1/supervisi/instruments', [
            'name' => 'Percobaan Guru',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
        ])->assertStatus(403);

        $this->getJson('/api/v1/supervisi/instruments')->assertOk();
    }

    public function test_ekspor_dan_impor_definisi_instrumen(): void
    {
        [$version] = $this->publishedScaleInstrument();
        $this->actingAsRole('kepala-sekolah');

        $exported = $this->getJson("/api/v1/supervisi/instrument-versions/{$version->id}/export")->assertOk();

        $target = SupInstrument::query()->create([
            'code' => 'salinan-instrumen',
            'name' => 'Salinan Instrumen',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
        ]);

        $imported = $this->postJson("/api/v1/supervisi/instruments/import/{$target->id}", [
            'payload' => $exported->json('data'),
        ])->assertCreated();

        $this->assertSame('DRAFT', $imported->json('data.status'));
        $this->assertSame((float) $version->max_score, (float) $imported->json('data.max_score'));
    }

    /**
     * Instrumen skala sederhana yang sudah terbit.
     *
     * @return array{0:SupInstrumentVersion,1:SupIndicator}
     */
    protected function publishedScaleInstrument(): array
    {
        $this->actingAsRole('kepala-sekolah');

        $versionId = $this->postJson('/api/v1/supervisi/instruments', [
            'name' => 'Instrumen Skala',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
        ])->json('data.version.id');

        $this->putJson("/api/v1/supervisi/instrument-versions/{$versionId}/structure", [
            'sections' => [[
                'title' => 'Bagian Skala',
                'indicators' => [[
                    'statement' => 'Kejelasan tujuan pembelajaran',
                    'guidance' => 'Periksa apakah tujuan disampaikan dan dipahami peserta didik.',
                    'input_type' => 'SCALE',
                    'options' => [
                        ['label' => 'Kurang', 'score' => 1],
                        ['label' => 'Cukup', 'score' => 2],
                        ['label' => 'Baik', 'score' => 3],
                        ['label' => 'Amat Baik', 'score' => 4],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->postJson("/api/v1/supervisi/instrument-versions/{$versionId}/publish")->assertOk();

        $version = SupInstrumentVersion::query()->findOrFail($versionId);

        return [$version, $version->sections()->first()->indicators()->first()];
    }
}
