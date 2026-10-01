<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status tindak lanjut hasil konferensi.
 *
 * Setiap keputusan konferensi wajib punya penanggung jawab dan tenggat; `OVERDUE`
 * ditandai sistem, bukan disimpulkan dari warna di layar.
 */
enum ConferenceActionStatus: string
{
    case PENDING = 'PENDING';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case OVERDUE = 'OVERDUE';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::IN_PROGRESS => 'Dikerjakan',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::OVERDUE => 'Terlambat',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::IN_PROGRESS, self::OVERDUE], true);
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
