<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ReportFormat;
use App\Modules\LMS\Domain\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ekspor laporan LMS (§22) — laporan berat dijalankan di antrean. */
class LmsReportExport extends BaseModel
{
    protected $table = 'lms_report_exports';

    protected $casts = [
        'format' => ReportFormat::class,
        'status' => ReportStatus::class,
        'filters' => 'array',
        'rows_count' => 'integer',
        'queued_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(LmsUser::class, 'requested_by');
    }

    public function isReady(): bool
    {
        return $this->status === ReportStatus::READY && $this->file_path !== null;
    }

    public function toPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'report_key' => $this->report_key,
            'format' => $this->format?->value,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'filters' => $this->filters,
            'rows_count' => (int) $this->rows_count,
            'ready' => $this->isReady(),
            'error_message' => $this->error_message,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
