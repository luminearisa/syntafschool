<?php

namespace App\Modules\LMS\Console\Commands;

use App\Modules\LMS\Application\Services\LmsReportService;
use App\Modules\LMS\Domain\Enums\ReportFormat;
use App\Modules\LMS\Domain\Enums\ReportKey;
use App\Modules\LMS\Domain\Support\LmsSystemActor;
use Illuminate\Console\Command;

/** `php artisan lms:report` — menjalankan laporan LMS dari terminal (§22). */
class LmsReportCommand extends Command
{
    protected $signature = 'lms:report
        {key? : Kunci laporan (COURSE, ENROLLMENT, COMPLETION, …); kosong = daftar}
        {--course= : Filter course}
        {--student= : Filter siswa}
        {--from= : Tanggal awal (YYYY-MM-DD)}
        {--to= : Tanggal akhir (YYYY-MM-DD)}
        {--format=CSV : Format berkas (CSV)}';

    protected $description = 'Menjalankan laporan LMS dan menyimpannya sebagai berkas';

    public function handle(LmsReportService $reports): int
    {
        $key = $this->argument('key');

        if ($key === null) {
            foreach ($reports->catalog() as $entry) {
                $this->line(sprintf('  %-18s %s', $entry['key'], $entry['label']));
            }

            return self::SUCCESS;
        }

        $reportKey = ReportKey::tryFrom(strtoupper((string) $key));

        if ($reportKey === null) {
            $this->error('Kunci laporan tidak dikenal: '.$key);

            return self::FAILURE;
        }

        $format = ReportFormat::tryFrom(strtoupper((string) $this->option('format'))) ?? ReportFormat::CSV;

        $result = $reports->request($reportKey, $format, array_filter([
            'course_id' => $this->option('course'),
            'student_id' => $this->option('student'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
        ], fn ($value) => $value !== null && $value !== ''), LmsSystemActor::make(), 'cli:'.uniqid('', true));

        $this->info('Laporan '.$reportKey->label().': '.($result['rows_count'] ?? 0).' baris — status '.($result['status'] ?? '-'));

        if (isset($result['file_path']) && $result['file_path'] !== null) {
            $this->line('Berkas: '.$result['file_path']);
        }

        return self::SUCCESS;
    }
}
