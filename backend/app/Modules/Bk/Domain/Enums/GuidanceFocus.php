<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Fokus sesi bimbingan karier.
 *
 * Setiap sesi menunjuk satu fokus, sehingga riwayat bimbingan terbaca sebagai
 * proses — bukan tumpukan catatan tanpa arah.
 */
enum GuidanceFocus: string
{
    case ASPIRATION = 'ASPIRATION';
    case INTEREST = 'INTEREST';
    case SKILL = 'SKILL';
    case EXPLORATION = 'EXPLORATION';
    case UNIVERSITY = 'UNIVERSITY';
    case EDUCATION_PLAN = 'EDUCATION_PLAN';
    case ACTION_PLAN = 'ACTION_PLAN';
    case PROGRESS = 'PROGRESS';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ASPIRATION => 'Cita-cita',
            self::INTEREST => 'Minat',
            self::SKILL => 'Keterampilan',
            self::EXPLORATION => 'Penjelajahan pilihan',
            self::UNIVERSITY => 'Perencanaan perguruan tinggi',
            self::EDUCATION_PLAN => 'Perencanaan pendidikan',
            self::ACTION_PLAN => 'Rencana tindakan',
            self::PROGRESS => 'Kemajuan',
        };
    }

    public function isPlanOriented(): bool
    {
        return in_array($this, [self::UNIVERSITY, self::EDUCATION_PLAN, self::ACTION_PLAN], true);
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
