<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AcademicYear;
use App\Models\EducationLevel;
use App\Models\Gender;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Religion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Opsi dropdown untuk formulir & halaman daftar PPDB.
 *
 * Panitia penerimaan memegang `ppdb.*` saja — bukan `master-data.view`, juga
 * bukan `academic.view` — jadi tanpa endpoint ini pilihan jenis kelamin, agama,
 * jenjang, program sekolah, tahun ajaran, dan metode bayar pada halaman mereka
 * menjawab 403 dan tampil kosong tanpa pesan. Alternatifnya memberi panitia hak
 * baca SELURUH master data sekolah; itu bocor, maka referensinya dibaca lewat
 * whitelist tetap di sini.
 *
 * Penyaringan `is_active` mengikuti arah risikonya:
 * - jenjang & metode bayar disaring — yang dipilih adalah sesuatu yang berlaku
 *   SEKARANG, baris lama tidak pernah menunjuknya kembali;
 * - tahun ajaran & program sekolah TIDAK disaring — kolom `is_active` tahun
 *   ajaran berasumsi default `false` sehingga saringan menghapus isinya, dan
 *   penawaran PPDB yang sudah tersimpan boleh menunjuk program yang belakangan
 *   dinonaktifkan (menyingkirnya dari daftar membuat tautan itu hilang saat
 *   baris disimpan ulang).
 */
class PpdbLookupController extends BaseApiController
{
    /** GET /ppdb/lookups */
    public function index(): JsonResponse
    {
        return $this->successResponse([
            'genders' => $this->options(Gender::query()->orderBy('name'), ['id', 'code', 'name']),
            'religions' => $this->options(Religion::query()->orderBy('name')),
            'education_levels' => $this->options(
                EducationLevel::query()->where('is_active', true)->orderBy('level_number')->orderBy('name'),
                ['id', 'name', 'jenjang'],
            ),
            // `description` ikut karena formulir penawaran program memakainya sebagai
            // nilai awal; ini teks publik tentang program, bukan data pribadi.
            'programs' => $this->options(
                Program::query()->orderBy('code'),
                ['id', 'code', 'name', 'education_level_id', 'description'],
            ),
            'academic_years' => $this->options(AcademicYear::query()->orderByDesc('start_date')),
            'payment_methods' => $this->options(
                PaymentMethod::query()->where('is_active', true)->orderBy('name'),
                ['id', 'code', 'name'],
            ),
        ], 'Opsi PPDB berhasil diambil');
    }

    /**
     * Hanya kolom yang disebut di `$columns` yang keluar: tanpa `description`,
     * `created_at`, atau apa pun di luar kontrak whitelist.
     *
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function options(Builder $query, array $columns = ['id', 'name']): array
    {
        return $query->get($columns)
            ->map(fn (Model $row): array => array_intersect_key($row->getAttributes(), array_flip($columns)))
            ->all();
    }
}
