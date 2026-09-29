<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

/** Riwayat pemeriksaan integritas (§24). */
class LmsIntegrityRun extends BaseModel
{
    protected $table = 'lms_integrity_runs';

    protected $casts = [
        'findings' => 'array',
        'checks_run' => 'integer',
        'issues_found' => 'integer',
        'duration_ms' => 'integer',
    ];
}
