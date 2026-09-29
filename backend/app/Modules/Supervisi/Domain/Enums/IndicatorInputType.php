<?php

namespace App\Modules\Supervisi\Domain\Enums;

/**
 * Tipe input indikator — inilah yang membuat instrumen fleksibel:
 * satu indikator dapat meminta pilihan skala rubrik, entri angka, ya/tidak,
 * maupun teks/bukti.
 */
enum IndicatorInputType: string
{
    case SCALE = 'SCALE';                 // pilih satu opsi rubrik (mis. 1–4)
    case NUMBER = 'NUMBER';               // entri angka langsung (min/max/step)
    case BOOLEAN = 'BOOLEAN';             // ya / tidak / sebagian
    case MULTI_CHOICE = 'MULTI_CHOICE';   // beberapa pilihan (skor dijumlahkan)
    case TEXT = 'TEXT';                   // isian pendek
    case LONG_TEXT = 'LONG_TEXT';         // narasi (wawancara, catatan fakta)
    case DATE = 'DATE';                   // tanggal
    case FILE = 'FILE';                   // lampiran bukti

    public function label(): string
    {
        return match ($this) {
            self::SCALE => 'Skala / Rubrik Pilihan',
            self::NUMBER => 'Angka (Entri Langsung)',
            self::BOOLEAN => 'Ya / Tidak / Sebagian',
            self::MULTI_CHOICE => 'Pilihan Ganda (Skor Dijumlah)',
            self::TEXT => 'Teks Pendek',
            self::LONG_TEXT => 'Uraian',
            self::DATE => 'Tanggal',
            self::FILE => 'Lampiran Berkas',
        };
    }

    /** Apakah indikator ini menyumbang skor? */
    public function isScored(): bool
    {
        return in_array($this, [self::SCALE, self::NUMBER, self::BOOLEAN, self::MULTI_CHOICE], true);
    }

    /** Butuh daftar opsi di `sup_indicator_options`? */
    public function usesOptions(): bool
    {
        return in_array($this, [self::SCALE, self::MULTI_CHOICE], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
