<?php

namespace App\Http\Controllers\Api;

use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Skala nilai: angka → huruf + predikat (A7 — konfigurasi).
 *
 * Rentang huruf disimpan sebagai baris terpisah supaya sekolah bisa mengubah
 * ambang A/B/C tanpa menyentuh kode. Yang dijaga di sini: rentang tidak boleh
 * tumpang tindih dan satu huruf tidak boleh dipakai dua kali.
 */
class GradeScaleController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = GradeScale::query()
            ->with(['items', 'educationLevel'])
            ->when($request->boolean('only_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse($query->get(), 'Skala nilai berhasil diambil');
        }

        return $this->successResponse(
            $query->paginate((int) $request->query('per_page', 15)),
            'Skala nilai berhasil diambil',
        );
    }

    public function show(GradeScale $scale): JsonResponse
    {
        return $this->successResponse(
            $scale->load(['items', 'educationLevel', 'curriculum']),
            'Detail skala nilai berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $items = $data['items'] ?? [];
        unset($data['items']);

        $scale = DB::transaction(function () use ($data, $items) {
            $scale = GradeScale::create($data);
            $this->syncItems($scale, $items);

            return $scale;
        });

        return $this->createdResponse(
            $scale->load(['items', 'educationLevel']),
            'Skala nilai berhasil dibuat',
        );
    }

    public function update(Request $request, GradeScale $scale): JsonResponse
    {
        $data = $this->validateData($request, $scale);
        $items = $data['items'] ?? null;
        unset($data['items']);

        DB::transaction(function () use ($scale, $data, $items) {
            $scale->update($data);

            // Items hanya diganti bila request memang mengirim daftarnya.
            if ($items !== null) {
                $this->syncItems($scale, $items);
            }
        });

        return $this->successResponse(
            $scale->refresh()->load(['items', 'educationLevel']),
            'Skala nilai berhasil diperbarui',
        );
    }

    public function destroy(GradeScale $scale): JsonResponse
    {
        $scale->delete();

        return $this->successResponse(null, 'Skala nilai berhasil dihapus');
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function validateData(Request $request, ?GradeScale $scale = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('grade_scales', 'code')->ignore($scale?->id)->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'curriculum_id' => ['nullable', 'integer', 'exists:curriculums,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
            'is_active' => ['boolean'],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.min_score' => ['required', 'numeric', 'between:0,100'],
            'items.*.max_score' => ['required', 'numeric', 'between:0,100'],
            'items.*.grade' => ['required', 'string', 'max:5'],
            'items.*.predicate' => ['nullable', 'string', 'max:50'],
            'items.*.description' => ['nullable', 'string'],
        ], [], [
            'code' => 'kode',
            'name' => 'nama',
            'curriculum_id' => 'kurikulum',
            'education_level_id' => 'jenjang',
            'items' => 'rentang nilai',
            'items.*.min_score' => 'nilai terendah',
            'items.*.max_score' => 'nilai tertinggi',
            'items.*.grade' => 'huruf',
            'items.*.predicate' => 'predikat',
        ]);

        if (isset($data['items'])) {
            $this->assertItemsValid($data['items']);
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function assertItemsValid(array $items): void
    {
        foreach ($items as $item) {
            if ((float) $item['min_score'] > (float) $item['max_score']) {
                throw ValidationException::withMessages([
                    'items' => "Rentang {$item['grade']}: nilai terendah tidak boleh lebih besar dari nilai tertinggi.",
                ]);
            }
        }

        $grades = array_column($items, 'grade');

        if (count($grades) !== count(array_unique(array_map('strtolower', $grades)))) {
            throw ValidationException::withMessages([
                'items' => 'Satu huruf tidak boleh dipakai pada lebih dari satu rentang.',
            ]);
        }

        usort($items, fn ($a, $b) => (float) $a['min_score'] <=> (float) $b['min_score']);

        for ($i = 1; $i < count($items); $i++) {
            if ((float) $items[$i]['min_score'] <= (float) $items[$i - 1]['max_score']) {
                throw ValidationException::withMessages([
                    'items' => "Rentang {$items[$i]['grade']} tumpang tindih dengan {$items[$i - 1]['grade']}.",
                ]);
            }
        }
    }

    /**
     * Ganti seluruh rentang pada skala — tanpa orphan record.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function syncItems(GradeScale $scale, array $items): void
    {
        $keep = [];

        foreach ($items as $item) {
            $row = GradeScaleItem::updateOrCreate(
                [
                    'grade_scale_id' => $scale->id,
                    'grade' => $item['grade'],
                ],
                [
                    'min_score' => $item['min_score'],
                    'max_score' => $item['max_score'],
                    'predicate' => $item['predicate'] ?? null,
                    'description' => $item['description'] ?? null,
                ],
            );

            $keep[] = $row->id;
        }

        $scale->items()->whereNotIn('id', $keep)->delete();
    }
}
