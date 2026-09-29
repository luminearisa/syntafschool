<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Jenis kegiatan supervisi (menentukan panjang instrumen & bobotnya). */
enum ActivityKind: string
{
    case WALKTHROUGH = 'WALKTHROUGH';                 // kunjungan singkat, umpan balik cepat
    case CLASS_OBSERVATION = 'CLASS_OBSERVATION';     // observasi kelas penuh + instrumen
    case CLINICAL = 'CLINICAL';                       // supervisi klinis individual
    case PEER = 'PEER';                               // supervisi sejawat
    case LESSON_STUDY = 'LESSON_STUDY';               // plan–do–see
    case COACHING = 'COACHING';                       // percakapan coaching (PMM: diskusi)
    case DOCUMENT_REVIEW = 'DOCUMENT_REVIEW';         // telaah perangkat/RPP/soal
    case STAFF_REVIEW = 'STAFF_REVIEW';               // supervisi tendik/manajerial
    case PROGRAM_EVALUATION = 'PROGRAM_EVALUATION';   // evaluasi program (CIPP)

    public function label(): string
    {
        return match ($this) {
            self::WALKTHROUGH => 'Kunjungan Kelas Singkat',
            self::CLASS_OBSERVATION => 'Observasi Kelas',
            self::CLINICAL => 'Supervisi Klinis',
            self::PEER => 'Supervisi Sejawat',
            self::LESSON_STUDY => 'Lesson Study',
            self::COACHING => 'Coaching / Diskusi',
            self::DOCUMENT_REVIEW => 'Telaah Dokumen',
            self::STAFF_REVIEW => 'Supervisi Tenaga Kependidikan',
            self::PROGRAM_EVALUATION => 'Evaluasi Program',
        };
    }

    /** Kegiatan yang lazim memerlukan pra-observasi & pasca-observasi formal. */
    public function requiresConferences(): bool
    {
        return in_array($this, [self::CLASS_OBSERVATION, self::CLINICAL, self::LESSON_STUDY], true);
    }

    /** Walkthrough tidak wajib memakai instrumen penuh (cukup fokus singkat). */
    public function isLightweight(): bool
    {
        return in_array($this, [self::WALKTHROUGH, self::COACHING], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
