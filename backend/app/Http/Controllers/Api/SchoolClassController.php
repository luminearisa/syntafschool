<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Academic\SchoolClassRequest;
use App\Http\Resources\SchoolClassResource;
use App\Models\ClassStudent;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolClassController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = SchoolClass::query()
            ->with(['academicYear', 'educationLevel', 'room', 'homeroomTeacher'])
            ->withCount(['classStudents', 'classSubjects']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array($column, ['academic_year_id', 'education_level_id', 'school_id', 'is_active'], true)) {
                $query->where($column, $value);
            }
        }

        $query->latest('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                SchoolClassResource::collection($query->get()),
                'Data kelas berhasil diambil',
            );
        }

        $paginator = $query->paginate((int) $request->query('per_page', 15));

        return $this->successResponse(
            SchoolClassResource::collection($paginator),
            'Data kelas berhasil diambil',
        );
    }

    public function show(SchoolClass $class): JsonResponse
    {
        $class->load(['academicYear', 'educationLevel', 'room', 'homeroomTeacher'])
            ->loadCount(['classStudents', 'classSubjects']);

        return $this->successResponse(new SchoolClassResource($class), 'Detail kelas berhasil diambil');
    }

    public function store(SchoolClassRequest $request): JsonResponse
    {
        $class = SchoolClass::create([
            'school_id' => $request->validated('school_id'),
            'academic_year_id' => $request->validated('academic_year_id'),
            'education_level_id' => $request->validated('education_level_id'),
            'room_id' => $request->validated('room_id'),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'homeroom_teacher_id' => $request->validated('homeroom_teacher_id'),
            'capacity' => $request->validated('capacity', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->createdResponse(
            new SchoolClassResource($class->load(['academicYear', 'educationLevel', 'room', 'homeroomTeacher'])),
            'Kelas berhasil dibuat',
        );
    }

    public function update(SchoolClassRequest $request, SchoolClass $class): JsonResponse
    {
        $class->update([
            'school_id' => $request->validated('school_id'),
            'academic_year_id' => $request->validated('academic_year_id'),
            'education_level_id' => $request->validated('education_level_id'),
            'room_id' => $request->validated('room_id'),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'homeroom_teacher_id' => $request->validated('homeroom_teacher_id'),
            'capacity' => $request->validated('capacity', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->successResponse(
            new SchoolClassResource($class->refresh()->load(['academicYear', 'educationLevel', 'room', 'homeroomTeacher'])),
            'Kelas berhasil diperbarui',
        );
    }

    public function destroy(SchoolClass $class): JsonResponse
    {
        $class->delete();

        return $this->successResponse(null, 'Kelas berhasil dihapus');
    }

    /**
     * Daftar siswa dalam rombel kelas.
     */
    public function students(SchoolClass $class): JsonResponse
    {
        $items = ClassStudent::query()
            ->with('student')
            ->where('school_class_id', $class->id)
            ->get()
            ->map(fn (ClassStudent $cs) => [
                'class_student_id' => $cs->id,
                'status' => $cs->status,
                'student' => $cs->student ? [
                    'id' => $cs->student->id,
                    'nis' => $cs->student->nis,
                    'nisn' => $cs->student->nisn,
                    'full_name' => $cs->student->full_name,
                ] : null,
            ]);

        return $this->successResponse($items, 'Daftar siswa kelas berhasil diambil');
    }

    /**
     * Tambahkan siswa ke rombel kelas.
     */
    public function addStudents(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array'],
            'student_ids.*' => ['integer', 'exists:students,id'],
        ], [], ['student_ids' => 'siswa']);

        DB::transaction(function () use ($class, $data) {
            foreach ($data['student_ids'] as $studentId) {
                $existing = ClassStudent::where('student_id', $studentId)
                    ->where('academic_year_id', $class->academic_year_id)
                    ->first();

                if ($existing && $existing->school_class_id !== $class->id) {
                    throw ValidationException::withMessages([
                        'student_ids' => 'Sebagian siswa sudah terdaftar pada kelas lain di tahun ajaran ini.',
                    ]);
                }

                ClassStudent::updateOrCreate(
                    [
                        'student_id' => $studentId,
                        'academic_year_id' => $class->academic_year_id,
                    ],
                    [
                        'school_class_id' => $class->id,
                        'status' => 'aktif',
                    ],
                );
            }
        });

        return $this->successResponse(null, 'Siswa berhasil ditambahkan ke kelas');
    }

    /**
     * Hapus siswa dari rombel kelas.
     */
    public function removeStudent(SchoolClass $class, Student $student): JsonResponse
    {
        ClassStudent::where('school_class_id', $class->id)
            ->where('student_id', $student->id)
            ->delete();

        return $this->successResponse(null, 'Siswa berhasil dikeluarkan dari kelas');
    }
}
