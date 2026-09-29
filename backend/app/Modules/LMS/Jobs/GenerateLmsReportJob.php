<?php

namespace App\Modules\LMS\Jobs;

use App\Modules\LMS\Application\Services\LmsReportService;
use App\Modules\LMS\Domain\Enums\ReportStatus;
use App\Modules\LMS\Models\LmsReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Membuat berkas laporan besar di antrean (§22, §11). */
class GenerateLmsReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $exportId) {}

    public function uniqueId(): string
    {
        return 'lms-report-'.$this->exportId;
    }

    public function handle(LmsReportService $reports): void
    {
        $export = LmsReportExport::query()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $reports->generate($export);
    }

    public function failed(Throwable $exception): void
    {
        LmsReportExport::query()->whereKey($this->exportId)->update([
            'status' => ReportStatus::FAILED->value,
            'error_message' => mb_strimwidth($exception->getMessage(), 0, 500, '…'),
            'updated_at' => now(),
        ]);
    }
}
