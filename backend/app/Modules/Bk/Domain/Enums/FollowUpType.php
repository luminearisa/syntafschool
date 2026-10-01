<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Bentuk tindak lanjut.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum FollowUpType: string
{
    case CHECK_IN = 'CHECK_IN';
    case SESSION = 'SESSION';
    case OBSERVATION = 'OBSERVATION';
    case PARENT_CONTACT = 'PARENT_CONTACT';
    case TEACHER_FEEDBACK = 'TEACHER_FEEDBACK';
    case PROGRESS_REVIEW = 'PROGRESS_REVIEW';
    case DOCUMENTATION = 'DOCUMENTATION';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::CHECK_IN => 'Pemeriksaan singkat',
            self::SESSION => 'Sesi lanjutan',
            self::OBSERVATION => 'Observasi kelas/asrama',
            self::PARENT_CONTACT => 'Kontak orang tua',
            self::TEACHER_FEEDBACK => 'Umpan balik guru',
            self::PROGRESS_REVIEW => 'Tinjauan kemajuan',
            self::DOCUMENTATION => 'Kelengkapan dokumen',
            self::OTHER => 'Lain-lain',
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
