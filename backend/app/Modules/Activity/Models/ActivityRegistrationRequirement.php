<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\RequirementOperator;
use App\Modules\Activity\Domain\Enums\RequirementType;

/**
 * Satu aturan penerimaan anggota. Baris, bukan kolom — supaya syarat baru tidak butuh migrasi.
 *
 * Tabel: `activity_registration_requirements`
 */
class ActivityRegistrationRequirement extends ActivityModel
{
    protected $table = 'activity_registration_requirements';

    protected $casts = [
        'value' => 'array',
        'is_mandatory' => 'boolean',
        'sort_order' => 'integer',
        'type' => RequirementType::class,
        'operator' => RequirementOperator::class,
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
}
