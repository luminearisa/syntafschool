<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Skor seorang supervisor pada sesi kalibrasi. */
class SupCalibrationScore extends BaseModel
{
    protected $table = 'sup_calibration_scores';

    protected $casts = [
        'score' => 'float',
    ];

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(SupIndicator::class, 'indicator_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SupCalibrationSession::class, 'calibration_session_id');
    }
}
