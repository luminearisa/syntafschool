<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aturan validasi yang dapat dikonfigurasi (§57).
 *
 * `handler` menunjuk pemeriksa bawaan yang terdaftar di `ValidationEngine`;
 * `config` membawa parameternya (mis. jendela hari). Sekolah dapat menonaktifkan
 * aturan yang tidak sesuai kebijakannya tanpa deploy ulang.
 */
class HrValidationRule extends HrdModel
{
    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'auto_detect' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function issues(): HasMany
    {
        return $this->hasMany(HrDataQualityIssue::class, 'rule_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAutoDetect($query)
    {
        return $query->where('auto_detect', true);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function isBlocking(): bool
    {
        return in_array($this->severity, (array) config('hrd.validation.blocking_severity', ['critical']), true);
    }
}
