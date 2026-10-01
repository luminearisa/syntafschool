<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status eskalasi.
 *
 * Eskalasi punya akhir yang jelas: diakui lalu diselesaikan oleh orang, bukan
 * menghilang begitu saja setelah dibaca.
 */
enum EscalationStatus: string
{
    case PENDING = 'PENDING';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case RESOLVED = 'RESOLVED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu ditangani',
            self::ACKNOWLEDGED => 'Diakui',
            self::RESOLVED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::ACKNOWLEDGED], true);
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
