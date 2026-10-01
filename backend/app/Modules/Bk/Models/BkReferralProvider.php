<?php

namespace App\Modules\Bk\Models;

use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penyedia rujukan, baik di dalam maupun di luar sekolah.
 *
 * Data penyedia disimpan sekali dan dipakai ulang, sehingga setiap rujukan tidak
 * menulis ulang nama dan kontak yang sama.
 *
 * Tabel: `bk_referral_providers`
 */
class BkReferralProvider extends BkModel
{
    protected $table = 'bk_referral_providers';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'is_external' => 'boolean',
        'is_active' => 'boolean',
        'requires_consent' => 'boolean',
        'service_scope' => 'array',
        'provider_type' => \App\Modules\Bk\Domain\Enums\ReferralProviderType::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function externalReferrals(): HasMany
    {
        return $this->hasMany(BkExternalReferral::class, 'referral_provider_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
