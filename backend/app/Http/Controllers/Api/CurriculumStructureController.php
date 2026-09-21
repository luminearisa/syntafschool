<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Curriculum\ApplyCurriculumRequest;
use App\Http\Requests\Curriculum\CurriculumStructureRequest;
use App\Http\Requests\Curriculum\DuplicateCurriculumStructureRequest;
use App\Http\Resources\CurriculumStructureResource;
use App\Models\ClassSubject;
use App\Models\Curriculum;
use App\Models\CurriculumStructure;
use App\Models\CurriculumStructureItem;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Struktur kurikulum berversi per (kurikulum, jenjang).
 *
 * Aturan bisnis:
 *  - versi unik per (kurikulum, jenjang);
 *  - satu mapel tidak boleh dobel pada semester yang sama dalam satu struktur;
 *  - `weekly_hours` 1–12 dan `semester` hanya 1/2 (validasi request);
 *  - struktur yang sudah dipakai kelas tidak boleh dihapus;
 *  - `apply-curriculum` idempoten dan hanya untuk jenjang yang cocok.
 */
class CurriculumStructureController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = [
        'curriculum_id',
        'education_level_id',
        'academic_year_id',
        'is_active',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = CurriculumStructure::query()
            ->with($this->relations())
            ->withCount('items');

        $this->applyFilters($query, $request);

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $query->orderBy('curriculum_id')
            ->orderBy('education_level_id')
            ->orderByDesc('version');

        if ($request->boolean('all')) {
            return $this->successResponse(
                CurriculumStructureResource::collection($query->get()),
                'Struktur kurikulum berhasil diambil',
            );
        }

        return $this->successResponse(
            CurriculumStructureResource::collection($query->paginate((int) $request->query('per_page', 50))),
            'Struktur kurikulum berhasil diambil',
        );
    }

    public function store(CurriculumStructureRequest $request): JsonResponse
    {
        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        $data['academic_year_id'] = $data['academic_year_id'] ?? null;
        $data['is_active'] = $request->boolean('is_active', true);

        $this->assertVersionUnique($data);
        $this->assertNoDuplicateItems($items);

        $structure = DB::transaction(function () use ($data, $items) {
            $structure = CurriculumStructure::create($data);
            $this->syncItems($structure, $items);

            return $structure;
        });

        return $this->createdResponse(
            new CurriculumStructureResource($structure->load($this->relations())->loadCount('items')),
            'Struktur kurikulum berhasil ditambahkan',
        );
    }

    public function show(CurriculumStructure $curriculumStructure): JsonResponse
    {
        return $this->successResponse(
            new CurriculumStructureResource(
                $curriculumStructure->load($this->relations())->loadCount('items')
            ),
            'Detail struktur kurikulum berhasil diambil',
        );
    }

    public function update(CurriculumStructureRequest $request, CurriculumStructure $curriculumStructure): JsonResponse
    {
        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        $data['academic_year_id'] = $data['academic_year_id'] ?? null;

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $this->assertVersionUnique($data, $curriculumStructure->id);
        $this->assertNoDuplicateItems($items);

        DB::transaction(function () use ($curriculumStructure, $data, $items) {
            $curriculumStructure->update($data);
            $curriculumStructure->items()->delete();
            $this->syncItems($curriculumStructure, $items);
        });

        return $this->successResponse(
            new CurriculumStructureResource(
                $curriculumStructure->refresh()->load($this->relations())->loadCount('items')
            ),
            'Struktur kurikulum berhasil diperbarui',
        );
    }

    public function destroy(CurriculumStructure $curriculumStructure): JsonResponse
    {
        // Struktur yang lahir di kelas lewat apply-curriculum tidak boleh hilang,
        // karena kelas kehilangan rujukan versi kurikulum yang sedang dipakai.
        $inUse = ClassSubject::where('curriculum_structure_id', $curriculumStructure->id)->exists();

        if ($inUse) {
            return $this->errorResponse(
                'Struktur kurikulum ini sudah dipakai kelas dan tidak boleh dihapus.',
                422,
            );
        }

        $curriculumStructure->delete();

        return $this->successResponse(null, 'Struktur kurikulum berhasil dihapus');
    }

    /**
     * Seluruh struktur milik satu kurikulum.
     */
    public function byCurriculum(Curriculum $curriculum): JsonResponse
    {
        $structures = CurriculumStructure::query()
            ->where('curriculum_id', $curriculum->id)
            ->with($this->relations())
            ->withCount('items')
            ->orderBy('education_level_id')
            ->orderByDesc('version')
            ->get();

        return $this->successResponse(
            CurriculumStructureResource::collection($structures),
            'Struktur kurikulum berhasil diambil',
        );
    }

    /**
     * Salin struktur + seluruh itemnya ke versi baru.
     */
    public function duplicate(
        DuplicateCurriculumStructureRequest $request,
        CurriculumStructure $curriculumStructure,
    ): JsonResponse {
        $data = $request->validated();
        $version = (int) $data['version'];

        $exists = CurriculumStructure::withTrashed()
            ->where('curriculum_id', $curriculumStructure->curriculum_id)
            ->where('education_level_id', $curriculumStructure->education_level_id)
            ->where('version', $version)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'version' => 'Versi ini sudah dipakai untuk kombinasi kurikulum dan jenjang tersebut.',
            ]);
        }

        $copy = DB::transaction(function () use ($curriculumStructure, $data, $version) {
            $copy = $curriculumStructure->replicate();
            $copy->version = $version;
            $copy->name = $data['name'] ?? ($curriculumStructure->name.' (v'.$version.')');
            $copy->save();

            foreach ($curriculumStructure->items as $item) {
                CurriculumStructureItem::create([
                    'curriculum_structure_id' => $copy->id,
                    'subject_id' => $item->subject_id,
                    'subject_group_id' => $item->subject_group_id,
                    'semester' => $item->semester,
                    'weekly_hours' => $item->weekly_hours,
                    'is_required' => $item->is_required,
                    'sort_order' => $item->sort_order,
                    'notes' => $item->notes,
                ]);
            }

            return $copy;
        });

        return $this->createdResponse(
            new CurriculumStructureResource($copy->load($this->relations())->loadCount('items')),
            'Struktur kurikulum berhasil diduplikasi',
        );
    }

    /**
     * Struktur yang berlaku untuk sebuah kelas + daftar mapel yang seharusnya.
     */
    public function classCurriculum(Request $request, SchoolClass $class): JsonResponse
    {
        $structure = null;

        // Struktur yang sudah benar-benar diterapkan ke kelas lebih diutamakan.
        $appliedStructureId = ClassSubject::query()
            ->where('school_class_id', $class->id)
            ->whereNotNull('curriculum_structure_id')
            ->orderByDesc('id')
            ->value('curriculum_structure_id');

        if ($appliedStructureId) {
            $structure = CurriculumStructure::with($this->relations())->find($appliedStructureId);
        }

        if (! $structure) {
            $structure = $this->structureForClass($class, $request->integer('curriculum_id') ?: null);
        }

        $items = $structure ? $structure->items : collect();

        $expected = $items->map(fn (CurriculumStructureItem $item) => [
            'subject_id' => $item->subject_id,
            'code' => $item->subject?->code,
            'name' => $item->subject?->name,
            'semester' => (int) $item->semester,
            'weekly_hours' => (int) $item->weekly_hours,
            'is_required' => (bool) $item->is_required,
            'subject_group' => $item->subjectGroup ? [
                'id' => $item->subjectGroup->id,
                'code' => $item->subjectGroup->code,
                'name' => $item->subjectGroup->name,
            ] : null,
        ])->values();

        $applied = ClassSubject::query()
            ->with('subject:id,code,name')
            ->where('school_class_id', $class->id)
            ->get()
            ->map(fn (ClassSubject $classSubject) => [
                'class_subject_id' => $classSubject->id,
                'subject_id' => $classSubject->subject_id,
                'code' => $classSubject->subject?->code,
                'name' => $classSubject->subject?->name,
                'employee_id' => $classSubject->employee_id,
                'from_structure' => $structure
                    ? (int) $classSubject->curriculum_structure_id === (int) $structure->id
                    : false,
            ])
            ->values();

        $appliedIds = $applied->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

        $missing = $expected
            ->reject(fn (array $row) => in_array((int) $row['subject_id'], $appliedIds, true))
            ->pluck('subject_id')
            ->values();

        return $this->successResponse([
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'code' => $class->code,
                'education_level_id' => $class->education_level_id,
                'academic_year_id' => $class->academic_year_id,
            ],
            'structure' => $structure
                ? (new CurriculumStructureResource($structure))->resolve()
                : null,
            'subjects' => $expected,
            'applied' => $applied,
            'missing_subject_ids' => $missing,
        ], 'Kurikulum kelas berhasil diambil');
    }

    /**
     * Terapkan struktur kurikulum ke `class_subjects` kelas.
     *
     * Idempoten: mapel yang sudah terdaftar dilewati, bukan digagalkan.
     */
    public function applyCurriculum(ApplyCurriculumRequest $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validated();

        $structure = CurriculumStructure::with('items')->findOrFail($data['curriculum_structure_id']);

        if ((int) $structure->education_level_id !== (int) $class->education_level_id) {
            throw ValidationException::withMessages([
                'curriculum_structure_id' => 'Jenjang struktur kurikulum tidak cocok dengan jenjang kelas.',
            ]);
        }

        $subjectIds = $structure->items
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $existing = ClassSubject::query()
            ->where('school_class_id', $class->id)
            ->where('academic_year_id', $class->academic_year_id)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($class, $structure, $subjectIds, $existing, &$created, &$skipped) {
            foreach ($subjectIds as $subjectId) {
                if (in_array($subjectId, $existing, true)) {
                    $skipped++;
                    continue;
                }

                ClassSubject::create([
                    'school_class_id' => $class->id,
                    'subject_id' => $subjectId,
                    'employee_id' => null,
                    'academic_year_id' => $class->academic_year_id,
                    'curriculum_structure_id' => $structure->id,
                ]);

                $created++;
            }
        });

        return $this->successResponse([
            'created' => $created,
            'skipped' => $skipped,
            'total' => $subjectIds->count(),
        ], "Kurikulum diterapkan: {$created} mapel ditambahkan, {$skipped} dilewati.");
    }

    // -----------------------------------------------------------------
    // Validasi & helper
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertVersionUnique(array $data, ?int $ignoreId = null): void
    {
        $exists = CurriculumStructure::withTrashed()
            ->where('curriculum_id', $data['curriculum_id'])
            ->where('education_level_id', $data['education_level_id'])
            ->where('version', $data['version'])
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'version' => 'Versi ini sudah dipakai untuk kombinasi kurikulum dan jenjang tersebut.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function assertNoDuplicateItems(array $items): void
    {
        $seen = [];

        foreach ($items as $item) {
            $key = ((int) $item['subject_id']).':'.((int) $item['semester']);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'items' => 'Satu mata pelajaran tidak boleh muncul dua kali pada semester yang sama.',
                ]);
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function syncItems(CurriculumStructure $structure, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            CurriculumStructureItem::create([
                'curriculum_structure_id' => $structure->id,
                'subject_id' => $item['subject_id'],
                'subject_group_id' => $item['subject_group_id'] ?? null,
                'semester' => $item['semester'],
                'weekly_hours' => $item['weekly_hours'],
                'is_required' => array_key_exists('is_required', $item) ? (bool) $item['is_required'] : true,
                'sort_order' => $item['sort_order'] ?? $index,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    /**
     * Struktur aktif untuk jenjang kelas; utamakan yang punya tahun ajaran sama.
     */
    protected function structureForClass(SchoolClass $class, ?int $curriculumId = null): ?CurriculumStructure
    {
        if (! $class->education_level_id) {
            return null;
        }

        $structure = $this->classStructureQuery($class, $curriculumId)
            ->where('academic_year_id', $class->academic_year_id)
            ->orderByDesc('version')
            ->first();

        $structure ??= $this->classStructureQuery($class, $curriculumId)
            ->orderByDesc('version')
            ->first();

        return $structure?->load($this->relations());
    }

    protected function classStructureQuery(SchoolClass $class, ?int $curriculumId): Builder
    {
        $query = CurriculumStructure::query()
            ->forLevel((int) $class->education_level_id)
            ->active();

        if ($curriculumId) {
            $query->where('curriculum_id', $curriculumId);
        } else {
            $activeCurriculumId = Curriculum::where('is_active', true)->value('id');

            if ($activeCurriculumId) {
                $query->where('curriculum_id', $activeCurriculumId);
            }
        }

        return $query;
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $filters = array_merge(
            (array) $request->query('filter', []),
            array_intersect_key($request->query(), array_flip($this->filterable)),
        );

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }

            if ($column === 'is_active') {
                $query->where('is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN));
                continue;
            }

            $query->where($column, $value);
        }
    }

    /**
     * @return list<string>
     */
    protected function relations(): array
    {
        return [
            'curriculum:id,code,name',
            'educationLevel:id,name,jenjang,level_number',
            'academicYear:id,name',
            'items.subject:id,code,name,category,credit',
            'items.subjectGroup:id,code,name',
        ];
    }
}
