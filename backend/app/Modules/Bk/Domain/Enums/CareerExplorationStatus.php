<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Tahap penjelajahan karier siswa.
 *
 * `DECIDED` bukan tujuan akhir: siswa boleh kembali ke `EXPLORING` kapan saja, dan
 * modul ini tidak pernah mengunci pilihan hanya karena satu hasil asesmen.
 */
enum CareerExplorationStatus: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case EXPLORING = 'EXPLORING';
    case NARROWED = 'NARROWED';
    case DECIDED = 'DECIDED';
    case REVIEWING = 'REVIEWING';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Belum dimulai',
            self::EXPLORING => 'Menjelajah',
            self::NARROWED => 'Mulai menyempit',
            self::DECIDED => 'Sudah menentukan',
            self::REVIEWING => 'Sedang ditinjau ulang',
        };
    }

    /** Siswa masih boleh berpindah arah karier tanpa hambatan administratif. */
    public function isOpen(): bool
    {
        return $this !== self::NOT_STARTED ? true : false;
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NOT_STARTED => [self::EXPLORING, self::REVIEWING],
            self::EXPLORING => [self::NARROWED, self::DECIDED, self::REVIEWING],
            self::NARROWED => [self::EXPLORING, self::DECIDED, self::REVIEWING],
            self::DECIDED => [self::EXPLORING, self::REVIEWING],
            self::REVIEWING => [self::EXPLORING, self::NARROWED, self::DECIDED],
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
