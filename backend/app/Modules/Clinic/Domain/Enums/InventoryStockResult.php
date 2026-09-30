<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum InventoryStockResult — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum InventoryStockResult: string
{
    case PENDING = 'PENDING';
    case SUCCESS = 'SUCCESS';
    case INSUFFICIENT_STOCK = 'INSUFFICIENT_STOCK';
    case EXPIRED = 'EXPIRED';
    case NOT_AVAILABLE = 'NOT_AVAILABLE';
    case FAILED = 'FAILED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::SUCCESS => 'Berhasil',
            self::INSUFFICIENT_STOCK => 'Stok Kurang',
            self::EXPIRED => 'Kedaluwarsa',
            self::NOT_AVAILABLE => 'Tidak Tersedia',
            self::FAILED => 'Gagal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
