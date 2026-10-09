<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ClassSubjectResource;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mapel per kelas (struktur pengajaran sebuah rombel).
 *
 * Menjawab: "mapel apa saja yang diajarkan di kelas ini, dan siapa gurunya?"
 */
class ClassSubjectController extends BaseApiController
{
    public function index(SchoolClass $class): JsonResponse
    {
        $items = ClassSubject::query()
            ->with(['subject', 'employee'])
            ->where('school_class_id', $class->id)
            ->get()
            ->sortBy(fn (ClassSubject $cs) => $cs->subject?->name ?? '')
            ->values();

        return $this->successResponse(
            ClassSubjectResource::collection($items),
            'Daftar mapel kelas berhasil diambil',
        );
    }

    /**
     * Tambah satu atau beberapa mapel sekaligus ke kelas.
     */
    public function store(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*.subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'subjects.*.employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ], [], [
            'subjects' => 'mata pelajaran',
            'subjects.*.subject_id' => 'mata pelajaran',
            'subjects.*.employee_id' => 'guru pengajar',
        ]);

        $this->assertTeachers($data['subjects']);

        $existing = ClassSubject::where('school_class_id', $class->id)
            ->pluck('subject_id')
            ->all();

        $added = 0;
        $skipped = 0;

        DB::transaction(function () use ($class, $data, $existing, &$added, &$skipped) {
            foreach ($data['subjects'] as $row) {
                // Mapel yang sudah terdaftar di kelas ini dilewati, bukan digagalkan,
                // agar penambahan massal tetap aman diulang.
                if (in_array($row['subject_id'], $existing, true)) {
                    $skipped++;
                    continue;
                }

                ClassSubject::create([
                    'school_class_id' => $class->id,
                    'subject_id' => $row['subject_id'],
                    'employee_id' => $row['employee_id'] ?? null,
                    'academic_year_id' => $class->academic_year_id,
                ]);

                $added++;
            }
        });

        return $this->createdResponse([
            'added' => $added,
            'skipped' => $skipped,
        ], $skipped > 0
            ? "{$added} mapel ditambahkan, {$skipped} dilewati karena sudah terdaftar"
            : "{$added} mapel berhasil ditambahkan");
    }

    /**
     * Ubah guru pengajar sebuah mapel di kelas.
     */
    public function update(Request $request, SchoolClass $class, ClassSubject $classSubject): JsonResponse
    {
        $this->assertBelongsToClass($class, $classSubject);

        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ], [], ['employee_id' => 'guru pengajar']);

        $this->assertTeachers([['employee_id' => $data['employee_id'] ?? null]]);

        $classSubject->update(['employee_id' => $data['employee_id'] ?? null]);

        return $this->successResponse(
            new ClassSubjectResource($classSubject->refresh()->load(['subject', 'employee'])),
            'Guru pengajar berhasil diperbarui',
        );
    }

    public function destroy(SchoolClass $class, ClassSubject $classSubject): JsonResponse
    {
        $this->assertBelongsToClass($class, $classSubject);

        $usedBySchedule = Schedule::query()
            ->where('school_class_id', $class->id)
            ->where('subject_id', $classSubject->subject_id)
            ->exists();

        if ($usedBySchedule) {
            throw ValidationException::withMessages([
                'class_subject' => 'Mapel ini masih dipakai pada jadwal pelajaran. Hapus jadwalnya terlebih dahulu.',
            ]);
        }

        $classSubject->delete();

        return $this->successResponse(null, 'Mapel berhasil dikeluarkan dari kelas');
    }

    /**
     * Salin seluruh mapel dari kelas lain — mempercepat setup awal tahun ajaran.
     */
    public function copy(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'source_class_id' => ['required', 'integer', 'exists:school_classes,id'],
        ], [], ['source_class_id' => 'kelas sumber']);

        if ((int) $data['source_class_id'] === (int) $class->id) {
            throw ValidationException::withMessages([
                'source_class_id' => 'Kelas sumber tidak boleh sama dengan kelas tujuan.',
            ]);
        }

        $sources = ClassSubject::where('school_class_id', $data['source_class_id'])->get();

        if ($sources->isEmpty()) {
            return $this->errorResponse('Kelas sumber belum memiliki mapel.', 422);
        }

        $existing = ClassSubject::where('school_class_id', $class->id)
            ->pluck('subject_id')
            ->all();

        $added = 0;

        DB::transaction(function () use ($class, $sources, $existing, &$added) {
            foreach ($sources as $source) {
                if (in_array($source->subject_id, $existing, true)) {
                    continue;
                }

                ClassSubject::create([
                    'school_class_id' => $class->id,
                    'subject_id' => $source->subject_id,
                    'employee_id' => $source->employee_id,
                    'academic_year_id' => $class->academic_year_id,
                ]);

                $added++;
            }
        });

        $skipped = $sources->count() - $added;

        return $this->createdResponse([
            'added' => $added,
            'skipped' => $skipped,
        ], "{$added} mapel disalin dari kelas lain".($skipped > 0 ? ", {$skipped} dilewati" : ''));
    }

    /**
     * Pastikan seluruh guru yang dikirim benar-benar berstatus pengajar.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function assertTeachers(array $rows): void
    {
        $ids = collect($rows)
            ->pluck('employee_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $teacherCount = Employee::whereIn('id', $ids)->where('is_teacher', true)->count();

        if ($teacherCount !== $ids->count()) {
            throw ValidationException::withMessages([
                'subjects' => 'Guru pengajar harus pegawai yang berstatus pengajar (is_teacher).',
            ]);
        }
    }

    /**
     * Pastikan mapel memang milik kelas yang dituju di URL.
     */
    protected function assertBelongsToClass(SchoolClass $class, ClassSubject $classSubject): void
    {
        if ((int) $classSubject->school_class_id !== (int) $class->id) {
            abort(404, 'Mapel tidak ditemukan pada kelas ini.');
        }
    }
}
