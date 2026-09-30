<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat dokumen/laporan yang dihasilkan modul (program, hasil, evaluasi). */
class SupReport extends BaseModel
{
    protected $table = 'sup_reports';

    protected $casts = [
        'parameters' => 'array',
        'completed_at' => 'datetime',
        'rows_count' => 'integer',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** Tautan publik untuk pembuktian keaslian salinan cetak. */
    public function verificationUrl(): ?string
    {
        return $this->verification_code === null ? null : url('/verifikasi-dokumen/'.$this->verification_code);
    }
}
