<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Jenis konten interaktif (§7).
 *
 * Satu tipe = satu driver di `InteractiveContentRegistry`. Menambah tipe baru
 * cukup mendaftarkan driver baru tanpa menyentuh mesin course.
 */
enum InteractiveContentType: string
{
    case INTERACTIVE_VIDEO = 'INTERACTIVE_VIDEO';
    case SLIDES = 'SLIDES';
    case FLASHCARD = 'FLASHCARD';
    case MATCHING = 'MATCHING';
    case TIMELINE = 'TIMELINE';
    case INTERACTIVE_QUIZ = 'INTERACTIVE_QUIZ';

    public function label(): string
    {
        return match ($this) {
            self::INTERACTIVE_VIDEO => 'Video Interaktif',
            self::SLIDES => 'Presentasi',
            self::FLASHCARD => 'Flashcard',
            self::MATCHING => 'Mencocokkan',
            self::TIMELINE => 'Timeline',
            self::INTERACTIVE_QUIZ => 'Kuis Interaktif',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::INTERACTIVE_VIDEO => 'play-circle',
            self::SLIDES => 'presentation',
            self::FLASHCARD => 'layers',
            self::MATCHING => 'shuffle',
            self::TIMELINE => 'history',
            self::INTERACTIVE_QUIZ => 'help-circle',
        };
    }

    /** Apakah hasilnya dapat dinilai otomatis. */
    public function isAutoGradable(): bool
    {
        return in_array($this, [self::MATCHING, self::INTERACTIVE_QUIZ, self::INTERACTIVE_VIDEO], true);
    }

    /** Butuh media video sebagai sumber. */
    public function usesVideo(): bool
    {
        return $this === self::INTERACTIVE_VIDEO;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
