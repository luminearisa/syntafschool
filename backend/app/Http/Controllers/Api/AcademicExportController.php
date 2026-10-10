<?php

namespace App\Http\Controllers\Api;

use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Subject;
use App\Services\Academic\TeachingAccess;
use App\Services\Assessment\GradingService;
use App\Services\Attendance\AttendanceRecapService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor CSV rekap akademik (nilai & presensi).
 *
 * CSV dipilih agar tidak menambah dependensi (mis. maatwebsite/excel) namun
 * tetap bisa dibuka di Excel/Google Sheets. Ditulis dengan BOM UTF-8 agar
 * aksen dan karakter Indonesia tampil benar.
 */
class AcademicExportController extends BaseApiController
{
    public function __construct(
        private readonly GradingService $grading,
        private readonly AttendanceRecapService $attendance,
    ) {}

    /** Rekap nilai (buku nilai) satu mapel-kelas-semester sebagai CSV. */
    public function gradebook(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
        ], [], [
            'class_id' => 'kelas',
            'subject_id' => 'mata pelajaran',
            'semester_id' => 'semester',
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);
        $subject = Subject::findOrFail($data['subject_id']);
        $semester = $this->semester($data['semester_id'] ?? null);

        $this->assertMayExportClass($request, (int) $class->id);

        $result = $this->grading->build($class, $subject, $semester);
        $components = $result['weights'];

        $headers = array_merge(
            ['No', 'NIS', 'Nama'],
            array_map(fn (array $weight) => $weight['name'], $components),
            ['Nilai Akhir', 'Grade', 'Predikat', 'Ketuntasan'],
        );

        $rows = [];
        foreach ($result['students'] as $index => $student) {
            $byAssessment = collect($student['items'])->keyBy('assessment_id');

            $cells = [];
            foreach ($components as $component) {
                $item = $byAssessment->get($component['assessment_id']);
                $cells[] = $item === null ? '' : $this->number($item['percentage']);
            }

            $rows[] = array_merge(
                [$index + 1, $student['nis'], $student['full_name']],
                $cells,
                [
                    $student['final_score'] === null ? '' : $this->number($student['final_score']),
                    $student['grade'] ?? '',
                    $student['predicate'] ?? '',
                    $student['tuntas'] === null ? '' : ($student['tuntas'] ? 'Tuntas' : 'Belum tuntas'),
                ],
            );
        }

        $filename = 'nilai-'.$class->code.'-'.$subject->code.'-'.$semester->name.'.csv';

        return $this->csv($filename, $headers, $rows);
    }

    /** Rekap presensi seluruh siswa satu kelas sebagai CSV. */
    public function attendanceRecap(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
        ], [], [
            'class_id' => 'kelas',
            'semester_id' => 'semester',
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);
        $semesterId = $data['semester_id'] ?? $this->attendance->activeSemesterId();

        if ($semesterId === null) {
            throw ValidationException::withMessages([
                'semester_id' => 'Semester aktif belum ditetapkan, pilih semester secara eksplisit.',
            ]);
        }

        $this->assertMayExportClass($request, (int) $class->id);

        $result = $this->attendance->forClass((int) $class->id, (int) $semesterId);

        $headers = ['No', 'NIS', 'Nama', 'Hadir', 'Terlambat', 'Sakit', 'Izin', 'Alpa', 'Hadir+Terlambat', 'Hari Tercatat', 'Hari Efektif', 'Persentase (%)'];

        $rows = [];
        foreach ($result['items'] as $index => $item) {
            $rows[] = [
                $index + 1,
                $item['student']['nis'],
                $item['student']['full_name'],
                $item['present'],
                $item['late'],
                $item['sick'],
                $item['permitted'],
                $item['absent'],
                $item['total_present'],
                $item['recorded_days'],
                $item['effective_days'] ?? '',
                $this->number($item['percentage']),
            ];
        }

        $filename = 'presensi-'.$class->code.'-'.$semesterId.'.csv';

        return $this->csv($filename, $headers, $rows);
    }

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

    protected function assertMayExportClass(Request $request, int $classId): void
    {
        if (! app(TeachingAccess::class)->mayRecordClass($request->user(), $classId)) {
            abort(403, 'Anda bukan pengampu atau wali kelas ini.');
        }
    }

    protected function number(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    protected function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel mengenali UTF-8
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
