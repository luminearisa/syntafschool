<?php

namespace App\Modules\Hrd\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * Penanda controller HRD yang belum diimplementasikan.
 *
 * Sebagian controller inti modul HRD — Employee, Organization, Position,
 * Employment, Lifecycle, Document, Report, SelfService, dan DataQuality —
 * tidak pernah disertakan pada commit modul (§88), padahal `Routes/api.php`
 * sudah memuat 150+ endpoint yang merujuknya. Akibatnya:
 *
 *  - setiap permintaan ke `/api/v1/hrd/*` gagal fatal "Class not found" (500);
 *  - `php artisan route:list` ikut berhenti karena refleksi class gagal.
 *
 * Trait ini membuat class-nya tetap ada sehingga:
 *
 *  - `route:list` dapat dijalankan (cukup butuh class, bukan method-nya);
 *  - endpoint yang belum siap membalas `501 Not Implemented` yang rapi,
 *    bukan error 500 yang membocorkan jejak;
 *  - otorisasi (`permission:hr.*`) tetap berjalan — middleware dieksekusi
 *    sebelum controller, jadi endpoint tetap tertutup bagi yang tak berhak.
 *
 * Saat controller sesungguhnya diimplementasikan, cukup hapus trait ini dari
 * class bersangkutan; tidak ada perubahan lain yang diperlukan.
 */
trait NotImplementedHrd
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): JsonResponse
    {
        return response()->json([
            'data' => null,
            'message' => 'Fitur HRD "'.class_basename(static::class).'::'.$method.'" belum diimplementasikan.',
        ], 501);
    }
}
