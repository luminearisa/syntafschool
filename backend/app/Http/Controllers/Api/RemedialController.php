<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Assessment\RemedialRequest;
use App\Http\Resources\RemedialResource;
use App\Models\Assessment;
use App\Models\ClassStudent;
use App\Models\Remedial;
use App\Models\Score;
use App\Services\Assessment\GradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Remedial (A7).
 *
 * Menjawab: "siapa yang belum tuntas pada penilaian ini, dan berapa nilai
 * perbaikannya?" Cara nilai perbaikan itu menggantikan nilai awal ditentukan
 * formula penilaian, bukan hardcoded di sini — lihat GradingService.
 */
class RemedialController extends BaseApiController
{
    public function __construct(protected GradingService $grading) {}

    public function index(Assessment $assessment): JsonResponse
    {
        $remedials = Remedial::query()
            ->with('student')
            ->where('assessment_id', $assessment->id)
            ->get()
            ->sortBy(fn (Remedial $remedial) => $remedial->student?->full_name ?? '')
            ->values();

        $formula = $this->grading->formulaFor($assessment);

        return $this->successResponse([
            'assessment_id' => $assessment->id,
            'max_score' => $assessment->maxScoreValue(),
            'remedial_rule' => $formula->remedialRule(),
            'remedial_cap' => $formula->remedialCap(),
            'total' => $remedials->count(),
            'completed' => $remedials->whereNotNull('score_after')->count(),
            'items' => $remedials->map(fn (Remedial $remedial) => [
                'id' => $remedial->id,
                'student_id' => $remedial->student_id,
                'nis' => $remedial->student?->nis,
                'full_name' => $remedial->student?->full_name,
                'score_before' => $remedial->score_before === null ? null : (float) $remedial->score_before,
                'score_after' => $remedial->score_after === null ? null : (float) $remedial->score_after,
                'improvement' => $remedial->improvement(),
                'date' => $remedial->date?->toDateString(),
                'notes' => $remedial->notes,
                'status' => $remedial->score_after === null ? 'pending' : 'done',
            ])->values()->all(),
        ], 'Daftar remedial berhasil diambil');
    }

    /**
     * Siswa yang nilainya di bawah KKTP pada penilaian ini — kandidat remedial.
     */
    public function candidates(Assessment $assessment): JsonResponse
    {
        $summary = $this->grading->summaryFor($assessment->load(['subject', 'schoolClass', 'semester']));

        return $this->successResponse([
            'assessment_id' => $assessment->id,
            'passing_grade' => $summary['passing_grade'],
            'total' => count($summary['below_passing']),
            'items' => $summary['below_passing'],
        ], 'Kandidat remedial berhasil diambil');
    }

    /**
     * Catat remedial satu siswa. Barisnya unik per (penilaian, siswa) sehingga
     * penyimpanan ulang memperbarui catatan yang ada, bukan menggandakannya.
     */
    public function store(RemedialRequest $request, Assessment $assessment): JsonResponse
    {
        $data = $request->validated();
        $studentId = (int) $data['student_id'];

        $this->assertNotLocked($assessment);
        $this->assertMember($assessment, $studentId);
        $this->assertWithinMax($assessment, $data);

        $existing = $this->find($assessment, $studentId);

        // Nilai awal diambil dari skor yang tercatat bila guru tidak mengisi.
        $scoreBefore = $data['score_before']
            ?? Score::query()->where('assessment_id', $assessment->id)->where('student_id', $studentId)->value('score');

        $payload = [
            'score_before' => $scoreBefore,
            'score_after' => $data['score_after'] ?? null,
            'date' => $data['date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        $remedial = $existing
            ? tap($existing)->update($payload)
            : Remedial::create($payload + ['assessment_id' => $assessment->id, 'student_id' => $studentId]);

        $remedial->load('student');

        return $existing
            ? $this->successResponse(new RemedialResource($remedial), 'Catatan remedial berhasil diperbarui')
            : $this->createdResponse(new RemedialResource($remedial), 'Catatan remedial berhasil dibuat');
    }

    public function update(RemedialRequest $request, Assessment $assessment, Remedial $remedial): JsonResponse
    {
        $this->assertBelongsTo($assessment, $remedial);
        $this->assertNotLocked($assessment);

        $data = $request->validated();
        $this->assertWithinMax($assessment, $data);

        $remedial->update([
            'score_before' => array_key_exists('score_before', $data) ? $data['score_before'] : $remedial->score_before,
            'score_after' => array_key_exists('score_after', $data) ? $data['score_after'] : $remedial->score_after,
            'date' => array_key_exists('date', $data) ? $data['date'] : $remedial->date,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $remedial->notes,
        ]);

        return $this->successResponse(
            new RemedialResource($remedial->refresh()->load('student')),
            'Catatan remedial berhasil diperbarui',
        );
    }

    public function destroy(Assessment $assessment, Remedial $remedial): JsonResponse
    {
        $this->assertBelongsTo($assessment, $remedial);
        $this->assertNotLocked($assessment);

        $remedial->delete();

        return $this->successResponse(null, 'Catatan remedial berhasil dihapus');
    }

    // -----------------------------------------------------------------

    protected function find(Assessment $assessment, int $studentId): ?Remedial
    {
        return Remedial::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $studentId)
            ->first();
    }

    protected function assertNotLocked(Assessment $assessment): void
    {
        if ($assessment->isLocked()) {
            throw ValidationException::withMessages([
                'assessment_id' => 'Nilai penilaian ini sudah difinalisasi. Buka kembali nilai untuk menambah remedial.',
            ]);
        }
    }

    protected function assertMember(Assessment $assessment, int $studentId): void
    {
        $isMember = ClassStudent::query()
            ->where('school_class_id', $assessment->school_class_id)
            ->where('student_id', $studentId)
            ->where('status', 'aktif')
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'student_id' => 'Siswa ini bukan anggota aktif kelas pada penilaian tersebut.',
            ]);
        }
    }

    /**
     * Remedial memakai skor maksimum penilaian asalnya.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertWithinMax(Assessment $assessment, array $data): void
    {
        $max = $assessment->maxScoreValue();

        foreach (['score_before' => 'nilai awal', 'score_after' => 'nilai remedial'] as $field => $label) {
            $value = $data[$field] ?? null;

            if ($value !== null && (float) $value > $max) {
                throw ValidationException::withMessages([
                    $field => "{$label} tidak boleh melebihi skor maksimum penilaian ({$max}).",
                ]);
            }
        }
    }

    protected function assertBelongsTo(Assessment $assessment, Remedial $remedial): void
    {
        if ($remedial->assessment_id !== $assessment->id) {
            throw ValidationException::withMessages([
                'remedial' => 'Catatan remedial ini bukan milik penilaian tersebut.',
            ]);
        }
    }
}
