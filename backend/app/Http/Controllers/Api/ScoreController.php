<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Assessment\BulkScoreRequest;
use App\Http\Requests\Assessment\ScoreRequest;
use App\Http\Resources\ScoreResource;
use App\Models\Assessment;
use App\Models\ClassStudent;
use App\Models\Score;
use App\Models\Student;
use App\Services\Assessment\ScoreRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Input nilai siswa (A7).
 *
 * Semua tulisan diteruskan ke ScoreRecorder supaya penguncian nilai, pengisian
 * huruf/predikat, dan jejak revisi selalu terjadi bersamaan.
 */
class ScoreController extends BaseApiController
{
    public function __construct(protected ScoreRecorder $recorder) {}

    public function index(Assessment $assessment): JsonResponse
    {
        $scores = Score::query()
            ->with(['student', 'revisions.reviser'])
            ->where('assessment_id', $assessment->id)
            ->get()
            ->sortBy(fn (Score $score) => $score->student?->full_name ?? '')
            ->values();

        return $this->successResponse(
            ScoreResource::collection($scores),
            'Daftar nilai berhasil diambil',
        );
    }

    /**
     * Simpan nilai satu siswa. Bila nilainya sudah ada, barisnya diperbarui.
     */
    public function store(Assessment $assessment, ScoreRequest $request): JsonResponse
    {
        $this->assertMayGrade($request, $assessment);

        $data = $request->validated();

        $existing = $this->findScore($assessment, (int) $data['student_id']);

        $score = $this->recorder->put(
            $assessment,
            (int) $data['student_id'],
            $data['score'] ?? null,
            $data['notes'] ?? null,
            $request->user()?->id,
            $data['reason'] ?? null,
        );

        $payload = new ScoreResource($score->load(['student', 'revisions']));

        return $existing === null
            ? $this->createdResponse($payload, 'Nilai berhasil disimpan')
            : $this->successResponse($payload, 'Nilai berhasil diperbarui');
    }

    public function update(Assessment $assessment, Score $score, ScoreRequest $request): JsonResponse
    {
        $this->assertMayGrade($request, $assessment);
        $this->assertBelongsTo($assessment, $score);

        $data = $request->validated();

        if (isset($data['student_id']) && (int) $data['student_id'] !== $score->student_id) {
            throw ValidationException::withMessages([
                'student_id' => 'Catatan nilai ini milik siswa lain. Hapus lalu buat nilai untuk siswa yang dituju.',
            ]);
        }

        $updated = $this->recorder->put(
            $assessment,
            $score->student_id,
            array_key_exists('score', $data) ? $data['score'] : $score->score,
            array_key_exists('notes', $data) ? $data['notes'] : $score->notes,
            $request->user()?->id,
            $data['reason'] ?? null,
        );

        return $this->successResponse(
            new ScoreResource($updated->load(['student', 'revisions'])),
            'Nilai berhasil diperbarui',
        );
    }

    public function destroy(Request $request, Assessment $assessment, Score $score): JsonResponse
    {
        $this->assertMayGrade($request, $assessment);
        $this->assertBelongsTo($assessment, $score);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], [
            'reason' => 'alasan',
        ]);

        $this->recorder->remove($score, $request->user()?->id, $data['reason'] ?? null);

        return $this->successResponse(null, 'Nilai siswa berhasil dihapus');
    }

    /**
     * Input nilai massal untuk satu penilaian (bulk grading).
     *
     * Divalidasi penuh sebelum satu baris pun ditulis: satu entri yang salah
     * (siswa bukan anggota kelas, nilai melebihi skor maksimum) membatalkan
     * seluruh simpanan, sehingga guru tidak berakhir dengan nilai separuh jadi.
     */
    public function bulkStore(Assessment $assessment, BulkScoreRequest $request): JsonResponse
    {
        $this->assertMayGrade($request, $assessment);

        $data = $request->validated();
        $max = $assessment->maxScoreValue();

        $memberIds = ClassStudent::query()
            ->where('school_class_id', $assessment->school_class_id)
            ->where('status', 'aktif')
            ->pluck('student_id')
            ->all();

        $alreadyStored = Score::query()
            ->where('assessment_id', $assessment->id)
            ->pluck('student_id')
            ->all();

        foreach ($data['entries'] as $index => $entry) {
            $studentId = (int) $entry['student_id'];

            if (! in_array($studentId, $memberIds, true)) {
                throw ValidationException::withMessages([
                    "entries.{$index}.student_id" => 'Siswa ini bukan anggota aktif kelas pada penilaian tersebut.',
                ]);
            }

            $score = $entry['score'] ?? null;

            if ($score !== null && (float) $score > $max) {
                throw ValidationException::withMessages([
                    "entries.{$index}.score" => "Nilai tidak boleh melebihi skor maksimum penilaian ({$max}).",
                ]);
            }
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($data, $assessment, $request, $alreadyStored, &$created, &$updated) {
            foreach ($data['entries'] as $entry) {
                $studentId = (int) $entry['student_id'];

                $this->recorder->put(
                    $assessment,
                    $studentId,
                    $entry['score'] ?? null,
                    $entry['notes'] ?? null,
                    $request->user()?->id,
                    $request->input('reason'),
                );

                in_array($studentId, $alreadyStored, true) ? $updated++ : $created++;
            }
        });

        return $this->successResponse([
            'assessment_id' => $assessment->id,
            'created' => $created,
            'updated' => $updated,
            'total' => $created + $updated,
        ], 'Nilai kelas berhasil disimpan');
    }

    /**
     * Daftar nilai milik satu siswa di semua penilaian.
     */
    public function studentScores(Request $request, Student $student): JsonResponse
    {
        $query = Score::query()
            ->with(['assessment.assessmentType', 'assessment.subject'])
            ->where('student_id', $student->id)
            ->whereHas('assessment', function ($q) use ($request) {
                if ($semesterId = $request->query('semester_id')) {
                    $q->where('semester_id', $semesterId);
                }
                if ($subjectId = $request->query('subject_id')) {
                    $q->where('subject_id', $subjectId);
                }
            })
            ->whereNotNull('score');

        $rows = $query->get()->map(fn (Score $score) => [
            'assessment_id' => $score->assessment_id,
            'assessment' => $score->assessment?->name,
            'type' => $score->assessment?->assessmentType?->name,
            'category' => $score->assessment?->assessmentType?->category,
            'subject' => $score->assessment?->subject?->name,
            'score' => (float) $score->score,
            'max_score' => $score->assessment?->maxScoreValue(),
            'percentage' => $score->percentage((float) ($score->assessment?->max_score ?: 100)),
            'grade' => $score->grade,
            'predicate' => $score->predicate,
            'notes' => $score->notes,
            'date' => $score->assessment?->date?->toDateString(),
        ])->sortByDesc('date')->values();

        return $this->successResponse($rows, 'Daftar nilai siswa berhasil diambil');
    }

    /** Guru hanya menilai penilaian miliknya/kelasnya (lihat TeachingAccess). */
    protected function assertMayGrade(Request $request, Assessment $assessment): void
    {
        if (! app(\App\Services\Academic\TeachingAccess::class)->mayGrade($request->user(), $assessment)) {
            abort(403, 'Anda bukan pengampu penilaian ini.');
        }
    }

    protected function findScore(Assessment $assessment, int $studentId): ?Score
    {
        return Score::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $studentId)
            ->first();
    }

    /**
     * Jalur bernested hanya sah bila skor memang milik penilaian di URL.
     */
    protected function assertBelongsTo(Assessment $assessment, Score $score): void
    {
        if ($score->assessment_id !== $assessment->id) {
            abort(404, 'Catatan nilai ini tidak termasuk dalam penilaian tersebut.');
        }
    }
}
