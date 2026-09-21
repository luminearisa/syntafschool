<?php

namespace App\Http\Controllers\Api;

use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Subject;
use App\Services\Assessment\GradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Buku nilai / gradebook (A7).
 *
 * Menjawab: "berapa nilai akhir mapel ini per siswa, dan berapa yang tuntas?"
 *
 * Sengaja tidak menyimpan apa pun ke tabel. Nilai akhir adalah hasil perhitungan
 * dari skor mentah + bobot + aturan remedial; menyimpannya akan membuat angka di
 * rapor basi begitu bobot diubah. Yang menyimpan hasil akhir nanti hanya rapor
 * (A9) dan keputusan kenaikan kelas (A10), sebagai snapshot bertanggal.
 */
class GradebookController extends BaseApiController
{
    public function __construct(protected GradingService $grading) {}

    /**
     * Nilai akhir satu mapel di satu kelas pada satu semester.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $this->validateContext($request);

        $context = $this->resolveContext($data);

        return $this->successResponse(
            $this->grading->build($context['class'], $context['subject'], $context['semester']),
            'Nilai akhir berhasil dihitung',
        );
    }

    /**
     * Rekap seluruh mapel di satu kelas — untuk melihat kelas/mapel mana yang
     * nilainya masih rendah atau bobotnya belum lengkap.
     */
    public function overview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
        ], [], [
            'class_id' => 'kelas',
            'semester_id' => 'semester',
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);
        $semester = $this->semester($data['semester_id'] ?? null);

        $subjects = $class->classSubjects()
            ->with('subject')
            ->whereHas('subject')
            ->get()
            ->map(fn ($classSubject) => $classSubject->subject)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $rows = $subjects->map(function (Subject $subject) use ($class, $semester) {
            $result = $this->grading->build($class, $subject, $semester);

            return [
                'subject' => $result['subject'],
                'assessment_count' => $result['assessment_count'],
                'weight_total' => $result['weight_total'],
                'weights_complete' => $result['weights_complete'],
                'passing_grade' => $result['passing_grade'],
                'average' => $result['summary']['average'],
                'highest' => $result['summary']['highest'],
                'lowest' => $result['summary']['lowest'],
                'median' => $result['summary']['median'],
                'tuntas' => $result['summary']['tuntas'],
                'belum_tuntas' => $result['summary']['belum_tuntas'],
                'unscored' => $result['summary']['unscored'],
                'pass_rate' => $result['summary']['pass_rate'],
            ];
        })->all();

        return $this->successResponse([
            'class' => ['id' => $class->id, 'name' => $class->name, 'code' => $class->code],
            'semester' => ['id' => $semester->id, 'name' => $semester->name],
            'subject_count' => count($rows),
            'incomplete_weights' => collect($rows)->where('weights_complete', false)->count(),
            'subjects' => $rows,
        ], 'Rekap nilai per mata pelajaran berhasil dihitung');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateContext(Request $request): array
    {
        return $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
        ], [], [
            'class_id' => 'kelas',
            'subject_id' => 'mata pelajaran',
            'semester_id' => 'semester',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{class: SchoolClass, subject: Subject, semester: Semester}
     */
    protected function resolveContext(array $data): array
    {
        return [
            'class' => SchoolClass::findOrFail($data['class_id']),
            'subject' => Subject::findOrFail($data['subject_id']),
            'semester' => $this->semester($data['semester_id'] ?? null),
        ];
    }

    /**
     * Semester aktif dipakai bila pemakai tidak memilih semester.
     */
    protected function semester(?int $id): Semester
    {
        if ($id) {
            return Semester::findOrFail($id);
        }

        $semester = Semester::query()->where('is_active', true)->orderBy('id')->first();

        if ($semester === null) {
            throw ValidationException::withMessages([
                'semester_id' => 'Semester aktif belum ditetapkan, pilih semester secara eksplisit.',
            ]);
        }

        return $semester;
    }
}
