<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCertificateTemplate;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsQuestion;

/**
 * Ekspor course (§14).
 *
 * Struktur bagian/item/materi/aktivitas **tidak digambar ulang** di sini: ia
 * memakai `CourseTemplateService::snapshotCourse()` yang sudah terbukti dipakai
 * untuk template & salin course. Yang ditambahkan hanyalah bagian yang tidak
 * ada di template: metadata course, soal & kategorinya, struktur penilaian,
 * capaian/kompetensi, serta konfigurasi sertifikat.
 *
 * Yang **tidak pernah** diekspor: kata sandi kuis, token, rahasia autentikasi,
 * data siswa (pendaftaran, submission, nilai, progres, sertifikat, badge,
 * pesan), dan jejak audit.
 */
class CourseExportService
{
    use LogsLmsAudit;

    /** Penanda berkas agar impor menolak berkas asing. */
    public const FORMAT = 'syntaf-lms-course';

    public const FORMAT_VERSION = 1;

    public function __construct(
        private readonly CourseTemplateService $templates,
        private readonly FileStorageProviderInterface $files,
    ) {}

    /**
     * Susun payload ekspor lengkap sebuah course.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function export(LmsCourse $course, LmsActor $actor, array $options = []): array
    {
        $structure = $this->templates->snapshotCourse($course, [
            'include_content' => (bool) ($options['include_content'] ?? true),
            'include_files' => (bool) ($options['include_files'] ?? true),
            'include_activities' => (bool) ($options['include_activities'] ?? true),
        ]);

        $payload = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'exported_by' => ['id' => $actor->id, 'name' => $actor->name],
            'course' => $this->courseMetadata($course),
            'structure' => $structure,
            'question_categories' => $this->questionCategories($course),
            'questions' => $this->questions($course),
            'grade_structure' => $this->gradeStructure($course),
            'outcomes' => $this->outcomes($course),
            'competencies' => $this->competencies($course),
            'certificate' => $this->certificate($course),
            'integrity' => [],
        ];

        // Sidik jari isi: dipakai impor untuk mendeteksi berkas yang berubah
        // atau rusak, dan untuk idempotensi (impor dua kali tidak menggandakan).
        $payload['integrity'] = [
            'checksum' => hash('sha256', json_encode($payload['structure'] ?? [])),
            'counts' => $this->counts($payload),
        ];

        $this->audit('LMS_COURSE_EXPORTED', $course, ['counts' => $payload['integrity']['counts']]);

        return $payload;
    }

    /**
     * Tulis payload ekspor ke penyimpanan (visibilitas PRIVATE — bukan tautan publik).
     *
     * @param  array<string, mixed>  $payload
     * @return array{path:string,disk:string,visibility:string,size:int,mime:string|null,checksum:string,filename:string}
     */
    public function store(array $payload, LmsCourse $course, LmsActor $actor): array
    {
        $filename = 'course-'.($course->code ?? $course->id).'-'.now()->format('Ymd-His').'.json';
        $contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stored = $this->files->put('lms/exports/courses', $filename, (string) $contents, 'PRIVATE');

        $this->audit('LMS_COURSE_EXPORT_STORED', $course, [
            'path' => $stored['path'],
            'size' => $stored['size'],
        ]);

        return $stored + ['filename' => $filename];
    }

    /**
     * Ringkasan isi berkas ekspor (untuk pratinjau sebelum impor).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function summary(array $payload): array
    {
        $counts = $payload['integrity']['counts'] ?? $this->counts($payload);

        return [
            'format' => $payload['format'] ?? null,
            'format_version' => $payload['format_version'] ?? null,
            'exported_at' => $payload['exported_at'] ?? null,
            'course' => [
                'name' => $payload['course']['name'] ?? null,
                'code' => $payload['course']['code'] ?? null,
                'category' => $payload['course']['category'] ?? null,
            ],
            'counts' => $counts,
            'checksum' => $payload['integrity']['checksum'] ?? null,
            'warnings' => $this->redactionWarnings($payload),
        ];
    }

    /** @return array<string, mixed> */
    private function courseMetadata(LmsCourse $course): array
    {
        return [
            'name' => $course->name,
            'code' => $course->code,
            'description' => $course->description,
            'short_description' => $course->short_description,
            'category' => $course->category?->name,
            'level' => $course->level,
            'language' => $course->language,
            'visibility' => $course->visibility?->value,
            'completion_rule' => $course->completion_rule?->value ?? null,
            'completion_min_progress' => $course->completion_min_progress !== null ? (float) $course->completion_min_progress : null,
            'completion_min_grade' => $course->completion_min_grade !== null ? (float) $course->completion_min_grade : null,
            'estimated_minutes' => $course->estimated_minutes ?? null,
            // Guru sengaja tidak diekspor: penetapan guru wajib dipilih di
            // sekolah tujuan supaya tidak ada akses yang "ikut pindah".
            'teacher_assignment' => 'not_exported',
        ];
    }

    /**
     * Kategori soal yang benar-benar dipakai soal course ini.
     *
     * Kategori bersifat per bank soal, jadi yang diekspor hanya kategori yang
     * menaungi soal course ini — sekaligus nama banknya agar impor tahu konteks.
     *
     * @return list<array<string, mixed>>
     */
    private function questionCategories(LmsCourse $course): array
    {
        return LmsQuestion::query()
            ->where('course_id', $course->id)
            ->whereNotNull('category_id')
            ->with(['category:id,name,description,bank_id', 'category.bank:id,name'])
            ->get()
            ->pluck('category')
            ->filter()
            ->unique('id')
            ->map(fn ($category) => [
                'name' => $category->name,
                'description' => $category->description,
                'bank' => $category->bank?->name,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function questions(LmsCourse $course): array
    {
        return LmsQuestion::query()
            ->with(['options:id,question_id,option_key,text,is_correct,feedback,match_target,sort_order', 'category:id,name'])
            ->where('course_id', $course->id)
            ->orderBy('id')
            ->get()
            ->map(fn (LmsQuestion $question) => [
                'name' => $question->name,
                'question_type' => $question->question_type?->value,
                'question_text' => $question->question_text,
                'general_feedback' => $question->general_feedback,
                'default_mark' => (float) $question->default_mark,
                'difficulty' => $question->difficulty?->value ?? null,
                'category' => $question->category?->name,
                'settings' => $question->settings,
                'options' => $question->options->map(fn ($option) => [
                    'option_key' => $option->option_key,
                    'text' => $option->text,
                    'is_correct' => (bool) $option->is_correct,
                    'feedback' => $option->feedback,
                    'match_target' => $option->match_target,
                    'sort_order' => (int) $option->sort_order,
                ])->all(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function gradeStructure(LmsCourse $course): array
    {
        $gradebook = $course->gradebook()->with(['categories', 'items'])->first();

        if ($gradebook === null) {
            return ['present' => false];
        }

        return [
            'present' => true,
            'grading_method' => $gradebook->grading_method?->value,
            'aggregation_method' => $gradebook->aggregation_method?->value,
            'scale' => $gradebook->scale?->name,
            'categories' => $gradebook->categories->map(fn ($category) => [
                'name' => $category->name,
                'weight' => (float) $category->weight,
                'aggregation_method' => $category->aggregation_method?->value,
                'parent' => $category->parent_id !== null ? $category->parent_id : null,
                'sort_order' => (int) $category->sort_order,
            ])->all(),
            'items' => $gradebook->items->map(fn ($item) => [
                'name' => $item->name,
                'source' => $item->source?->value,
                'maximum' => (float) $item->maximum_grade,
                'weight' => $item->weight !== null ? (float) $item->weight : null,
                'category' => $item->category?->name ?? null,
                'sort_order' => (int) $item->sort_order,
            ])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function outcomes(LmsCourse $course): array
    {
        return $course->learningOutcomes()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($outcome) => [
                'code' => $outcome->code ?? null,
                'statement' => $outcome->statement,
                'category' => $outcome->category,
                'achievement_thresholds' => $outcome->achievement_thresholds,
                'is_required' => (bool) $outcome->is_required,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function competencies(LmsCourse $course): array
    {
        return $course->competencies()
            ->get()
            ->map(fn ($competency) => [
                'name' => $competency->name,
                'code' => $competency->code ?? null,
                'framework' => $competency->framework?->name ?? null,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function certificate(LmsCourse $course): array
    {
        return [
            'enabled' => (bool) $course->certificate_enabled,
            'template' => $course->certificate_template_id !== null
                ? LmsCertificateTemplate::query()->whereKey($course->certificate_template_id)->value('title')
                : null,
            'min_progress' => $course->certificate_min_progress !== null ? (float) $course->certificate_min_progress : null,
            'min_grade' => $course->certificate_min_grade !== null ? (float) $course->certificate_min_grade : null,
            'required_competency' => $course->certificate_required_competency_id !== null
                ? $course->competencies()->where('lms_competencies.id', $course->certificate_required_competency_id)->value('name')
                : null,
        ];
    }

    /** @return array<string, int> */
    private function counts(array $payload): array
    {
        $sections = (array) ($payload['structure']['sections'] ?? []);
        $items = 0;

        foreach ($sections as $section) {
            $items += count((array) ($section['items'] ?? []));
        }

        return [
            'sections' => count($sections),
            'items' => $items,
            'questions' => count((array) ($payload['questions'] ?? [])),
            'question_categories' => count((array) ($payload['question_categories'] ?? [])),
            'grade_items' => count((array) ($payload['grade_structure']['items'] ?? [])),
            'outcomes' => count((array) ($payload['outcomes'] ?? [])),
            'competencies' => count((array) ($payload['competencies'] ?? [])),
        ];
    }

    /** @return list<string> */
    private function redactionWarnings(array $payload): array
    {
        $warnings = [];

        if (($payload['course']['teacher_assignment'] ?? null) === 'not_exported') {
            $warnings[] = 'Guru pengampu tidak diekspor; tetapkan kembali setelah impor.';
        }

        if (($payload['grade_structure']['present'] ?? false) === true) {
            $warnings[] = 'Nilai siswa tidak diekspor — hanya struktur penilaiannya.';
        }

        return $warnings;
    }
}
