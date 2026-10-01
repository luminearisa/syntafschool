<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status umpan balik.
 *
 * Setelah FINAL, isinya tidak dapat diubah siapa pun, termasuk konselor. Konselor
 * juga tidak dapat menyunting umpan balik pada status mana pun; yang boleh
 * dilakukannya hanyalah menandai sudah dibaca.
 */
enum FeedbackStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case FINAL = 'FINAL';
    case WITHDRAWN = 'WITHDRAWN';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Terkirim',
            self::FINAL => 'Final (tidak dapat diubah)',
            self::WITHDRAWN => 'Ditarik',
        };
    }

    public function isLocked(): bool
    {
        return $this === self::FINAL;
    }

    public function isVisible(): bool
    {
        return $this !== self::WITHDRAWN;
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
