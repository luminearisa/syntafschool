<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\IntegrationName;
use App\Modules\Activity\Domain\Enums\IntegrationStatus;

/**
 * Catatan setiap panggilan ke modul lain — bukti bahwa integrasi benar-benar terjadi (atau sengaja dilewati).
 *
 * Tabel: `activity_integration_logs`
 */
class ActivityIntegrationLog extends ActivityModel
{
    /**
     * Tabel riwayat append-only: hanya `created_at` yang dibutuhkan (diisi basis
     * data). Tanpa ini Eloquent akan menulis `updated_at` yang memang tidak ada
     * — dan baris riwayat tidak boleh punya arti "diperbarui".
     */
    public $timestamps = false;

    protected $table = 'activity_integration_logs';

    protected $casts = [
        'integration' => IntegrationName::class,
        'status' => IntegrationStatus::class,
        'payload' => 'array',
        'response' => 'array',
        'created_at' => 'datetime',
    ];
    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_user_id');
    }
}
