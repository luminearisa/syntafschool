<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setelan modul (§81 Administration → HR Settings).
 * Nilai bawaan dari `config/hrd.php`; baris di sini adalah penimpaan yang
 * disengaja sekolah, lengkap dengan siapa yang mengubahnya.
 */
class HrSetting extends HrdModel
{
    protected $casts = ['value' => 'array'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
