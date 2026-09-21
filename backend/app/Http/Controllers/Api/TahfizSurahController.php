<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\QuranSurahResource;
use App\Models\QuranSurah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Referensi surah Al-Qur'an (114 surah).
 *
 * Tabel `quran_surahs` diisi seeder dan tidak diubah lewat aplikasi. Endpoint
 * ini hanya membacanya, agar form setoran/target/tahsin memilih surah dari
 * sumber yang sama dengan progress engine — bukan daftar yang ditulis di klien.
 */
class TahfizSurahController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = QuranSurah::query();

        $juz = (array) $request->query('filter', []);
        if (! empty($juz['juz'])) {
            $query->inJuz((int) $juz['juz']);
        }

        return $this->successResponse(
            QuranSurahResource::collection($query->orderBy('number')->get()),
            'Daftar surah berhasil diambil'
        );
    }
}