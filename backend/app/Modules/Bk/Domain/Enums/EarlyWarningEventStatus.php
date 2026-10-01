<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Hasil satu evaluasi aturan.
 *
 * Setiap evaluasi meninggalkan jejak — termasuk yang tidak cocok (`SKIPPED`) dan yang
 * ditahan karena jeda (`COOLDOWN`), supaya "kenapa tidak ada peringatan?" terjawab.
 */
enum EarlyWarningEventStatus: string
{
    case DETECTED = 'DETECTED';
    case ACTIONED = 'ACTIONED';
    case COOLDOWN = 'COOLDOWN';
    case SUPPRESSED = 'SUPPRESSED';
    case SKIPPED = 'SKIPPED';
    case FAILED = 'FAILED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DETECTED => 'Terdeteksi',
            self::ACTIONED => 'Tindakan dijalankan',
            self::COOLDOWN => 'Ditahan (jeda)',
            self::SUPPRESSED => 'Ditekan',
            self::SKIPPED => 'Tidak cocok',
            self::FAILED => 'Gagal dievaluasi',
        };
    }

    public function producedAction(): bool
    {
        return $this === self::ACTIONED;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
