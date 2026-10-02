<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis transaksi SDM (§33) — konfigurasi, bukan `switch` di kode.
 *
 * Setiap jenis menunjuk `effect_handler` (yang mengeksekusi perubahan) dan
 * `workflow_definition_code` (yang menyetujuinya). Menambah "Rotasi Antar Unit"
 * berarti menambah satu baris di sini, bukan menyunting mesin transaksi.
 */
class HrTransactionType extends HrdModel
{
    protected $casts = [
        'requires_approval' => 'boolean',
        'requires_effective_date' => 'boolean',
        'requires_attachment' => 'boolean',
        'requires_reason' => 'boolean',
        'applies_to' => 'array',
        'allowed_from_states' => 'array',
        'sort_order' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(HrTransaction::class, 'type_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Apakah transaksi ini boleh dimulai dari keadaan lifecycle tertentu? */
    public function allowsFrom(?string $lifecycleState): bool
    {
        $allowed = $this->allowed_from_states;

        if (! is_array($allowed) || $allowed === []) {
            return true;
        }

        return $lifecycleState !== null && in_array($lifecycleState, $allowed, true);
    }
}
