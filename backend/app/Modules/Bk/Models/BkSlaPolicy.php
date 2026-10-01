<?php

namespace App\Modules\Bk\Models;

use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kebijakan SLA.
 *
 * SLA configurable per kategori, prioritas, severity, dan tipe kasus. Angkanya
 * tersimpan sebagai data berversi, bukan konstanta di kode: sekolah dapat
 * menyesuaikannya tanpa deploy, dan perubahan lama tetap terekam.
 *
 * Tabel: `bk_sla_policies`
 */
class BkSlaPolicy extends BkModel
{
    protected $table = 'bk_sla_policies';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'applies_to' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'subject_type' => \App\Modules\Bk\Domain\Enums\SlaSubjectType::class,
        'escalation_level' => \App\Modules\Bk\Domain\Enums\EscalationLevel::class,
        'second_escalation_level' => \App\Modules\Bk\Domain\Enums\EscalationLevel::class,
    ];

    public function events(): HasMany
    {
        return $this->hasMany(BkSlaEvent::class, 'policy_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Apakah kebijakan ini menyaring subjek tertentu? */
    public function appliesToSubject(?string $priority, ?string $severity, ?string $caseType, ?string $category): bool
    {
        $applies = $this->applies_to ?? [];

        $checks = [
            'priorities' => $priority,
            'severities' => $severity,
            'case_types' => $caseType,
            'categories' => $category,
        ];

        foreach ($checks as $key => $value) {
            $allowed = $applies[$key] ?? [];

            if (! empty($allowed) && ! in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
