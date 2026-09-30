<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum VisitStatus — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum VisitStatus: string
{
    case REGISTERED = 'REGISTERED';
    case TRIAGED = 'TRIAGED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case OBSERVATION = 'OBSERVATION';
    case REFERRED = 'REFERRED';
    case DISCHARGED = 'DISCHARGED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REGISTERED => 'Terdaftar',
            self::TRIAGED => 'Sudah Triase',
            self::IN_PROGRESS => 'Dalam Penanganan',
            self::OBSERVATION => 'Observasi',
            self::REFERRED => 'Dirujuk',
            self::DISCHARGED => 'Sudah Dipulangkan',
            self::CANCELLED => 'Dibatalkan',
        };
    }


    /** Status akhir: kunjungan tidak boleh diubah lagi setelah ini. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::DISCHARGED, self::CANCELLED], true);
    }

    /** Apakah kunjungan masih boleh menerima catatan klinis baru. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
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
