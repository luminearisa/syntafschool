<?php

namespace App\Modules\Supervisi\Database\Seeders\Concerns;

use App\Models\User;
use App\Modules\Supervisi\Application\Services\InstrumentBuilderService;
use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use App\Modules\Supervisi\Models\SupGradeScale;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupInstrument;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupSection;

/**
 * Pembantu penyusunan instrumen bawaan.
 *
 * Dipakai seeder agar setiap butir cukup ditulis sekali sebagai kalimat +
 * panduan penilaian, tanpa mengulang detail teknis kolom database.
 */
trait BuildsInstruments
{
    /** Opsi baku untuk skala 1–4 (Kemendikbud). */
    protected function scale14(): array
    {
        return [
            ['label' => 'Kurang', 'score' => 1, 'tone' => 'RED', 'descriptor' => 'Belum tampak / belum dilakukan.'],
            ['label' => 'Cukup', 'score' => 2, 'tone' => 'AMBER', 'descriptor' => 'Dilakukan sebagian, masih ada kekurangan.'],
            ['label' => 'Baik', 'score' => 3, 'tone' => 'GREEN', 'descriptor' => 'Dilakukan dengan baik dan sesuai tuntutan.'],
            ['label' => 'Amat Baik', 'score' => 4, 'tone' => 'GREEN', 'descriptor' => 'Dilakukan sangat baik, konsisten, dan menjadi teladan.'],
        ];
    }

    /** Opsi baku untuk skala 0–2 (telaah dokumen/RPP). */
    protected function scale02(): array
    {
        return [
            ['label' => 'Tidak ada', 'score' => 0, 'tone' => 'RED', 'descriptor' => 'Komponen tidak ditemukan.'],
            ['label' => 'Ada, kurang lengkap', 'score' => 1, 'tone' => 'AMBER', 'descriptor' => 'Komponen ada tetapi belum lengkap/tepat.'],
            ['label' => 'Ada dan lengkap', 'score' => 2, 'tone' => 'GREEN', 'descriptor' => 'Komponen lengkap, jelas, dan dapat dilaksanakan.'],
        ];
    }

    /** Opsi baku untuk skala 1–5 (tinjauan kinerja pegawai). */
    protected function scale15(): array
    {
        return [
            ['label' => 'Tidak memenuhi', 'score' => 1, 'tone' => 'RED'],
            ['label' => 'Perlu perbaikan', 'score' => 2, 'tone' => 'RED'],
            ['label' => 'Sesuai harapan', 'score' => 3, 'tone' => 'AMBER'],
            ['label' => 'Melebihi harapan', 'score' => 4, 'tone' => 'GREEN'],
            ['label' => 'Istimewa', 'score' => 5, 'tone' => 'GREEN'],
        ];
    }

    /** Buat instrumen + draf versi pertama. */
    protected function instrument(array $meta): SupInstrumentVersion
    {
        $builder = app(InstrumentBuilderService::class);
        $actor = User::query()->orderBy('id')->first();

        $instrument = SupInstrument::query()->updateOrCreate(
            ['code' => $meta['code']],
            [
                'name' => $meta['name'],
                'category' => $meta['category'],
                'target_type' => $meta['target_type'],
                'description' => $meta['description'] ?? null,
                'is_system' => true,
                'is_active' => true,
            ],
        );

        if ($instrument->versions()->where('status', 'PUBLISHED')->exists()) {
            return $instrument->versions()->where('status', 'PUBLISHED')->orderByDesc('version')->firstOrFail();
        }

        $version = $builder->createVersion($instrument, [
            'scoring_mode' => $meta['scoring_mode'] ?? 'PERCENT',
            'scale_type' => $meta['scale_type'] ?? 'SCALE_1_4',
            'grade_scale_id' => SupGradeScale::query()->where('code', $meta['grade_scale'] ?? 'kemendikbud_90_80_70')->value('id'),
            'requires_action_plan' => $meta['requires_action_plan'] ?? true,
            'allows_multi_source' => $meta['allows_multi_source'] ?? false,
            'guidance' => $meta['guidance'] ?? null,
            'change_note' => 'Instrumen bawaan modul supervisi',
        ], $actor);

        return $version;
    }

    protected function section(SupInstrumentVersion $version, string $code, string $title, ?string $description = null, float $weight = 1): SupSection
    {
        return $version->sections()->create([
            'code' => $code,
            'title' => $title,
            'description' => $description,
            'weight' => $weight,
            'sort_order' => $version->sections()->count(),
        ]);
    }

    /**
     * Indikator berskala (pilihan rubrik).
     *
     * @param  list<array<string,mixed>>  $options
     * @param  array<string,mixed>  $extra
     */
    protected function scale(SupSection $section, string $statement, string $guidance, array $options, array $extra = []): SupIndicator
    {
        return $this->indicator($section, $statement, $guidance, [
            'input_type' => IndicatorInputType::SCALE->value,
            'options' => $options,
            ...$extra,
        ]);
    }

    protected function yesNo(SupSection $section, string $statement, string $guidance, array $extra = []): SupIndicator
    {
        return $this->indicator($section, $statement, $guidance, [
            'input_type' => IndicatorInputType::BOOLEAN->value,
            'score_yes' => 1,
            'score_no' => 0,
            'score_partial' => null,
            ...$extra,
        ]);
    }

    /** Indikator bertipe angka langsung (bukan pilihan skala). */
    protected function number(SupSection $section, string $statement, string $guidance, array $params, array $extra = []): SupIndicator
    {
        return $this->indicator($section, $statement, $guidance, [
            'input_type' => IndicatorInputType::NUMBER->value,
            ...$params,
            ...$extra,
        ]);
    }

    protected function narrative(SupSection $section, string $statement, string $guidance, array $extra = []): SupIndicator
    {
        return $this->indicator($section, $statement, $guidance, [
            'input_type' => IndicatorInputType::LONG_TEXT->value,
            'is_scored' => false,
            'is_required' => true,
            ...$extra,
        ]);
    }

    /** @param array<string,mixed> $data */
    protected function indicator(SupSection $section, string $statement, string $guidance, array $data): SupIndicator
    {
        return app(InstrumentBuilderService::class)->createIndicator($section, [
            'statement' => $statement,
            'guidance' => $guidance,
            'min_comment_below' => $data['min_comment_below'] ?? 2,
            ...$data,
        ], $section->indicators()->count());
    }

    /** Terbitkan versi (checksum + skor maksimum dihitung server). */
    protected function publishVersion(SupInstrumentVersion $version): void
    {
        app(InstrumentBuilderService::class)->publish($version, User::query()->orderBy('id')->first());
    }
}
