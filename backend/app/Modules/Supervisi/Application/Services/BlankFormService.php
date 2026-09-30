<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupVisit;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Formulir observasi kosong untuk dicetak (mode luring).
 *
 * Observasi kelas terjadi di ruang yang sering tanpa sinyal: supervisor
 * mencetak formulir, menulis nilai di kelas, lalu memasukkannya ke aplikasi.
 * Formulir sengaja memakai **huruf pilihan** (A/B/C/…) supaya penulisan tangan
 * singkat, dan huruf itu dikenali saat sinkronisasi.
 *
 * Urutan kolom mengikuti versi instrumen yang dipakai kegiatan tersebut — bukan
 * template terbaru, agar formulir cetak dan perhitungan nilai memakai acuan
 * yang sama.
 */
class BlankFormService
{
    public function __construct(private readonly SupervisiSettings $settings)
    {
    }

    /** @return array{title:string,visit:array<string,mixed>,rows:list<array<string,mixed>>} */
    public function data(SupVisit $visit): array
    {
        $visit->loadMissing(['supervisee:id,full_name,position', 'supervisor:id,full_name', 'instrumentVersion.instrument:id,name,code']);

        $version = $visit->instrumentVersion;

        if ($version === null) {
            return [
                'title' => 'Formulir Observasi '.$visit->code,
                'visit' => $this->visitPayload($visit),
                'rows' => [],
            ];
        }

        $version->loadMissing('sections.indicators.options');

        $rows = [];
        $number = 0;

        foreach ($version->sections->sortBy('sort_order') as $section) {
            $rows[] = ['is_section' => true, 'label' => $section->title];

            foreach ($section->indicators->sortBy('sort_order') as $indicator) {
                $number++;
                $rows[] = $this->indicatorRow($indicator, $number);
            }
        }

        return [
            'title' => 'Formulir Observasi '.$visit->code,
            'visit' => $this->visitPayload($visit),
            'rows' => $rows,
        ];
    }

    /** Berkas PDF formulir kosong. */
    public function pdf(SupVisit $visit): string
    {
        $data = $this->data($visit);

        return Pdf::loadView('pdf.supervisi-blank-form', [
            'rows' => $data['rows'],
            'visit' => $data['visit'],
            'school' => [
                'name' => (string) config('app.school_name', config('app.name')),
                'address' => (string) config('app.school_address', ''),
            ],
        ])->setPaper('a4', 'portrait')->output();
    }

    /** Nama berkas unduhan yang aman. */
    public function filename(SupVisit $visit): string
    {
        return 'formulir-luring-'.str_replace('/', '-', $visit->code).'.pdf';
    }

    /** @return array<string,mixed> */
    protected function indicatorRow(SupIndicator $indicator, int $number): array
    {
        $options = $indicator->options->sortBy('sort_order')->values();

        return [
            'is_section' => false,
            'no' => $number,
            'label' => $indicator->statement,
            'guidance' => $indicator->guidance,
            'allow_na' => (bool) $indicator->allow_na,
            'hint' => match ($indicator->input_type) {
                IndicatorInputType::NUMBER => $indicator->score_min !== null || $indicator->score_max !== null
                    ? '('.rtrim(rtrim((string) ($indicator->score_min ?? 0), '0'), '.').'–'.rtrim(rtrim((string) ($indicator->score_max ?? 0), '0'), '.').')'
                    : '(angka)',
                IndicatorInputType::BOOLEAN => '(ya / tidak)',
                IndicatorInputType::SCALE => '(huruf)',
                default => '(tulis)',
            },
            'options' => $options->values()->map(fn ($option, int $index) => [
                'code' => chr(65 + $index),
                'label' => (string) $option->label,
                'score' => $this->score((float) $option->score),
            ])->all(),
        ];
    }

    /** @return array<string,mixed> */
    protected function visitPayload(SupVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'code' => $visit->code,
            'supervisee' => $visit->supervisee?->full_name,
            'supervisor' => $visit->supervisor?->full_name,
            'instrument' => $visit->instrumentVersion?->instrument?->name,
            'instrument_version' => $visit->instrumentVersion?->version,
            'observed_at' => $visit->observed_at?->translatedFormat('d F Y H:i'),
            'activity_kind' => $visit->activity_kind?->label(),
            'lesson_topic' => $visit->lesson_topic,
            // Ambang komentar diambil dari pengaturan sekolah yang sedang berlaku,
            // supaya catatan wajib sudah ditulis di kelas — bukan baru diingat
            // saat memasukkan nilai.
            'min_comment_percent' => $this->settings->int('require_comment_below_percent'),
        ];
    }

    protected function score(float $score): string
    {
        return rtrim(rtrim(number_format($score, 2, ',', '.'), '0'), ',');
    }
}
