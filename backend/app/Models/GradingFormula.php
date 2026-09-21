<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Konfigurasi cara sebuah penilaian dihitung (A7).
 *
 * `settings` dipakai sebagai tempat aturan yang tidak layak menjadi kolom baru
 * selama masih berupa opsi, misalnya aturan pembulatan dan cara nilai remedial
 * menggantikan nilai awal (`remedial_rule`).
 *
 * Contoh `settings`:
 *
 *     {"decimals": 2, "remedial_rule": "max", "remedial_cap": 75}
 */
class GradingFormula extends BaseModel
{
    use SoftDeletes;

    public const TYPE_WEIGHTED_AVERAGE = 'weighted_average';
    public const TYPE_TOTAL = 'total';
    public const TYPE_CUSTOM = 'custom';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_WEIGHTED_AVERAGE,
        self::TYPE_TOTAL,
        self::TYPE_CUSTOM,
    ];

    /** Cara nilai remedial dihitung terhadap nilai awal. */
    public const REMEDIAL_MAX = 'max';
    public const REMEDIAL_AVERAGE = 'average';
    public const REMEDIAL_CAP = 'cap';

    /** @var list<string> */
    public const REMEDIAL_RULES = [
        self::REMEDIAL_MAX,
        self::REMEDIAL_AVERAGE,
        self::REMEDIAL_CAP,
    ];

    protected $casts = [
        'settings' => 'array',
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function effectiveAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'effective_academic_year_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * Aturan pembulatan: jumlah desimal yang dipakai nilai akhir.
     */
    public function decimals(): int
    {
        $decimals = $this->settings['decimals'] ?? 2;

        return max(0, min(4, (int) $decimals));
    }

    /**
     * Cara nilai remedial menggantikan nilai awal.
     *
     * Baku `max`: remedial hanya menolong, tidak pernah menurunkan nilai siswa.
     */
    public function remedialRule(): string
    {
        $rule = $this->settings['remedial_rule'] ?? self::REMEDIAL_MAX;

        return in_array($rule, self::REMEDIAL_RULES, true) ? $rule : self::REMEDIAL_MAX;
    }

    /**
     * Batas nilai tertinggi yang boleh dicapai lewat remedial (aturan `cap`).
     */
    public function remedialCap(): ?float
    {
        $cap = $this->settings['remedial_cap'] ?? null;

        return $cap === null ? null : (float) $cap;
    }
}
