<?php

namespace App\Http\Controllers\Api;

use App\Models\AcademicYear;
use App\Models\AssessmentType;
use App\Models\AssessmentWeight;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bobot penilaian per kelas (A7 — "assessment scheme").
 *
 * Menjawab: "dari nilai akhir mapel di kelas ini, berapa persen kontribusi
 * kuis, tugas, praktik, PTS, dan PAS?" Jumlahnya harus 100% — kalau tidak,
 * nilai akhir tetap bisa dihitung (dibagi bobot yang ada) tetapi itu hampir
 * selalu berarti ada jenis yang belum diberi bobot.
 */
class AssessmentWeightController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ], [], [
            'class_id' => 'kelas',
            'academic_year_id' => 'tahun ajaran',
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);
        $year = $this->academicYear($data['academic_year_id'] ?? null);

        $weights = AssessmentWeight::query()
            ->where('school_class_id', $class->id)
            ->where('academic_year_id', $year->id)
            ->get()
            ->keyBy('assessment_type_id');

        $items = AssessmentType::query()
            ->active()
            ->ordered()
            ->get()
            ->map(fn (AssessmentType $type) => [
                'id' => $weights->get($type->id)?->id,
                'assessment_type_id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'category' => $type->category,
                'weight' => $weights->has($type->id) ? (float) $weights->get($type->id)->weight : null,
            ])
            ->values()
            ->all();

        return $this->successResponse([
            'class' => ['id' => $class->id, 'name' => $class->name, 'code' => $class->code],
            'academic_year' => ['id' => $year->id, 'name' => $year->name],
            'weight_total' => round(array_sum(array_column($items, 'weight')), 2),
            'weights_complete' => abs(array_sum(array_column($items, 'weight')) - 100) < 0.01,
            'items' => $items,
        ], 'Bobot penilaian berhasil diambil');
    }

    /**
     * Simpan massal bobot untuk satu kelas. Baris yang sudah ada diperbarui.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.assessment_type_id' => ['required', 'integer', 'exists:assessment_types,id'],
            'entries.*.weight' => ['required', 'numeric', 'between:0,100'],
        ], [], [
            'class_id' => 'kelas',
            'academic_year_id' => 'tahun ajaran',
            'entries' => 'daftar bobot',
            'entries.*.assessment_type_id' => 'jenis penilaian',
            'entries.*.weight' => 'bobot',
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);
        $year = $this->academicYear($data['academic_year_id'] ?? null);

        $this->assertTypesDistinct($data['entries']);
        $this->assertNotExceedingTotal($data['entries'], $class, $year);

        $saved = 0;

        DB::transaction(function () use ($data, $class, $year, &$saved) {
            foreach ($data['entries'] as $entry) {
                AssessmentWeight::updateOrCreate(
                    [
                        'school_class_id' => $class->id,
                        'academic_year_id' => $year->id,
                        'assessment_type_id' => (int) $entry['assessment_type_id'],
                    ],
                    ['weight' => $entry['weight']],
                );

                $saved++;
            }
        });

        return $this->successResponse([
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'saved' => $saved,
            'weight_total' => $this->totalFor($class, $year),
        ], 'Bobot penilaian berhasil disimpan');
    }

    public function update(Request $request, AssessmentWeight $weight): JsonResponse
    {
        $data = $request->validate([
            'weight' => ['required', 'numeric', 'between:0,100'],
        ], [], [
            'weight' => 'bobot',
        ]);

        $weight->update($data);

        return $this->successResponse($weight->refresh(), 'Bobot penilaian berhasil diperbarui');
    }

    /**
     * Menghapus baris bobot membuat jenis itu tidak ikut dihitung lagi.
     * Rekap nilai akan menandainya sebagai bobot yang belum lengkap (lihat
     * `weights_complete` pada gradebook), bukan menghasilkan nilai yang salah.
     */
    public function destroy(AssessmentWeight $weight): JsonResponse
    {
        $weight->delete();

        return $this->successResponse(null, 'Bobot penilaian berhasil dihapus');
    }

    // -----------------------------------------------------------------

    protected function academicYear(?int $id): AcademicYear
    {
        if ($id) {
            return AcademicYear::findOrFail($id);
        }

        $year = AcademicYear::query()->where('is_active', true)->orderBy('id')->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'Tahun ajaran aktif belum ditetapkan, pilih tahun ajaran secara eksplisit.',
            ]);
        }

        return $year;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    protected function assertTypesDistinct(array $entries): void
    {
        $ids = array_column($entries, 'assessment_type_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'entries' => 'Satu jenis penilaian tidak boleh diberi bobot lebih dari sekali.',
            ]);
        }
    }

    /**
     * Total bobot seluruh jenis di kelas ini tidak boleh lewat 100%.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    protected function assertNotExceedingTotal(array $entries, SchoolClass $class, AcademicYear $year): void
    {
        $incoming = array_column($entries, 'weight', 'assessment_type_id');

        $total = 0.0;

        // Bobot jenis yang tidak dikirim hari ini tetap dihitung; yang dikirim
        // memakai nilai barunya.
        $untouched = AssessmentWeight::query()
            ->where('school_class_id', $class->id)
            ->where('academic_year_id', $year->id)
            ->whereNotIn('assessment_type_id', array_map('intval', array_keys($incoming)))
            ->sum('weight');

        $total += (float) $untouched + array_sum(array_map('floatval', $incoming));

        if ($total > 100.01) {
            throw ValidationException::withMessages([
                'entries' => 'Total bobot penilaian di kelas ini melebihi 100% ('.round($total, 2).'%).',
            ]);
        }
    }

    protected function totalFor(SchoolClass $class, AcademicYear $year): float
    {
        return round((float) AssessmentWeight::query()
            ->where('school_class_id', $class->id)
            ->where('academic_year_id', $year->id)
            ->sum('weight'), 2);
    }
}
