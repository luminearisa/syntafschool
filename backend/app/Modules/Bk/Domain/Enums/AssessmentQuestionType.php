<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis pertanyaan asesmen yang dapat dikonfigurasi.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum AssessmentQuestionType: string
{
    case TEXT = 'TEXT';
    case TEXTAREA = 'TEXTAREA';
    case SINGLE_CHOICE = 'SINGLE_CHOICE';
    case MULTIPLE_CHOICE = 'MULTIPLE_CHOICE';
    case SCALE = 'SCALE';
    case BOOLEAN = 'BOOLEAN';
    case NUMBER = 'NUMBER';
    case DATE = 'DATE';
    case RATING = 'RATING';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::TEXT => 'Isian singkat',
            self::TEXTAREA => 'Uraian',
            self::SINGLE_CHOICE => 'Pilihan tunggal',
            self::MULTIPLE_CHOICE => 'Pilihan ganda',
            self::SCALE => 'Skala',
            self::BOOLEAN => 'Ya/Tidak',
            self::NUMBER => 'Angka',
            self::DATE => 'Tanggal',
            self::RATING => 'Peringkat',
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

    /** Pertanyaan bertipe pilihan wajib menyertakan daftar opsi. */
    public function requiresOptions(): bool
    {
        return in_array($this, [self::SINGLE_CHOICE, self::MULTIPLE_CHOICE, self::RATING], true);
    }

    /** Jawaban disimpan pada kolom numerik, bukan teks. */
    public function isNumericAnswer(): bool
    {
        return in_array($this, [self::SCALE, self::NUMBER], true);
    }
}
