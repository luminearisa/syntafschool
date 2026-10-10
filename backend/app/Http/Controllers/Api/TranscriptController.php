<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TranscriptResource;
use App\Models\School;
use App\Models\Student;
use App\Models\Transcript;
use App\Services\Academic\TranscriptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Transkrip akademik kumulatif.
 *
 * Dibangun dari rapor yang telah diterbitkan; lihat TranscriptService.
 */
class TranscriptController extends BaseApiController
{
    public function __construct(private readonly TranscriptService $transcripts) {}

    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'academic_years' => \App\Models\AcademicYear::query()
                ->orderByDesc('start_date')
                ->get(['id', 'name']),
        ], 'Referensi transkrip berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Transcript::query()
            ->with(['student', 'academicYear'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', (int) $request->input('academic_year_id')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->whereHas('student', fn ($student) => $student
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('nis', 'like', "%{$term}%"));
            })
            ->orderByDesc('id');

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            TranscriptResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar transkrip berhasil diambil',
        );
    }

    public function show(Transcript $transcript): JsonResponse
    {
        $transcript->load(['student', 'academicYear']);

        return $this->successResponse(new TranscriptResource($transcript), 'Detail transkrip berhasil diambil');
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ], [], [
            'student_id' => 'siswa',
            'academic_year_id' => 'tahun ajaran',
        ]);

        $transcript = $this->transcripts->generate(
            Student::query()->findOrFail((int) $data['student_id']),
            isset($data['academic_year_id']) ? (int) $data['academic_year_id'] : null,
            $request->user()?->id,
        );

        return $this->successResponse(new TranscriptResource($transcript), 'Transkrip berhasil dibuat');
    }

    public function pdf(Request $request, Transcript $transcript): Response
    {
        $transcript->load(['student', 'academicYear']);
        $school = School::query()->where('is_active', true)->orderBy('id')->first();
        $filename = 'transkrip-'.$transcript->student?->nis.'.pdf';

        return Pdf::loadView('pdf.academic-transcript', compact('transcript', 'school'))
            ->setPaper('a4')
            ->download($filename);
    }
}
