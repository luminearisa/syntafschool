<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status pemantauan SLA.
 *
 * MET dihitung dari penyelesaian sebelum tenggat, BREACHED dari keterlampauan.
 * Keduanya tersimpan sebagai fakta, bukan dihitung ulang setiap tampilan.
 */
enum SlaStatus: string
{
    case RUNNING = 'RUNNING';
    case WARNING_SENT = 'WARNING_SENT';
    case MET = 'MET';
    case BREACHED = 'BREACHED';
    case ESCALATED = 'ESCALATED';
    case PAUSED = 'PAUSED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::RUNNING => 'Berjalan',
            self::WARNING_SENT => 'Peringatan terkirim',
            self::MET => 'Tepat waktu',
            self::BREACHED => 'Terlampaui',
            self::ESCALATED => 'Sudah dieskalasi',
            self::PAUSED => 'Ditahan',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::RUNNING, self::WARNING_SENT], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::MET, self::BREACHED, self::ESCALATED, self::CANCELLED], true);
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
