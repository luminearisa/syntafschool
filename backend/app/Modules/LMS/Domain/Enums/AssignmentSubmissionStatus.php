<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Siklus hidup submission (§5).
 *
 * DRAFT      : siswa masih menyusun jawaban, belum masuk penilaian.
 * SUBMITTED  : dikirim siswa, menunggu nilai.
 * RESUBMITTED: pengiriman ulang setelah dikembalikan (attempt baru).
 * GRADED     : sudah dinilai guru.
 * RETURNED   : dikembalikan tanpa nilai agar siswa memperbaiki.
 * REJECTED   : ditolak (mis. melewati cutoff).
 */
enum AssignmentSubmissionStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case RESUBMITTED = 'RESUBMITTED';
    case GRADED = 'GRADED';
    case RETURNED = 'RETURNED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Terkirim',
            self::RESUBMITTED => 'Terkirim ulang',
            self::GRADED => 'Sudah dinilai',
            self::RETURNED => 'Dikembalikan',
            self::REJECTED => 'Ditolak',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::DRAFT => 'slate',
            self::SUBMITTED, self::RESUBMITTED => 'blue',
            self::GRADED => 'green',
            self::RETURNED => 'amber',
            self::REJECTED => 'red',
        };
    }

    /** Sudah dikirim ke guru (bukan draf). */
    public function isSubmitted(): bool
    {
        return in_array($this, [self::SUBMITTED, self::RESUBMITTED, self::GRADED, self::RETURNED], true);
    }

    /** Masih dapat diubah siswa. */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::RETURNED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
