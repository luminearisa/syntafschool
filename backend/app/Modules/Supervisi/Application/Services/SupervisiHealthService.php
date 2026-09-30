<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Modules\Supervisi\Domain\Enums\InstrumentVersionStatus;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use App\Modules\Supervisi\Models\SupActionItem;
use App\Modules\Supervisi\Models\SupCalibrationScore;
use App\Modules\Supervisi\Models\SupCalibrationSession;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupPipPlan;
use App\Modules\Supervisi\Models\SupReport;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pemeriksaan kesehatan & integritas modul supervisi.
 *
 * Kerusakan data supervisi tidak menimbulkan galat di layar — ia tampil sebagai
 * angka yang salah (nilai hilang, kegiatan final tanpa jawaban, berkas dokumen
 * yang tidak ada). Karena itu modul ini menyediakan satu tempat yang memeriksa
 * hal-hal tersebut: dapat dibuka lewat API (`GET /supervisi/health`) maupun
 * dijalankan `php artisan supervisi:health` untuk pemantauan berkala.
 *
 * Setiap temuan memuat jumlah, contoh id, **saran perbaikan**, dan status.
 * Tidak ada pemeriksaan yang mengubah data.
 */
class SupervisiHealthService
{
    /** Ambang kegentingan: `critical` menghentikan kepercayaan pada angka. */
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const OK = 'ok';

    /** @return array<string,mixed> */
    public function report(): array
    {
        $checks = [
            $this->finalWithoutAnswers(),
            $this->finalWithoutScore(),
            $this->answersOutsideInstrument(),
            $this->publishedInstrumentWithoutIndicators(),
            $this->calibrationNeedingConclusion(),
            $this->pipWithoutItems(),
            $this->missingReportFiles(),
            $this->inactiveSupervisorAssigned(),
            $this->settingsUsable(),
        ];

        $counts = ['critical' => 0, 'warning' => 0, 'ok' => 0];

        foreach ($checks as $check) {
            $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
        }

        $status = $counts[self::CRITICAL] > 0
            ? self::CRITICAL
            : ($counts[self::WARNING] > 0 ? self::WARNING : self::OK);

        return [
            'status' => $status,
            'summary' => [
                'checks' => count($checks),
                'ok' => $counts[self::OK],
                'warning' => $counts[self::WARNING],
                'critical' => $counts[self::CRITICAL],
                'needs_attention' => collect($checks)->whereIn('status', [self::WARNING, self::CRITICAL])->count(),
            ],
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Kegiatan yang sudah difinalkan tetapi tidak menyimpan satu pun jawaban. */
    protected function finalWithoutAnswers(): array
    {
        $ids = SupVisit::query()
            ->whereNotNull('finalized_at')
            ->whereDoesntHave('answers')
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('code')
            ->all();

        return $this->check(
            'visit_final_without_answers',
            'Kegiatan final tanpa jawaban',
            count($ids),
            $ids,
            self::CRITICAL,
            'Nilai kegiatan ini tidak dapat dipertanggungjawabkan. Buka kembali kegiatan lalu isi instrumen.',
        );
    }

    /** Kegiatan final yang nilai akhirnya kosong (perhitungan tidak jalan). */
    protected function finalWithoutScore(): array
    {
        $ids = SupVisit::query()
            ->whereNotNull('finalized_at')
            ->whereNull('final_score')
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('code')
            ->all();

        return $this->check(
            'visit_final_without_score',
            'Kegiatan final tanpa nilai akhir',
            count($ids),
            $ids,
            self::CRITICAL,
            'Buka kembali kegiatan agar mesin penilaian menghitung ulang, lalu finalkan kembali.',
        );
    }

    /** Jawaban yang indikatornya bukan bagian dari instrumen kegiatan (data yatim). */
    protected function answersOutsideInstrument(): array
    {
        $rows = SupVisitAnswer::query()
            ->join('sup_visits', 'sup_visits.id', '=', 'sup_visit_answers.visit_id')
            ->join('sup_indicators', 'sup_indicators.id', '=', 'sup_visit_answers.indicator_id')
            ->join('sup_sections', 'sup_sections.id', '=', 'sup_indicators.section_id')
            ->whereColumn('sup_sections.version_id', '!=', 'sup_visits.instrument_version_id')
            ->select('sup_visit_answers.id')
            ->orderByDesc('sup_visit_answers.id')
            ->limit(10)
            ->pluck('sup_visit_answers.id')
            ->all();

        return $this->check(
            'answers_outside_instrument',
            'Jawaban dari versi instrumen lain',
            count($rows),
            $rows,
            self::CRITICAL,
            'Jawaban tersebut tidak ikut dihitung dan menandakan versi instrumen berubah setelah pengisian. Periksa kegiatan terkait.',
        );
    }

    /** Instrumen terbit tanpa indikator: tidak mungkin dinilai. */
    protected function publishedInstrumentWithoutIndicators(): array
    {
        $ids = DB::table('sup_instrument_versions as v')
            ->leftJoin('sup_sections as s', 's.version_id', '=', 'v.id')
            ->leftJoin('sup_indicators as i', 'i.section_id', '=', 's.id')
            ->where('v.status', InstrumentVersionStatus::PUBLISHED->value)
            ->groupBy('v.id')
            ->havingRaw('count(i.id) = 0')
            ->pluck('v.id')
            ->all();

        return $this->check(
            'published_version_without_indicators',
            'Versi terbit tanpa indikator',
            count($ids),
            $ids,
            self::WARNING,
            'Lengkapi indikatornya sebelum dipakai, atau tarik versi tersebut dari peredaran.',
        );
    }

    /** Sesi kalibrasi yang selisihnya melewati ambang tetapi belum disepakati. */
    protected function calibrationNeedingConclusion(): array
    {
        $sessions = SupCalibrationSession::query()
            ->whereNull('finalized_at')
            ->with(['scores' => fn ($query) => $query->select('id', 'calibration_session_id', 'indicator_id', 'indicator_label', 'score')])
            ->limit(200)
            ->get();

        $ids = [];

        foreach ($sessions as $session) {
            $spreads = $session->scores
                ->groupBy(fn (SupCalibrationScore $score) => $score->indicator_id === null ? $score->indicator_label : 'i:'.$score->indicator_id)
                ->map(fn ($group) => (float) $group->max('score') - (float) $group->min('score'));

            if ($spreads->contains(fn (float $spread) => $spread > (float) $session->threshold)) {
                $ids[] = $session->title;
            }
        }

        return $this->check(
            'calibration_pending_conclusion',
            'Kalibrasi melewati ambang tanpa kesepakatan',
            count($ids),
            $ids,
            self::WARNING,
            'Selisih antar penilai belum disepakati; nilai dari periode ini berpotensi tidak sebanding antar kelas.',
        );
    }

    /** PIP berjalan tanpa satu pun butir perbaikan. */
    protected function pipWithoutItems(): array
    {
        $ids = SupPipPlan::query()
            ->whereIn('status', ['ACTIVE', 'ACKNOWLEDGED', 'IN_PROGRESS'])
            ->whereDoesntHave('items')
            ->limit(10)
            ->pluck('id')
            ->all();

        return $this->check(
            'pip_without_items',
            'PIP berjalan tanpa butir perbaikan',
            count($ids),
            $ids,
            self::WARNING,
            'Rencana tanpa butir tidak dapat ditindaklanjuti; lengkapi butir sasaran dan tenggatnya.',
        );
    }

    /** Berkas dokumen yang barisnya ada tetapi berkasnya hilang dari penyimpanan. */
    protected function missingReportFiles(): array
    {
        $missing = [];

        SupReport::query()
            ->whereNotNull('path')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'path', 'verification_code'])
            ->each(function (SupReport $report) use (&$missing): void {
                if (! Storage::disk('local')->exists($report->path)) {
                    $missing[] = $report->verification_code ?? ('#'.$report->id);
                }
            });

        return $this->check(
            'report_file_missing',
            'Berkas dokumen hilang dari penyimpanan',
            count($missing),
            array_slice($missing, 0, 10),
            self::WARNING,
            'Baris riwayat tetap ada sehingga kode verifikasi menyatakan dokumen tidak sah. Hasilkan ulang dokumennya bila salinan cetak masih beredar.',
        );
    }

    /** Supervisor dinonaktifkan tetapi masih punya penugasan/kegiatan terbuka. */
    protected function inactiveSupervisorAssigned(): array
    {
        $ids = DB::table('sup_supervisors as s')
            ->join('sup_assignments as a', 'a.supervisor_employee_id', '=', 's.employee_id')
            ->where('s.is_active', false)
            ->where('a.is_active', true)
            ->distinct()
            ->pluck('s.employee_id')
            ->all();

        return $this->check(
            'inactive_supervisor_assigned',
            'Supervisor nonaktif masih ditugaskan',
            count($ids),
            array_slice($ids, 0, 10),
            self::WARNING,
            'Alihkan penugasannya kepada supervisor aktif agar jadwal tidak menjadi tanggungan kosong.',
        );
    }

    /** Pengaturan inti ada dan skalanya lengkap. */
    protected function settingsUsable(): array
    {
        $scaleCode = app(SupervisiSettings::class)->get('grade_scale_code');
        $scaleExists = DB::table('sup_grade_scales')->where('code', $scaleCode)->exists();
        $hasBands = $scaleExists && DB::table('sup_grade_scales')->where('code', $scaleCode)->whereNotNull('bands')->exists();

        $problems = [];

        if (! $scaleExists) {
            $problems[] = 'Skala nilai '.$scaleCode.' tidak ditemukan';
        } elseif (! $hasBands) {
            $problems[] = 'Skala nilai '.$scaleCode.' belum memiliki ambang predikat';
        }

        return $this->check(
            'settings_grade_scale',
            'Skala nilai & pengaturan inti',
            count($problems),
            $problems,
            self::WARNING,
            'Tanpa skala nilai yang lengkap, kegiatan dapat difinalkan tanpa predikat. Perbaiki di Pengaturan Supervisi.',
        );
    }

    /**
     * @param  list<mixed>  $samples
     * @return array<string,mixed>
     */
    protected function check(string $key, string $label, int $count, array $samples, string $severity, string $suggestion): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $count === 0 ? self::OK : $severity,
            'count' => $count,
            'samples' => $samples,
            'suggestion' => $count === 0 ? null : $suggestion,
        ];
    }

    /** Ringkas untuk log/jadwal: hanya temuan yang perlu perhatian. */
    /** @return list<array<string,mixed>> */
    public function issues(): array
    {
        return collect($this->report()['checks'])
            ->whereIn('status', [self::WARNING, self::CRITICAL])
            ->values()
            ->all();
    }

    /** Jumlah kegiatan per status (dipakai health command untuk konteks). */
    /** @return array<string,int> */
    public function visitStatusCounts(): array
    {
        return SupVisit::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /** Kegiatan yang masih terbuka (belum final/batal) — konteks pemantauan. */
    public function openVisits(): int
    {
        return SupVisit::query()
            ->whereNotIn('status', [VisitStatus::COMPLETED->value, VisitStatus::CANCELLED->value])
            ->count();
    }

    /** Butir RTL yang melewati tenggat — konteks pemantauan. */
    public function overdueActionItems(): int
    {
        return SupActionItem::query()
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();
    }

    /** Indikator aktif yang tersedia untuk instrumen (konteks pemantauan). */
    public function indicatorCount(): int
    {
        return SupIndicator::query()->count();
    }
}
