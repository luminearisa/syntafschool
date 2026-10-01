<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status sasaran pengembangan.
 *
 * `ON_TRACK` dan `AT_RISK` ada karena "sedang berjalan" tidak cukup untuk
 * membedakan yang perlu didorong dari yang perlu dibantu.
 */
enum DevelopmentGoalStatus: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case ON_TRACK = 'ON_TRACK';
    case AT_RISK = 'AT_RISK';
    case ACHIEVED = 'ACHIEVED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Belum dimulai',
            self::IN_PROGRESS => 'Berjalan',
            self::ON_TRACK => 'Sesuai jalur',
            self::AT_RISK => 'Berisiko tidak tercapai',
            self::ACHIEVED => 'Tercapai',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::NOT_STARTED, self::IN_PROGRESS, self::ON_TRACK, self::AT_RISK], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::ACHIEVED, self::CANCELLED], true);
    }

    /** Boleh berpindah ke status ini? Menjaga riwayat tetap masuk akal. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NOT_STARTED => [self::IN_PROGRESS, self::ON_TRACK, self::AT_RISK, self::CANCELLED],
            self::IN_PROGRESS => [self::ON_TRACK, self::AT_RISK, self::ACHIEVED, self::CANCELLED],
            self::ON_TRACK => [self::IN_PROGRESS, self::AT_RISK, self::ACHIEVED, self::CANCELLED],
            self::AT_RISK => [self::IN_PROGRESS, self::ON_TRACK, self::ACHIEVED, self::CANCELLED],
            self::ACHIEVED => [],
            self::CANCELLED => [self::NOT_STARTED, self::IN_PROGRESS],
        };
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
