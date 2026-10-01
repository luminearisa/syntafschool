<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Operator yang dikenali Condition Engine (§30).
 *
 * Daftar ini adalah daftar **tertutup**: kondisi pada definisi divalidasi
 * terhadap enum ini saat publish, sehingga tidak ada ekspresi yang lolos ke
 * runtime lalu gagal diam-diam.
 */
enum ConditionOperator: string
{
    case EQUALS = '=';
    case NOT_EQUALS = '!=';
    case GREATER_THAN = '>';
    case GREATER_THAN_OR_EQUAL = '>=';
    case LESS_THAN = '<';
    case LESS_THAN_OR_EQUAL = '<=';
    case IN = 'IN';
    case NOT_IN = 'NOT_IN';
    case CONTAINS = 'CONTAINS';
    case BETWEEN = 'BETWEEN';
    case IS_EMPTY = 'IS_EMPTY';
    case IS_NOT_EMPTY = 'IS_NOT_EMPTY';

    /** Jumlah argumen yang diharapkan pada sisi kanan. */
    public function expectsList(): bool
    {
        return in_array($this, [self::IN, self::NOT_IN, self::BETWEEN], true);
    }

    public function expectsValue(): bool
    {
        return ! in_array($this, [self::IS_EMPTY, self::IS_NOT_EMPTY], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::EQUALS => 'Sama dengan',
            self::NOT_EQUALS => 'Tidak sama dengan',
            self::GREATER_THAN => 'Lebih besar dari',
            self::GREATER_THAN_OR_EQUAL => 'Lebih besar atau sama dengan',
            self::LESS_THAN => 'Lebih kecil dari',
            self::LESS_THAN_OR_EQUAL => 'Lebih kecil atau sama dengan',
            self::IN => 'Termasuk dalam daftar',
            self::NOT_IN => 'Tidak termasuk dalam daftar',
            self::CONTAINS => 'Mengandung',
            self::BETWEEN => 'Di antara dua nilai',
            self::IS_EMPTY => 'Kosong',
            self::IS_NOT_EMPTY => 'Tidak kosong',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
