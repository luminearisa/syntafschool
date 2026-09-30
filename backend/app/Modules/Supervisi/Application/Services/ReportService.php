<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Application\Support\XlsxWriter;
use App\Modules\Supervisi\Models\SupActionPlan;
use App\Modules\Supervisi\Models\SupAppraisal;
use App\Modules\Supervisi\Models\SupCalibrationSession;
use App\Modules\Supervisi\Models\SupProgram;
use App\Modules\Supervisi\Models\SupReport;
use App\Modules\Supervisi\Models\SupVisit;
use App\Services\Library\Documents\CodeImageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Laporan & dokumen supervisi (Fase 4).
 *
 * Menyediakan laporan wajib Permendikbud 15/2018 dalam bentuk tabel siap
 * diekspor (CSV/XLSX/PDF), menyimpan riwayat dokumen beserta **checksum** dan
 * **kode verifikasi** — supaya salinan cetak dapat dibuktikan keasliannya lewat
 * halaman verifikasi (kode juga disematkan sebagai QR di berkas PDF).
 */
class ReportService
{
    /** Definisi seluruh laporan: judul, kolom, dan sumber datanya. */
    public const REPORTS = [
        'program_supervisi' => 'Program & Pelaksanaan Supervisi',
        'hasil_supervisi' => 'Hasil Supervisi per Pegawai',
        'tindak_lanjut' => 'Tindak Lanjut Temuan (RTL)',
        'analitik_indikator' => 'Analitik Indikator & Tren',
        'rekap_penilaian' => 'Rekap Penilaian Kinerja & Pembinaan',
        'kalibrasi_penilai' => 'Kalibrasi Penilai',
    ];

    /**
     * Laporan yang memuat angka agregat sekolah. Guru/staf hanya boleh
     * membukanya bila sekolah mengaktifkan ringkasan bagi rekan sejawat
     * (`peer_summary_enabled`).
     */
    public const AGGREGATE_REPORTS = [
        'program_supervisi',
        'analitik_indikator',
        'kalibrasi_penilai',
    ];

    public function __construct(
        protected AnalyticsService $analytics,
        protected CodeImageService $codes = new CodeImageService(),
        protected DataScopeService $scopes = new DataScopeService(new VisitAccessService(), new SupervisiSettings()),
    ) {
    }

    /**
     * Bangun tabel laporan: judul kolom + baris + ringkasan angka.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{key:string,title:string,window:array<string,string>,headers:list<string>,rows:list<list<mixed>>,summary:array<string,mixed>}
     */
    public function build(string $key, array $parameters = [], ?DataScope $scope = null): array
    {
        $scope ??= DataScope::school();

        if (! array_key_exists($key, self::REPORTS)) {
            throw ValidationException::withMessages([
                'report_key' => 'Laporan '.$key.' tidak dikenal. Pilihan: '.implode(', ', array_keys(self::REPORTS)).'.',
            ]);
        }

        $from = isset($parameters['from']) ? Carbon::parse($parameters['from'])->startOfDay() : now()->startOfYear();
        $to = isset($parameters['to']) ? Carbon::parse($parameters['to'])->endOfDay() : now()->endOfYear();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        // Laporan agregat sekolah tidak dapat dibaca dengan cakupan "diri".
        if ($scope->isSelf() && in_array($key, self::AGGREGATE_REPORTS, true)) {
            $this->scopes->assertAllowsAggregate($scope, 'Laporan '.self::REPORTS[$key]);
        }

        $built = match ($key) {
            'program_supervisi' => $this->programReport($from, $to),
            'hasil_supervisi' => $this->resultReport($from, $to, $scope),
            'tindak_lanjut' => $this->followUpReport($from, $to, $scope),
            'analitik_indikator' => $this->indicatorReport($from, $to),
            'rekap_penilaian' => $this->appraisalReport($from, $to, $scope),
            'kalibrasi_penilai' => $this->calibrationReport($from, $to),
        };

        return [
            'key' => $key,
            'title' => self::REPORTS[$key],
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'scope' => $scope->payload(),
            'headers' => $built['headers'],
            'rows' => $built['rows'],
            'summary' => [
                ...$built['summary'],
                // Guru melihat barisnya sendiri: itu harus terbaca di dokumen,
                // bukan disamarkan sebagai laporan sekolah.
                'cakupan_data' => $scope->isSchool() ? 'Seluruh sekolah' : 'Data pegawai yang bersangkutan saja',
            ],
        ];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::REPORTS);
    }

    /**
     * Simpan laporan: berkas (format apa pun) + riwayat + kode verifikasi.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function generate(string $key, string $format, array $parameters, User $actor): SupReport
    {
        $table = $this->build($key, $parameters);
        $format = strtoupper($format);

        if (! in_array($format, ['CSV', 'XLSX', 'PDF', 'JSON'], true)) {
            throw ValidationException::withMessages(['format' => 'Format hanya CSV, XLSX, PDF, atau JSON.']);
        }

        $contents = match ($format) {
            'CSV' => $this->toCsv($table),
            'XLSX' => $this->toXlsx($table),
            'PDF' => $this->toPdf($table, null),
            default => json_encode($table, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}',
        };

        $code = $this->uniqueCode();
        $checksum = hash('sha256', $contents);
        $extension = strtolower($format);

        if ($format === 'PDF') {
            // PDF memuat QR berisi tautan verifikasi, sehingga checksum berubah
            // setelah kode ditetapkan: berkas ditulis ulang lalu di-hash final.
            $contents = $this->toPdf($table, $code);
            $checksum = hash('sha256', $contents);
        }

        $path = 'supervisi/laporan/'.$key.'-'.now()->format('Ymd-His').'-'.$code.'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        $report = SupReport::query()->create([
            'report_key' => $key,
            'title' => $table['title'],
            'format' => $format,
            'parameters' => $table['window'] + ['summary' => $table['summary']],
            'path' => $path,
            'checksum' => $checksum,
            'verification_code' => $code,
            'rows_count' => count($table['rows']),
            'status' => 'COMPLETED',
            'requested_by' => $actor->id,
            'generated_by' => $actor->id,
            'completed_at' => now(),
        ]);

        return $report;
    }

    /** Riwayat dokumen (terbaru lebih dahulu). */
    public function history(int $limit = 60, ?DataScope $scope = null)
    {
        return SupReport::query()
            ->with('requester:id,name')
            // Cakupan "diri": hanya dokumen yang ia sendiri hasilkan.
            ->when($scope?->isSelf(), fn ($query) => $query->where('requested_by', $scope->userId ?? -1))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Verifikasi dokumen dari kode publiknya. Hanya kode, checksum, dan
     * keterangan dokumen yang diungkap — tanpa isi laporan.
     *
     * @return array<string, mixed>
     */
    public function verify(string $code): array
    {
        $report = SupReport::query()->where('verification_code', strtoupper($code))->first();

        if ($report === null) {
            return ['valid' => false, 'code' => strtoupper($code), 'message' => 'Kode verifikasi tidak terdaftar.'];
        }

        $exists = $report->path !== null && Storage::disk('local')->exists($report->path);
        $checksumMatches = false;

        if ($exists) {
            $checksumMatches = hash('sha256', (string) Storage::disk('local')->get($report->path)) === $report->checksum;
        }

        return [
            'valid' => $report->status === 'COMPLETED' && $checksumMatches,
            'code' => $report->verification_code,
            'title' => $report->title,
            'report_key' => $report->report_key,
            'format' => $report->format,
            'rows_count' => $report->rows_count,
            'generated_at' => $report->completed_at?->toIso8601String(),
            'checksum' => $report->checksum,
            'checksum_matches' => $checksumMatches,
            'file_available' => $exists,
            'status' => $report->status,
            'message' => $checksumMatches
                ? 'Dokumen asli dan belum diubah.'
                : 'Berkas tidak ditemukan atau isinya berbeda dari catatan server.',
        ];
    }

    // ---------------------------------------------------------------------
    // Bentuk laporan
    // ---------------------------------------------------------------------

    /** @return array{headers:list<string>,rows:list<list<mixed>>,summary:array<string,mixed>} */
    protected function programReport(Carbon $from, Carbon $to): array
    {
        $programs = SupProgram::query()
            ->with('targets')
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('starts_at', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('created_at', [$from, $to]);
            })
            ->orderBy('starts_at')
            ->get();

        $rows = [];
        $planned = 0;
        $done = 0;

        foreach ($programs as $program) {
            $target = (int) $program->targets->pluck('planned_count')->filter()->sum();
            $visits = SupVisit::query()
                ->where('program_id', $program->id)
                ->whereNotNull('finalized_at')
                ->whereBetween('finalized_at', [$from, $to]);

            $finalized = (clone $visits)->count();
            $average = (clone $visits)->avg('final_score');
            $planned += $target;
            $done += $finalized;

            $rows[] = [
                $program->title,
                $this->enumLabel($program->scope),
                $this->enumLabel($program->status),
                $program->starts_at?->toDateString(),
                $program->ends_at?->toDateString(),
                $target > 0 ? $target : null,
                $finalized,
                $target > 0 ? round($finalized / $target * 100, 2) : null,
                $average === null ? null : round((float) $average, 2),
            ];
        }

        return [
            'headers' => ['Program', 'Lingkup', 'Status', 'Mulai', 'Selesai', 'Target kegiatan', 'Kegiatan selesai', 'Capaian (%)', 'Rata-rata nilai'],
            'rows' => $rows,
            'summary' => [
                'programs' => $programs->count(),
                'planned' => $planned,
                'finalized' => $done,
                'realization_percent' => $planned > 0 ? round($done / $planned * 100, 2) : null,
                'analytics' => $this->analytics->visitStats($from, $to),
            ],
        ];
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>,summary:array<string,mixed>} */
    protected function resultReport(Carbon $from, Carbon $to, ?DataScope $scope = null): array
    {
        $visits = SupVisit::query()
            ->with([
                'supervisor:id,full_name',
                'supervisee:id,full_name,position',
                'instrumentVersion:id,version,instrument_id',
            ])
            ->whereNotNull('finalized_at')
            ->whereBetween('finalized_at', [$from, $to])
            ->when($scope?->isSelf(), fn ($query) => $query->where(function ($inner) use ($scope): void {
                // Kegiatan yang menyangkut dirinya — sebagai yang disupervisi
                // maupun sebagai pelaksana.
                $inner->where('supervisee_employee_id', $scope->employeeId)
                    ->orWhere('supervisor_employee_id', $scope->employeeId);
            }))
            ->orderBy('finalized_at')
            ->get();

        $rows = $visits->map(function (SupVisit $visit) {
            $plan = SupActionPlan::query()->where('visit_id', $visit->id)->first();

            $verified = $plan !== null && $plan->verified_at !== null;

            return [
                $visit->code,
                $visit->finalized_at?->toDateString(),
                $visit->supervisee?->full_name,
                $visit->supervisee?->position,
                $visit->supervisor?->full_name,
                (string) $visit->activity_kind->label(),
                (string) $visit->status->label(),
                $visit->raw_score,
                $visit->max_score,
                $visit->final_score,
                $visit->grade_band,
                $plan?->title,
                $plan?->status->value,
                $plan?->progress_percent,
                $verified ? 'Ya' : 'Tidak',
            ];
        })->all();

        return [
            'headers' => [
                'Kode kegiatan', 'Tanggal finalisasi', 'Pegawai', 'Jabatan', 'Supervisor', 'Teknik',
                'Status', 'Skor mentah', 'Skor maksimum', 'Nilai akhir', 'Predikat',
                'RTL', 'Status RTL', 'Kemajuan RTL (%)', 'RTL terverifikasi',
            ],
            'rows' => $rows,
            'summary' => [
                'visits' => $visits->count(),
                'scores' => $this->analytics->scoreStats($from, $to),
                'follow_up' => $this->analytics->followUpStats($from, $to),
            ],
        ];
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>,summary:array<string,mixed>} */
    protected function followUpReport(Carbon $from, Carbon $to, ?DataScope $scope = null): array
    {
        $plans = SupActionPlan::query()
            ->with([
                'supervisee:id,full_name,position',
                'visit:id,code',
                'items',
            ])
            ->whereBetween('created_at', [$from, $to])
            ->when($scope?->isSelf(), fn ($query) => $query->where(function ($inner) use ($scope): void {
                $inner->where('supervisee_employee_id', $scope->employeeId)
                    ->orWhereHas('items', fn ($item) => $item->where('owner_employee_id', $scope->employeeId));
            }))
            ->orderBy('created_at')
            ->get();

        $rows = [];

        foreach ($plans as $plan) {
            foreach ($plan->items as $item) {
                $status = $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status;
                $rows[] = [
                    $plan->visit?->code,
                    $plan->title,
                    $plan->supervisee?->full_name,
                    $item->statement,
                    $item->due_date?->toDateString(),
                    $status,
                    $item->progress_percent,
                    $item->completed_at?->toDateString(),
                    $item->due_date !== null && $item->completed_at !== null
                        ? ($item->completed_at->lessThanOrEqualTo($item->due_date) ? 'Tepat waktu' : 'Terlambat')
                        : ($item->due_date !== null && $item->due_date->isPast() && ! in_array($status, ['DONE', 'VERIFIED', 'DROPPED'], true) ? 'Terlambat' : null),
                    $item->result_note,
                ];
            }
        }

        return [
            'headers' => [
                'Kode kegiatan', 'RTL', 'Pegawai', 'Sasaran/butir', 'Tenggat', 'Status butir',
                'Kemajuan (%)', 'Selesai pada', 'Ketepatan waktu', 'Catatan hasil',
            ],
            'rows' => $rows,
            'summary' => $this->analytics->followUpStats($from, $to),
        ];
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>,summary:array<string,mixed>} */
    protected function indicatorReport(Carbon $from, Carbon $to): array
    {
        $indicators = $this->analytics->indicatorStats($from, $to, 0);
        $sections = $this->analytics->sectionStats($from, $to);

        $rows = array_map(fn (array $row) => [
            $row['section'],
            $row['code'],
            $row['statement'],
            $row['max_score'],
            $row['answers'],
            $row['average_score'],
            $row['average_percent'],
            $row['lowest_score'],
            $row['is_weak'] ? 'Perlu perbaikan' : 'Baik',
        ], $indicators);

        return [
            'headers' => ['Bagian', 'Kode', 'Indikator', 'Skor maksimum', 'Jumlah jawaban', 'Rata-rata skor', 'Rata-rata (%)', 'Skor terendah', 'Kategori'],
            'rows' => $rows,
            'summary' => [
                'indicators' => count($rows),
                'weak' => count(array_filter($indicators, fn (array $row) => $row['is_weak'])),
                'weak_threshold_percent' => AnalyticsService::WEAK_INDICATOR_PERCENT,
                'sections' => $sections,
                'trend' => $this->analytics->trend($from, $to),
            ],
        ];
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>,summary:array<string,mixed>} */
    protected function appraisalReport(Carbon $from, Carbon $to, ?DataScope $scope = null): array
    {
        $appraisals = SupAppraisal::query()
            ->with(['supervisee:id,full_name,position', 'pipPlans'])
            ->whereBetween('created_at', [$from, $to])
            ->when($scope?->isSelf(), fn ($query) => $query->where(function ($inner) use ($scope): void {
                $inner->where('supervisee_employee_id', $scope->employeeId)
                    ->orWhere('assessor_employee_id', $scope->employeeId)
                    ->orWhere('reviewer_employee_id', $scope->employeeId);
            }))
            ->orderBy('supervisee_employee_id')
            ->get();

        $rows = $appraisals->map(fn (SupAppraisal $appraisal) => [
            $appraisal->supervisee?->full_name,
            $appraisal->supervisee?->position,
            (string) ($appraisal->appraisal_type?->value ?? ''),
            $appraisal->period_start?->toDateString(),
            $appraisal->period_end?->toDateString(),
            $appraisal->raw_score,
            $appraisal->max_score,
            $appraisal->final_score,
            $appraisal->grade_label,
            $appraisal->credit_percent,
            $appraisal->attendance_absence_days,
            $appraisal->attendance_correction_percent,
            $appraisal->final_predicate,
            $appraisal->published_at?->toDateString(),
            $appraisal->pipPlans->first()?->status,
            $appraisal->pipPlans->first()?->outcome,
        ])->all();

        return [
            'headers' => [
                'Pegawai', 'Jabatan', 'Jenis penilaian', 'Awal periode', 'Akhir periode',
                'Skor mentah', 'Skor maksimum', 'Nilai akhir', 'Predikat', 'Angka kredit (%)',
                'Hari alfa', 'Koreksi kehadiran (%)', 'Predikat SKP', 'Tanggal terbit',
                'Status PIP', 'Hasil PIP',
            ],
            'rows' => $rows,
            'summary' => $this->analytics->appraisalStats($from, $to) + ['growth' => $this->analytics->growthStats($from, $to)],
        ];
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>,summary:array<string,mixed>} */
    protected function calibrationReport(Carbon $from, Carbon $to): array
    {
        $sessions = SupCalibrationSession::query()
            ->with('scores')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('held_at')
            ->get();

        $rows = [];

        foreach ($sessions as $session) {
            $grouped = $session->scores->groupBy(fn ($score) => $score->indicator_label ?? ('indikator-'.$score->indicator_id));

            foreach ($grouped as $label => $scores) {
                $values = $scores->pluck('score')->filter()->map(fn ($value) => (float) $value);
                $spread = $values->isEmpty() ? null : round($values->max() - $values->min(), 2);
                $average = $values->isEmpty() ? null : round($values->avg(), 2);

                $rows[] = [
                    $session->title,
                    $session->held_at?->toDateString(),
                    (string) $session->status,
                    $label,
                    $values->count(),
                    $average,
                    $spread,
                    $session->threshold,
                    $spread !== null && $spread > (float) $session->threshold ? 'Melebihi ambang' : 'Dalam ambang',
                    $session->conclusion,
                ];
            }
        }

        return [
            'headers' => [
                'Sesi kalibrasi', 'Tanggal', 'Status', 'Indikator/kasus', 'Jumlah penilai',
                'Rata-rata skor', 'Selisih (maks−min)', 'Ambang', 'Penilaian', 'Kesimpulan',
            ],
            'rows' => $rows,
            'summary' => [
                'sessions' => $sessions->count(),
                'finalized' => $sessions->whereNotNull('finalized_at')->count(),
                'exceeding_threshold' => count(array_filter($rows, fn (array $row) => ($row[8] ?? null) === 'Melebihi ambang')),
                'supervisors' => $this->analytics->supervisorStats($from, $to),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Format berkas
    // ---------------------------------------------------------------------

    /** @param array<string,mixed> $table */
    public function toCsv(array $table): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw ValidationException::withMessages(['format' => 'Berkas CSV tidak dapat disiapkan.']);
        }

        // BOM agar Excel membaca UTF-8 dengan benar.
        fwrite($handle, "\xEF\xBB\xBF");
        // Argumen escape ditulis eksplisit: nilai bawaannya berubah pada PHP 8.4.
        fputcsv($handle, $table['headers'], ';', '"', '\\');

        foreach ($table['rows'] as $row) {
            fputcsv($handle, array_map(fn ($value) => $value === null ? '' : $value, $row), ';', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @param array<string,mixed> $table */
    public function toXlsx(array $table): string
    {
        return (new XlsxWriter(mb_substr((string) $table['title'], 0, 28)))
            ->setHeaders($table['headers'])
            ->setRows($table['rows'])
            ->toString();
    }

    /**
     * PDF memuat kop sekolah, ringkasan, tabel, dan QR verifikasi.
     *
     * QR memerlukan ekstensi GD. Bila tidak tersedia, dokumen tetap dicetak —
     * kode dan tautan verifikasi ditulis sebagai teks sehingga keaslian salinan
     * masih dapat diperiksa.
     */
    public function toPdf(array $table, ?string $verificationCode): string
    {
        $verificationUrl = $verificationCode === null ? null : url('/verifikasi-dokumen/'.$verificationCode);
        $qrDataUri = null;

        if ($verificationUrl !== null && extension_loaded('gd')) {
            try {
                $qrDataUri = $this->codes->qrDataUri($verificationUrl, 220, 1);
            } catch (Throwable) {
                $qrDataUri = null;
            }
        }

        return Pdf::loadView('pdf.supervisi-report', [
            'table' => $table,
            'verificationCode' => $verificationCode,
            'verificationUrl' => $verificationUrl,
            'qrDataUri' => $qrDataUri,
            'school' => $this->school(),
        ])->setPaper('a4', 'landscape')->output();
    }

    /**
     * Nilai tampilan untuk kolom yang dicast menjadi enum.
     *
     * Laporan dicetak untuk dibaca manusia: pakai `label()` bila enum
     * menyediakannya, selain itu nilai `value`-nya.
     */
    protected function enumLabel(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            return method_exists($value, 'label')
                ? (string) $value->label()
                : (string) ($value->value ?? '');
        }

        return (string) $value;
    }

    /** @return array<string, mixed> */
    protected function school(): array
    {
        return [
            'name' => (string) config('app.school_name', 'Syntaf School'),
            'address' => (string) config('app.school_address', ''),
        ];
    }

    protected function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (SupReport::query()->where('verification_code', $code)->exists());

        return $code;
    }
}
