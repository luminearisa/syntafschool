<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Referensi satu surah Al-Qur'an.
 *
 * Dipakai form setoran/target/tahsin untuk memilih surah sekaligus mengetahui
 * batas ayat & halaman — supaya validasi di klien dan server memakai sumber
 * yang sama (`quran_surahs`).
 */
class QuranSurahResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'arabic_name' => $this->arabic_name,
            'translation' => $this->translation,
            'ayah_count' => $this->ayah_count,
            'page_start' => $this->page_start,
            'page_end' => $this->page_end,
            'juz_start' => $this->juz_start,
            'juz_end' => $this->juz_end,
            'revelation_place' => $this->revelation_place,
        ];
    }
}