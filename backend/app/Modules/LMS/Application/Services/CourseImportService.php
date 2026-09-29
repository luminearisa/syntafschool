<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Application\Validation\ContentValidationService;
use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Enums\ImportConflictStrategy;
use App\Modules\LMS\Domain\Enums\ImportStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCertificateTemplate;
use App\Modules\LMS\Models\LmsCompetency;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseImport;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsLearningOutcome;
use App\Modules\LMS\Models\LmsQuestion;
use App\Modules\LMS\Models\LmsQuestionBank;
use App\Modules\LMS\Models\LmsQuestionCategory;
use App\Modules\LMS\Models\LmsQuestionOption;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impor course (§14).
 *
 * Tahapan yang **selalu** berurutan:
 * `UPLOAD → VALIDATE → PREVIEW → RESOLVE CONFLICT → IMPORT → VERIFY`.
 *
 * Tidak ada penimpaan tanpa persetujuan: strategi konflik wajib dipilih
 * pemanggil, dan strategi bawaan `SKIP` hanya melewati yang bentrok. Impor
 * tidak pernah menyentuh data siswa (pendaftaran, submission, nilai, progres,
 * sertifikat, badge, pesan).
 */
class CourseImportService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly CourseExportService $exports,
        private readonly CourseTemplateService $templates,
        private readonly FileStorageProviderInterface $files,
        private readonly GradebookService $gradebooks,
        private readonly GradeItemService $gradeItems,
        private readonly QuestionService $questions,
        private readonly ContentValidationService $validation,
    ) {}

    /**
     * Tahap UPLOAD: simpan berkas impor dan buat baris pelacak.
     *
     * @return array{import_id:int,path:string,disk:string}
     */
    public function upload(UploadedFile $file, LmsActor $actor): array
    {
        if (! $actor->can('lms.course.import') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak mengimpor course.');
        }

        $stored = $this->files->store($file, 'lms/imports/courses', 'ADMIN_ONLY');

        $import = LmsCourseImport::query()->create([
            'status' => ImportStatus::UPLOADED,
            'source_name' => $file->getClientOriginalName(),
            'storage_path' => $stored['path'],
            'requested_by' => $actor->id,
        ]);

        return ['import_id' => (int) $import->id, 'path' => $stored['path'], 'disk' => $stored['disk']];
    }

    /**
     * Tahap VALIDATE + PREVIEW.
     *
     * @return array{valid:bool,errors:list<string>,warnings:list<string>,summary:array<string,mixed>,preview:array<string,mixed>,conflicts:list<array<string,mixed>>,payload:array<string,mixed>|null}
     */
    public function validate(string $json, ?LmsCourse $target = null): array
    {
        $errors = [];
        $warnings = [];

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            return $this->invalid('Berkas bukan JSON yang sah.');
        }

        if (($payload['format'] ?? null) !== CourseExportService::FORMAT) {
            return $this->invalid('Berkas ini bukan ekspor course LMS Syntaf.');
        }

        if ((int) ($payload['format_version'] ?? 0) > CourseExportService::FORMAT_VERSION) {
            return $this->invalid('Versi berkas ekspor ('.$payload['format_version'].') lebih baru daripada versi yang didukung aplikasi ini.');
        }

        $summary = $this->exports->summary($payload);
        $warnings = array_merge($warnings, $summary['warnings'] ?? []);

        $structure = (array) ($payload['structure']['sections'] ?? []);

        if ($structure === []) {
            $errors[] = 'Berkas tidak memuat satu pun bagian (section).';
        }

        foreach ($structure as $index => $section) {
            if (! filled($section['title'] ?? null)) {
                $errors[] = 'Bagian ke-'.($index + 1).' tidak memiliki judul.';
            }

            foreach ((array) ($section['items'] ?? []) as $itemIndex => $item) {
                if (! filled($item['title'] ?? null)) {
                    $errors[] = 'Item ke-'.($itemIndex + 1).' pada bagian "'.($section['title'] ?? '-').'" tidak memiliki judul.';
                }

                $activity = $item['activity'] ?? null;

                if (is_array($activity) && ! filled($activity['type'] ?? $item['activity_type'] ?? null)) {
                    $errors[] = 'Aktivitas "'.($item['title'] ?? '-').'" tidak memiliki tipe.';
                }
            }
        }

        $conflicts = $this->conflicts($payload, $target);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => $summary,
            'preview' => $this->preview($payload),
            'conflicts' => $conflicts,
            'payload' => $payload,
        ];
    }

    /**
     * Tahap PREVIEW: susunan isi yang akan dibuat.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload): array
    {
        $sections = [];

        foreach ((array) ($payload['structure']['sections'] ?? []) as $section) {
            $items = [];

            foreach ((array) ($section['items'] ?? []) as $item) {
                $items[] = [
                    'title' => $item['title'] ?? null,
                    'item_type' => $item['item_type'] ?? null,
                    'resource_type' => $item['resource_type'] ?? null,
                    'activity_type' => $item['activity_type'] ?? null,
                    'has_resource' => isset($item['resource']),
                    'has_activity' => isset($item['activity']),
                ];
            }

            $sections[] = ['title' => $section['title'] ?? null, 'items' => $items];
        }

        return [
            'sections' => $sections,
            'questions' => count((array) ($payload['questions'] ?? [])),
            'grade_items' => count((array) ($payload['grade_structure']['items'] ?? [])),
            'outcomes' => count((array) ($payload['outcomes'] ?? [])),
            'competencies' => count((array) ($payload['competencies'] ?? [])),
        ];
    }

    /**
     * Tahap RESOLVE CONFLICT → IMPORT → VERIFY.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function import(
        array $payload,
        LmsActor $actor,
        ImportConflictStrategy $strategy = ImportConflictStrategy::SKIP,
        ?LmsCourse $target = null,
        ?string $idempotencyKey = null,
        ?LmsCourseImport $tracking = null,
    ): array {
        if (! $actor->can('lms.course.import') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak mengimpor course.');
        }

        // Idempotensi: kunci yang sama tidak mengimpor dua kali (§25).
        if ($idempotencyKey !== null) {
            $existing = LmsCourseImport::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', ImportStatus::IMPORTED->value)
                ->first();

            if ($existing !== null) {
                return [
                    'import' => $existing->toPayload(),
                    'course_id' => (int) $existing->target_course_id,
                    'duplicate' => true,
                ];
            }
        }

        $metadata = (array) ($payload['course'] ?? []);
        $course = $target;

        if ($course === null) {
            $course = $this->resolveCourse($metadata, $strategy, $actor);
        } elseif ($strategy === ImportConflictStrategy::REPLACE) {
            $this->assertReplaceable($course);
            $this->clearStructure($course);
        } else {
            $course->fill([
                'name' => $metadata['name'] ?? $course->name,
                'description' => $metadata['description'] ?? $course->description,
                'short_description' => $metadata['short_description'] ?? $course->short_description,
                'completion_rule' => $metadata['completion_rule'] ?? $course->completion_rule,
                'completion_min_progress' => $metadata['completion_min_progress'] ?? $course->completion_min_progress,
                'completion_min_grade' => $metadata['completion_min_grade'] ?? $course->completion_min_grade,
            ])->save();
        }

        $tracking ??= LmsCourseImport::query()->create([
            'status' => ImportStatus::IMPORTING,
            'conflict_strategy' => $strategy,
            'requested_by' => $actor->id,
            'source_name' => $payload['course']['name'] ?? null,
        ]);

        $tracking->fill([
            'status' => ImportStatus::IMPORTING,
            'conflict_strategy' => $strategy,
            'target_course_id' => $course->id,
            'idempotency_key' => $idempotencyKey,
            'preview' => $this->preview($payload),
        ])->save();

        try {
            $summary = DB::transaction(function () use ($payload, $course, $actor): array {
                $stats = $this->templates->applyPayload($course, (array) ($payload['structure'] ?? []), $actor);

                $stats['questions'] = $this->importQuestions($payload, $course, $actor);
                $stats['question_categories'] = $this->importQuestionCategories($payload, $course);
                $stats['grade_items'] = $this->importGradeStructure($payload, $course, $actor);
                $stats['outcomes'] = $this->importOutcomes($payload, $course, $actor);
                $stats['competencies'] = $this->importCompetencies($payload, $course);
                $stats['certificate'] = $this->importCertificate($payload, $course);

                return $stats;
            });
        } catch (\Throwable $exception) {
            $tracking->fill([
                'status' => ImportStatus::FAILED,
                'error_message' => mb_strimwidth($exception->getMessage(), 0, 500, '…'),
            ])->save();

            throw $exception;
        }

        $tracking->fill([
            'status' => ImportStatus::IMPORTED,
            'summary' => $summary,
            'imported_at' => now(),
        ])->save();

        $verification = $this->verify($tracking->fresh());

        $tracking->fill(['validation' => $verification])->save();

        $this->audit('LMS_COURSE_IMPORTED', $course, [
            'course_id' => (int) $course->id,
            'strategy' => $strategy->value,
            'summary' => $summary,
            'verification' => $verification['status'],
        ]);

        return [
            'import' => $tracking->fresh()->toPayload(),
            'course_id' => (int) $course->id,
            'summary' => $summary,
            'verification' => $verification,
            'duplicate' => false,
        ];
    }

    /**
     * Tahap VERIFY: bandingkan yang dijanjikan berkas dengan yang benar-benar ada.
     *
     * @return array{status:string,expected:array<string,int>,actual:array<string,int>,mismatch:list<string>}
     */
    public function verify(LmsCourseImport $import): array
    {
        $course = $import->target_course_id !== null ? LmsCourse::query()->find($import->target_course_id) : null;

        if ($course === null) {
            return ['status' => 'UNKNOWN', 'expected' => [], 'actual' => [], 'mismatch' => ['Course tujuan tidak ditemukan.']];
        }

        $items = 0;
        foreach ($course->sections()->withCount('items')->get() as $section) {
            $items += (int) $section->items_count;
        }

        $expected = (array) ($import->summary ?? []);
        $actual = [
            'sections' => $course->sections()->count(),
            'items' => $items,
            'questions' => LmsQuestion::query()->where('course_id', $course->id)->count(),
            'grade_items' => LmsGradeItem::query()->whereIn('gradebook_id', $course->gradebook()->pluck('id'))->count(),
            'outcomes' => LmsLearningOutcome::query()->where('course_id', $course->id)->count(),
        ];

        $mismatch = [];

        foreach (['sections', 'items', 'questions', 'outcomes'] as $key) {
            if (isset($expected[$key]) && $expected[$key] !== $actual[$key]) {
                $mismatch[] = $key.': diharapkan '.$expected[$key].', terbentuk '.$actual[$key];
            }
        }

        return [
            'status' => $mismatch === [] ? 'VERIFIED' : 'PARTIAL',
            'expected' => array_map('intval', $expected),
            'actual' => $actual,
            'mismatch' => $mismatch,
        ];
    }

    /**
     * Riwayat impor (§14, §41).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function history(array $filters = [], int $limit = 50): array
    {
        return LmsCourseImport::query()
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['course_id'] ?? null), fn ($query) => $query->where('target_course_id', (int) $filters['course_id']))
            ->orderByDesc('id')
            ->limit(min(200, max(1, $limit)))
            ->get()
            ->map(fn (LmsCourseImport $import) => $import->toPayload())
            ->all();
    }

    /**
     * Konflik yang terdeteksi sebelum impor.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function conflicts(array $payload, ?LmsCourse $target = null): array
    {
        $conflicts = [];
        $metadata = (array) ($payload['course'] ?? []);

        $byCode = filled($metadata['code'] ?? null)
            ? LmsCourse::query()->where('code', $metadata['code'])->first()
            : null;

        if ($byCode !== null) {
            $conflicts[] = [
                'type' => 'course_code',
                'value' => $metadata['code'],
                'existing_course_id' => (int) $byCode->id,
                'message' => 'Sudah ada course dengan kode '.$metadata['code'].' ("'.$byCode->name.'").',
            ];
        }

        $byName = LmsCourse::query()->where('name', $metadata['name'] ?? null)->first();

        if ($byName !== null) {
            $conflicts[] = [
                'type' => 'course_name',
                'value' => $metadata['name'],
                'existing_course_id' => (int) $byName->id,
                'message' => 'Sudah ada course dengan nama yang sama.',
            ];
        }

        if ($target !== null) {
            $enrolled = LmsEnrollment::query()->where('course_id', $target->id)->exists();

            if ($enrolled) {
                $conflicts[] = [
                    'type' => 'target_has_students',
                    'value' => (string) $target->id,
                    'message' => 'Course tujuan sudah memiliki peserta; impor hanya dapat menambahkan (MERGE) tanpa menghapus isi.',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Tahap IMPORT untuk course baru:
     * DUPLICATE → course baru (kode diberi sufiks), MERGE/REPLACE tanpa target
     * diperlakukan sebagai DUPLICATE agar tidak ada penimpaan yang tidak diminta.
     */
    private function resolveCourse(array $metadata, ImportConflictStrategy $strategy, LmsActor $actor): LmsCourse
    {
        $existing = filled($metadata['code'] ?? null)
            ? LmsCourse::query()->where('code', $metadata['code'])->first()
            : null;

        if ($existing !== null) {
            if ($strategy === ImportConflictStrategy::SKIP) {
                LmsFault::conflict('Course dengan kode '.$metadata['code'].' sudah ada. Pilih strategi DUPLICATE atau MERGE.');
            }

            if ($strategy === ImportConflictStrategy::MERGE) {
                return $existing;
            }
        }

        $code = $this->uniqueCode($metadata['code'] ?? null);
        $slug = $this->uniqueSlug($metadata['name'] ?? 'course', $code);

        return LmsCourse::query()->create([
            'name' => (string) ($metadata['name'] ?? 'Course impor'),
            'code' => $code,
            'slug' => $slug,
            'description' => $metadata['description'] ?? null,
            'short_description' => $metadata['short_description'] ?? null,
            // `level`/`language` NOT NULL pada skema course: impor tidak boleh
            // gagal hanya karena berkas lama tidak memuatnya (§14).
            'level' => $metadata['level'] ?? 'BEGINNER',
            'language' => $metadata['language'] ?? 'id',
            'visibility' => $metadata['visibility'] ?? 'ENROLLED_ONLY',
            'completion_rule' => $metadata['completion_rule'] ?? 'ALL_REQUIRED_ACTIVITIES',
            'completion_min_progress' => $metadata['completion_min_progress'] ?? null,
            'completion_min_grade' => $metadata['completion_min_grade'] ?? null,
            'status' => 'DRAFT',
            'created_by' => $actor->id,
        ]);
    }

    /** Course dengan peserta tidak boleh diganti isinya (§15). */
    private function assertReplaceable(LmsCourse $course): void
    {
        $students = LmsEnrollment::query()->where('course_id', $course->id)->count();

        if ($students > 0) {
            LmsFault::conflict('Course tujuan sudah memiliki '.$students.' peserta; strategi REPLACE tidak diizinkan. Gunakan MERGE.');
        }
    }

    private function clearStructure(LmsCourse $course): void
    {
        foreach ($course->sections()->get() as $section) {
            $section->items()->each(fn ($item) => $item->delete());
            $section->delete();
        }
    }

    /** @param array<string, mixed> $payload */
    private function importQuestions(array $payload, LmsCourse $course, LmsActor $actor): int
    {
        $created = 0;

        foreach ((array) ($payload['questions'] ?? []) as $data) {
            $existing = LmsQuestion::query()
                ->where('course_id', $course->id)
                ->where('name', $data['name'] ?? null)
                ->first();

            $question = $existing ?? $this->questions->create([
                'course_id' => $course->id,
                'name' => (string) ($data['name'] ?? 'Soal impor'),
                'question_type' => $data['question_type'] ?? 'MULTIPLE_CHOICE_SINGLE',
                'question_text' => $data['question_text'] ?? '',
                'general_feedback' => $data['general_feedback'] ?? null,
                'default_mark' => (float) ($data['default_mark'] ?? 1),
                'difficulty' => $data['difficulty'] ?? 'MEDIUM',
                'settings' => $data['settings'] ?? null,
            ], $actor);

            if ($existing === null) {
                $created++;

                foreach ((array) ($data['options'] ?? []) as $option) {
                    LmsQuestionOption::query()->create([
                        'question_id' => $question->id,
                        'option_key' => $option['option_key'] ?? null,
                        'text' => (string) ($option['text'] ?? ''),
                        'is_correct' => (bool) ($option['is_correct'] ?? false),
                        'feedback' => $option['feedback'] ?? null,
                        'match_target' => $option['match_target'] ?? null,
                        'sort_order' => (int) ($option['sort_order'] ?? 0),
                    ]);
                }
            }
        }

        return $created;
    }

    /** @param array<string, mixed> $payload */
    private function importQuestionCategories(array $payload, LmsCourse $course): int
    {
        $created = 0;
        $bank = LmsQuestionBank::query()
            ->where('course_id', $course->id)
            ->orWhereNull('course_id')
            ->orderBy('id')
            ->first();

        if ($bank === null) {
            return 0;
        }

        foreach ((array) ($payload['question_categories'] ?? []) as $data) {
            $exists = LmsQuestionCategory::query()
                ->where('bank_id', $bank->id)
                ->where('name', $data['name'] ?? null)
                ->exists();

            if ($exists) {
                continue;
            }

            LmsQuestionCategory::query()->create([
                'bank_id' => $bank->id,
                'name' => (string) ($data['name'] ?? 'Kategori impor'),
                'description' => $data['description'] ?? null,
            ]);

            $created++;
        }

        return $created;
    }

    /** @param array<string, mixed> $payload */
    private function importGradeStructure(array $payload, LmsCourse $course, LmsActor $actor): int
    {
        $structure = (array) ($payload['grade_structure'] ?? []);

        if (($structure['present'] ?? false) !== true) {
            return 0;
        }

        $gradebook = $course->gradebook()->first();

        // Gradebook yang sudah punya nilai tidak pernah ditimpa (§15).
        if ($gradebook !== null && $this->gradebookHasGrades($gradebook)) {
            return 0;
        }

        $gradebook ??= $this->gradebooks->ensureForCourse($course, $actor->id);

        $categories = [];

        foreach ((array) ($structure['categories'] ?? []) as $index => $category) {
            $created = $this->gradebooks->createCategory($gradebook, [
                'name' => (string) ($category['name'] ?? 'Kategori '.($index + 1)),
                'weight' => (float) ($category['weight'] ?? 0),
                'aggregation_method' => $category['aggregation_method'] ?? null,
                'sort_order' => (int) ($category['sort_order'] ?? $index + 1),
            ], $actor);

            $categories[$created->name] = $created;
        }

        $items = 0;

        foreach ((array) ($structure['items'] ?? []) as $index => $item) {
            $this->gradeItems->create($gradebook, [
                'name' => (string) ($item['name'] ?? 'Komponen '.($index + 1)),
                'source' => $item['source'] ?? 'MANUAL',
                'maximum_grade' => (float) ($item['maximum'] ?? 100),
                'weight' => $item['weight'] ?? null,
                'category_id' => isset($item['category'], $categories[$item['category']])
                    ? $categories[$item['category']]->id
                    : null,
                'sort_order' => (int) ($item['sort_order'] ?? $index + 1),
            ], $actor);

            $items++;
        }

        return $items;
    }

    private function gradebookHasGrades($gradebook): bool
    {
        return LmsGradeItem::query()
            ->where('gradebook_id', $gradebook->id)
            ->whereHas('results')
            ->exists();
    }

    /** @param array<string, mixed> $payload */
    private function importOutcomes(array $payload, LmsCourse $course, LmsActor $actor): int
    {
        $created = 0;
        $position = (int) $course->learningOutcomes()->max('sort_order');

        foreach ((array) ($payload['outcomes'] ?? []) as $data) {
            $exists = LmsLearningOutcome::query()
                ->where('course_id', $course->id)
                ->where('statement', $data['statement'] ?? null)
                ->exists();

            if ($exists) {
                continue;
            }

            $position++;

            LmsLearningOutcome::query()->create([
                'course_id' => $course->id,
                'code' => $data['code'] ?? null,
                'statement' => (string) ($data['statement'] ?? 'Capaian pembelajaran'),
                'category' => $data['category'] ?? null,
                'achievement_thresholds' => $data['achievement_thresholds'] ?? null,
                'is_required' => (bool) ($data['is_required'] ?? true),
                'sort_order' => $position,
                'created_by' => $actor->id,
            ]);

            $created++;
        }

        return $created;
    }

    /** @param array<string, mixed> $payload */
    private function importCompetencies(array $payload, LmsCourse $course): int
    {
        $attached = 0;

        foreach ((array) ($payload['competencies'] ?? []) as $data) {
            $competency = LmsCompetency::query()
                ->where('name', $data['name'] ?? null)
                ->first();

            if ($competency === null) {
                // Kompetensi milik kerangka institusi — tidak dibuat otomatis.
                continue;
            }

            if ($course->competencies()->where('lms_competencies.id', $competency->id)->exists()) {
                continue;
            }

            $course->competencies()->attach($competency->id, ['created_at' => now(), 'updated_at' => now()]);
            $attached++;
        }

        return $attached;
    }

    /** @param array<string, mixed> $payload */
    private function importCertificate(array $payload, LmsCourse $course): int
    {
        $certificate = (array) ($payload['certificate'] ?? []);

        if (($certificate['enabled'] ?? false) !== true) {
            return 0;
        }

        $templateId = filled($certificate['template'] ?? null)
            ? LmsCertificateTemplate::query()
                ->where('title', $certificate['template'])
                ->value('id')
            : null;

        $competencyId = null;

        if (filled($certificate['required_competency'] ?? null)) {
            $competencyId = $course->competencies()
                ->where('lms_competencies.name', $certificate['required_competency'])
                ->value('lms_competencies.id');
        }

        $course->fill([
            'certificate_enabled' => true,
            'certificate_template_id' => $templateId,
            'certificate_min_progress' => $certificate['min_progress'] ?? null,
            'certificate_min_grade' => $certificate['min_grade'] ?? null,
            'certificate_required_competency_id' => $competencyId,
        ])->save();

        return 1;
    }

    private function uniqueCode(?string $code): string
    {
        $base = $code !== null && $code !== '' ? $code : 'IMP';
        $candidate = $base;
        $suffix = 1;

        while (LmsCourse::query()->where('code', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueSlug(string $name, string $code): string
    {
        $base = Str::slug($name).'-'.strtolower($code);
        $candidate = Str::limit($base, 180, '');
        $suffix = 1;

        while (LmsCourse::query()->where('slug', $candidate)->exists()) {
            $candidate = Str::limit($base.'-'.$suffix, 180, '');
            $suffix++;
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    private function invalid(string $error): array
    {
        return [
            'valid' => false,
            'errors' => [$error],
            'warnings' => [],
            'summary' => [],
            'preview' => [],
            'conflicts' => [],
            'payload' => null,
        ];
    }
}
