<?php

namespace App\Modules\Hrd\Domain\Enums;

/**
 * Tingkat keparahan temuan kualitas data & aturan validasi (§56, §57).
 *
 * `CRITICAL` menahan penyimpanan (bila `hrd.validation.enforce_on_save` menyala),
 * `WARNING` hanya dilaporkan, `INFO` adalah saran perbaikan. Ambang "mana yang
 * menghalangi" dibaca dari konfigurasi, bukan ditanam di pemeriksa.
 */
enum DataQualitySeverity: string
{
    case CRITICAL = 'critical';
    case WARNING = 'warning';
    case INFO = 'info';

    public function label(): string
    {
        return match ($this) {
            self::CRITICAL => 'Kritis',
            self::WARNING => 'Peringatan',
            self::INFO => 'Informasi',
        };
    }

    public function weight(): int
    {
        return (int) config("hrd.data_quality.weights.{$this->value}", match ($this) {
            self::CRITICAL => 5,
            self::WARNING => 2,
            self::INFO => 1,
        });
    }

    public function isBlocking(): bool
    {
        return in_array($this->value, (array) config('hrd.validation.blocking_severity', ['critical']), true);
    }
}
