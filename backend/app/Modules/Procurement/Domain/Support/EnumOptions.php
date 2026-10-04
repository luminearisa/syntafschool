<?php

namespace App\Modules\Procurement\Domain\Support;

/**
 * Boilerplate enum pengadaan: `values()` untuk aturan validasi (`Rule::in`)
 * dan `options()` untuk dropdown frontend. Setiap enum hanya perlu
 * mendefinisikan `label()`.
 */
trait EnumOptions
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    public static function labelFor(?string $value): ?string
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case->label();
            }
        }

        return null;
    }
}
