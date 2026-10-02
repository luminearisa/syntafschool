<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    ReligiousActivityAssessment, ReligiousAssessmentType, ReligiousStudyReference, ReligiousStudySession
};
use Illuminate\Validation\ValidationException;

/**
 * Pembelajaran keagamaan (§34, §35, §40-§42, §84).
 *
 * Batas: TIDAK menduplikasi LMS (§84) dan TIDAK menetapkan satu standar
 * penilaian agama yang hardcoded (§41) — seluruh jenis penilaian dan
 * rubriknya configurable.
 */
class ReligiousLearningService
{
    public function __construct(protected ReligiousContext $context)
    {
    }

    // ── Kajian / kitab (§34, §35) ───────────────────────────────────

    public function addStudySession(array $payload): ReligiousStudySession
    {
        $session = ReligiousStudySession::query()->create([
            'activity_id' => $payload['activity_id'] ?? null,
            'session_id' => $payload['session_id'] ?? null,
            'code' => $payload['code'] ?? $this->context->nextCode('KJI', 'religious_study_sessions'),
            'title' => $payload['title'],
            'topic' => $payload['topic'] ?? null,
            'speaker_name' => $payload['speaker_name'] ?? null,
            'personnel_id' => $payload['personnel_id'] ?? null,
            'book_title' => $payload['book_title'] ?? null,
            'book_author' => $payload['book_author'] ?? null,
            'chapter' => $payload['chapter'] ?? null,
            'page_from' => $payload['page_from'] ?? null,
            'page_to' => $payload['page_to'] ?? null,
            'reference_id' => $payload['reference_id'] ?? null,
            'session_number' => (int) ($payload['session_number'] ?? 1),
            'session_date' => $payload['session_date'] ?? now()->toDateString(),
            'duration_minutes' => $payload['duration_minutes'] ?? null,
            'location' => $payload['location'] ?? null,
            'target_group' => $payload['target_group'] ?? null,
            'language' => $payload['language'] ?? config('religious.khutbah.default_language', 'id'),
            'status' => 'PLANNED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.study_session.created', $session);

        return $session;
    }

    /**
     * Referensi kitab (§35). Bila modul Library tersedia, cukup menautkan
     * `library_item_id` — tidak membuat master buku kedua.
     */
    public function addReference(array $payload): ReligiousStudyReference
    {
        $reference = ReligiousStudyReference::query()->create([
            'title' => $payload['title'],
            'reference_type' => strtoupper((string) ($payload['reference_type'] ?? 'BOOK')),
            'author' => $payload['author'] ?? null,
            'publisher' => $payload['publisher'] ?? null,
            'isbn' => $payload['isbn'] ?? null,
            'library_item_id' => $payload['library_item_id'] ?? null,
            'category' => $payload['category'] ?? null,
            'language' => $payload['language'] ?? null,
            'edition' => $payload['edition'] ?? null,
            'publication_year' => $payload['publication_year'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'file_document_id' => $payload['file_document_id'] ?? null,
            'tags' => $payload['tags'] ?? null,
            'active' => true,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.study_reference.created', $reference);

        return $reference;
    }

    public function references(array $filters = []): array
    {
        return ReligiousStudyReference::query()
            ->when(! empty($filters['term']), fn ($q) => $q->where('title', 'like', '%'.$filters['term'].'%')
                ->orWhere('author', 'like', '%'.$filters['term'].'%'))
            ->when(! empty($filters['category']), fn ($q) => $q->where('category', $filters['category']))
            ->where('active', true)->limit((int) ($filters['limit'] ?? 100))->get()
            ->map(fn (ReligiousStudyReference $r): array => [
                'id' => $r->getKey(),
                'title' => $r->title,
                'author' => $r->author,
                'category' => $r->category,
                'language' => $r->language,
                'library_item_id' => $r->library_item_id,
            ])->all();
    }

    // ── Penilaian (§41) ─────────────────────────────────────────────────

    public function seedAssessmentTypes(): int
    {
        $count = 0;
        foreach ((array) config('religious.assessment.types', []) as $code => $definition) {
            ReligiousAssessmentType::query()->updateOrCreate(
                ['code' => strtoupper((string) $code)],
                [
                    'name' => $definition['name'] ?? $code,
                    'category' => $definition['category'] ?? 'KNOWLEDGE',
                    'scale_type' => $definition['scale'] ?? 'SCORE',
                    'max_score' => $definition['max_score'] ?? 100,
                    'components' => $definition['components'] ?? null,
                    'weight' => $definition['weight'] ?? null,
                    'rubric' => $definition['rubric'] ?? null,
                    'requires_supervisor' => (bool) ($definition['requires_supervisor'] ?? false),
                    'sort_order' => $count,
                    'status' => 'ACTIVE',
                    'active' => true,
                    'config_data' => $definition['config'] ?? null,
                ]
            );
            $count++;
        }

        return $count;
    }

    public function assessmentTypes(): array
    {
        return ReligiousAssessmentType::query()->where('active', true)->orderBy('sort_order')->get()
            ->map(fn (ReligiousAssessmentType $t): array => [
                'code' => $t->code,
                'name' => $t->name,
                'category' => $t->category,
                'scale_type' => $t->scale_type,
                'max_score' => $t->max_score,
                'components' => $t->components,
                'weight' => $t->weight,
            ])->all();
    }

    /**
     * Catat penilaian kegiatan (§41).
     * Skala dan komponen mengikuti jenis penilaian yang dikonfigurasi.
     */
    public function assess(array $payload): ReligiousActivityAssessment
    {
        $type = ReligiousAssessmentType::query()
            ->where('code', strtoupper((string) $payload['assessment_type_code']))
            ->where('active', true)->first();

        if (! $type) {
            throw ValidationException::withMessages([
                'assessment_type_code' => 'Jenis penilaian tidak dikenal atau tidak aktif.',
            ]);
        }

        $maxScore = $payload['max_score'] ?? $type->max_score;
        $score = $payload['score'] ?? null;

        if ($score !== null && $maxScore !== null && $score > $maxScore) {
            throw ValidationException::withMessages([
                'score' => 'Nilai melebihi skala maksimum ('.$maxScore.').',
            ]);
        }

        $assessment = ReligiousActivityAssessment::query()->create([
            'activity_id' => $payload['activity_id'] ?? null,
            'session_id' => $payload['session_id'] ?? null,
            'participant_id' => $payload['participant_id'] ?? null,
            'assessment_type_id' => $type->getKey(),
            'student_id' => $payload['student_id'] ?? null,
            'assessor_id' => $this->context->actorId(),
            'assessor_name' => $payload['assessor_name'] ?? null,
            'assessment_date' => $payload['assessment_date'] ?? now()->toDateString(),
            'score' => $score,
            'max_score' => $maxScore,
            'grade_label' => $payload['grade_label'] ?? $this->gradeLabel($score, $maxScore, $type),
            'level_label' => $payload['level_label'] ?? null,
            'components' => $payload['components'] ?? null,
            'result' => strtoupper((string) ($payload['result'] ?? (($score !== null && $maxScore) ? ($score >= ($maxScore * 0.6) ? 'PASS' : 'NEEDS_IMPROVEMENT') : 'RECORDED'))),
            'notes' => $payload['notes'] ?? null,
            'evidence_document_id' => $payload['evidence_document_id'] ?? null,
            'status' => 'FINAL',
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.assessment.recorded', $assessment, [
            'type' => $type->code, 'result' => $assessment->result,
        ]);

        return $assessment;
    }

    protected function gradeLabel(?float $score, ?float $maxScore, ReligiousAssessmentType $type): ?string
    {
        if ($score === null || ! $maxScore) {
            return null;
        }

        $bands = (array) ($type->config_data['grade_bands'] ?? config('religious.assessment.default_grade_bands', [
            ['min' => 90, 'label' => 'MUMTAZ'],
            ['min' => 80, 'label' => 'JAYYID_JIDDAN'],
            ['min' => 70, 'label' => 'JAYYID'],
            ['min' => 60, 'label' => 'MAQBUL'],
            ['min' => 0, 'label' => 'PERLU_BIMBINGAN'],
        ]));

        $pct = ($score / $maxScore) * 100;
        foreach ($bands as $band) {
            if ($pct >= (float) ($band['min'] ?? 0)) {
                return $band['label'] ?? null;
            }
        }

        return null;
    }

    /** Ringkasan penilaian seorang siswa (§73). */
    public function studentSummary(int $studentId, array $filters = []): array
    {
        $rows = ReligiousActivityAssessment::query()->where('student_id', $studentId)
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('assessment_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('assessment_date', '<=', $filters['to']))
            ->with('assessmentType')->get();

        return [
            'student_id' => $studentId,
            'assessments' => $rows->count(),
            'average_pct' => $rows->filter(fn ($r) => $r->score !== null && $r->max_score)->avg(fn ($r) => ($r->score / $r->max_score) * 100),
            'by_category' => $rows->groupBy(fn ($r) => $r->assessmentType?->category ?? 'UNKNOWN')
                ->map(fn ($group): array => [
                    'count' => $group->count(),
                    'average_pct' => $group->filter(fn ($r) => $r->score !== null && $r->max_score)
                        ->avg(fn ($r) => ($r->score / $r->max_score) * 100),
                ])->all(),
            'results' => $rows->groupBy('result')->map->count()->all(),
            'note' => 'Penilaian keagamaan bersifat perkembangan individual, bukan peringkat moral (§71).',
        ];
    }
}
