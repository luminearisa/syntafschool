<?php

namespace App\Modules\Hrd\Domain\Enums;

/**
 * Tingkat kerahasiaan data & dokumen (§52).
 *
 * Dipakai dua hal sekaligus: menentukan siapa yang boleh membaca sebuah dokumen,
 * dan menentukan sejauh mana linimasa pegawai boleh ditampilkan. Nilainya
 * berurutan — pembaca dengan tingkat `manager` otomatis boleh membaca `employee`
 * dan `public`, tetapi tidak sebaliknya.
 */
enum DocumentVisibility: string
{
    case PRIVATE = 'private';
    case HR = 'hr';
    case MANAGER = 'manager';
    case EMPLOYEE = 'employee';
    case PUBLIC = 'public';

    public function rank(): int
    {
        return match ($this) {
            self::PRIVATE => 0,
            self::HR => 1,
            self::MANAGER => 2,
            self::EMPLOYEE => 3,
            self::PUBLIC => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PRIVATE => 'Sangat Terbatas (HR Inti)',
            self::HR => 'HR',
            self::MANAGER => 'Atasan',
            self::EMPLOYEE => 'Pegawai',
            self::PUBLIC => 'Umum',
        };
    }

    /** Tingkat yang boleh dibaca pemanggil dengan tingkat akses tertentu. */
    public static function readableAt(string $level): array
    {
        $levelRank = match ($level) {
            'private' => self::PRIVATE->rank(),
            'hr' => self::HR->rank(),
            'manager' => self::MANAGER->rank(),
            'employee' => self::EMPLOYEE->rank(),
            default => self::PUBLIC->rank(),
        };

        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->rank() <= $levelRank),
        ));
    }
}
