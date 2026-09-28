<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Tipe soal bank soal (§15).
 *
 * Penilaian setiap tipe dilakukan `QuestionGrader` yang diregistrasikan pada
 * `QuestionTypeRegistry` — menambah tipe baru (DRAG_DROP, CLOZE, ORDERING)
 * cukup menambah grader, tanpa menyentuh mesin kuis.
 */
enum QuestionType: string
{
    case MULTIPLE_CHOICE_SINGLE = 'MULTIPLE_CHOICE_SINGLE';
    case MULTIPLE_CHOICE_MULTIPLE = 'MULTIPLE_CHOICE_MULTIPLE';
    case TRUE_FALSE = 'TRUE_FALSE';
    case SHORT_ANSWER = 'SHORT_ANSWER';
    case NUMERICAL = 'NUMERICAL';
    case ESSAY = 'ESSAY';
    case MATCHING = 'MATCHING';
    case DRAG_DROP = 'DRAG_DROP';
    case CLOZE = 'CLOZE';
    case ORDERING = 'ORDERING';

    public function label(): string
    {
        return match ($this) {
            self::MULTIPLE_CHOICE_SINGLE => 'Pilihan ganda (satu jawaban)',
            self::MULTIPLE_CHOICE_MULTIPLE => 'Pilihan ganda (banyak jawaban)',
            self::TRUE_FALSE => 'Benar / Salah',
            self::SHORT_ANSWER => 'Jawaban singkat',
            self::NUMERICAL => 'Numerik',
            self::ESSAY => 'Esai',
            self::MATCHING => 'Menjodohkan',
            self::DRAG_DROP => 'Drag & drop',
            self::CLOZE => 'Isian rumpang',
            self::ORDERING => 'Mengurutkan',
        };
    }

    /** Tipe yang dapat dinilai otomatis oleh server (§20). */
    public function isAutoGradable(): bool
    {
        return $this !== self::ESSAY;
    }

    /** Tipe yang sudah diimplementasikan pada Phase 2. */
    public function isImplemented(): bool
    {
        return in_array($this, [
            self::MULTIPLE_CHOICE_SINGLE,
            self::MULTIPLE_CHOICE_MULTIPLE,
            self::TRUE_FALSE,
            self::SHORT_ANSWER,
            self::NUMERICAL,
            self::ESSAY,
            self::MATCHING,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
