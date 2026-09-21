<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Curriculum\SubjectPrerequisiteRequest;
use App\Http\Resources\SubjectPrerequisiteResource;
use App\Models\SubjectPrerequisite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Prasyarat mata pelajaran.
 *
 * Dua aturan inti:
 *  - mapel tidak boleh menjadi prasyarat bagi dirinya sendiri;
 *  - rantai prasyarat tidak boleh membentuk siklus (A → B → A), karena siklus
 *    membuat urutan pengambilan mapel tidak pernah bisa dipenuhi.
 */
class SubjectPrerequisiteController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = ['subject_id', 'prerequisite_subject_id'];

    public function index(Request $request): JsonResponse
    {
        $query = SubjectPrerequisite::query()->with(['subject:id,code,name', 'prerequisite:id,code,name']);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        if ($search = $request->query('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('subject', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                    ->orWhereHas('prerequisite', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            });
        }

        $query->orderBy('subject_id')->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                SubjectPrerequisiteResource::collection($query->get()),
                'Prasyarat mapel berhasil diambil',
            );
        }

        return $this->successResponse(
            SubjectPrerequisiteResource::collection($query->paginate((int) $request->query('per_page', 50))),
            'Prasyarat mapel berhasil diambil',
        );
    }

    public function store(SubjectPrerequisiteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->assertNotSelf($data);
        $this->assertNotDuplicate($data);
        $this->assertNoCycle((int) $data['subject_id'], (int) $data['prerequisite_subject_id']);

        $prerequisite = SubjectPrerequisite::create($data);

        return $this->createdResponse(
            new SubjectPrerequisiteResource($prerequisite->load(['subject:id,code,name', 'prerequisite:id,code,name'])),
            'Prasyarat mapel berhasil ditambahkan',
        );
    }

    public function show(SubjectPrerequisite $subjectPrerequisite): JsonResponse
    {
        return $this->successResponse(
            new SubjectPrerequisiteResource(
                $subjectPrerequisite->load(['subject:id,code,name', 'prerequisite:id,code,name'])
            ),
            'Detail prasyarat mapel berhasil diambil',
        );
    }

    public function update(SubjectPrerequisiteRequest $request, SubjectPrerequisite $subjectPrerequisite): JsonResponse
    {
        $data = $request->validated();

        $this->assertNotSelf($data);
        $this->assertNotDuplicate($data, $subjectPrerequisite->id);
        $this->assertNoCycle(
            (int) $data['subject_id'],
            (int) $data['prerequisite_subject_id'],
            $subjectPrerequisite->id,
        );

        $subjectPrerequisite->update($data);

        return $this->successResponse(
            new SubjectPrerequisiteResource(
                $subjectPrerequisite->refresh()->load(['subject:id,code,name', 'prerequisite:id,code,name'])
            ),
            'Prasyarat mapel berhasil diperbarui',
        );
    }

    public function destroy(SubjectPrerequisite $subjectPrerequisite): JsonResponse
    {
        $subjectPrerequisite->delete();

        return $this->successResponse(null, 'Prasyarat mapel berhasil dihapus');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertNotSelf(array $data): void
    {
        if ((int) $data['subject_id'] === (int) $data['prerequisite_subject_id']) {
            throw ValidationException::withMessages([
                'prerequisite_subject_id' => 'Mata pelajaran tidak boleh menjadi prasyarat bagi dirinya sendiri.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertNotDuplicate(array $data, ?int $ignoreId = null): void
    {
        $exists = SubjectPrerequisite::query()
            ->where('subject_id', $data['subject_id'])
            ->where('prerequisite_subject_id', $data['prerequisite_subject_id'])
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'prerequisite_subject_id' => 'Prasyarat ini sudah terdaftar untuk mata pelajaran tersebut.',
            ]);
        }
    }

    /**
     * Deteksi siklus dengan penelusuran graf.
     *
     * Sisi graf diarahkan dari mapel ke prasyaratnya. Menambahkan
     * `subject → prerequisite` membentuk siklus bila `subject` sudah dapat
     * dicapai dari `prerequisite` (yaitu prerequisite pada akhirnya membutuhkan
     * subject tersebut).
     */
    protected function assertNoCycle(int $subjectId, int $prerequisiteId, ?int $ignoreId = null): void
    {
        $stack = [$prerequisiteId];
        $visited = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $subjectId) {
                throw ValidationException::withMessages([
                    'prerequisite_subject_id' => 'Prasyarat ini membentuk siklus (mapel saling membutuhkan).',
                ]);
            }

            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $next = SubjectPrerequisite::query()
                ->where('subject_id', $current)
                ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
                ->pluck('prerequisite_subject_id')
                ->all();

            foreach ($next as $id) {
                $stack[] = (int) $id;
            }
        }
    }
}
