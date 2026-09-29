<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsLearningItem;
use App\Modules\LMS\Models\LmsQuiz;
use App\Modules\LMS\Models\LmsResource;

/**
 * Gerbang validasi konten (§17).
 *
 * Satu tempat memanggil seluruh validator, supaya penerbitan course, penyimpanan
 * kuis, dan pengunggahan materi memakai aturan yang sama — bukan pemeriksaan
 * yang berbeda-beda di setiap controller.
 */
class ContentValidationService
{
    public function __construct(
        private readonly CourseValidator $courses,
        private readonly SectionValidator $sections,
        private readonly ActivityValidator $activities,
        private readonly ResourceValidator $resources,
        private readonly CompletionValidator $completions,
        private readonly AvailabilityValidator $availability,
        private readonly CertificateValidator $certificates,
        private readonly QuizValidator $quizzes,
        private readonly QuestionValidator $questions,
    ) {}

    /**
     * Validasi menyeluruh sebuah course sebelum penerbitan.
     *
     * @return array{publishable:bool,report:array<string,mixed>,scopes:array<string,array<string,mixed>>}
     */
    public function validateCourse(LmsCourse $course): array
    {
        $report = $this->courses->validate($course);
        $report->merge($this->completions->validateCourse($course));
        $report->merge($this->availability->validateCourse($course));
        $report->merge($this->certificates->validate($course));
        $report->merge($this->sections->validateCourse($course));

        $course->loadMissing(['items.activity', 'sections', 'prerequisites.prerequisite', 'competencies']);

        foreach ($course->items as $item) {
            $report->merge($this->validateItem($item));
        }

        return [
            'publishable' => $report->isPublishable(),
            'report' => $report->toArray(),
            'scopes' => $this->groupByScope($report),
        ];
    }

    /** Validasi satu item (materi atau aktivitas). */
    public function validateItem(LmsLearningItem $item): ValidationReport
    {
        $report = $this->completions->validateItem($item);
        $report->merge($this->availability->validateItem($item));

        if ($item->isActivity()) {
            $activity = $item->activity;

            if ($activity === null) {
                $report->error(
                    'item.activity_missing',
                    'Item "'.$item->title.'" ditandai aktivitas, tetapi konfigurasi aktivitasnya belum ada.',
                    'activity',
                    (int) $item->id,
                    'Buka aktivitas tersebut dan lengkapi pengaturannya.',
                );
            } else {
                $report->merge($this->activities->validate($activity));
            }
        }

        if ($item->isResource()) {
            $resource = $item->resource;

            if ($resource === null) {
                $report->error(
                    'item.resource_missing',
                    'Materi "'.$item->title.'" belum memiliki isi.',
                    'resource',
                    (int) $item->id,
                );
            } else {
                $report->merge($this->resources->validate($resource));
            }
        }

        return $report;
    }

    public function validateSection(LmsCourseSection $section): ValidationReport
    {
        return $this->sections->validate($section);
    }

    public function validateActivity(LmsActivity $activity): ValidationReport
    {
        return $this->activities->validate($activity);
    }

    public function validateResource(LmsResource $resource): ValidationReport
    {
        return $this->resources->validate($resource);
    }

    public function validateQuiz(LmsQuiz $quiz): ValidationReport
    {
        return $this->quizzes->validate($quiz);
    }

    /**
     * Validasi ringan: hanya temuan ERROR, dipakai pada jalur simpan/terbit.
     *
     * @return list<array<string, mixed>>
     */
    public function blockingIssues(LmsCourse $course): array
    {
        return $this->validateCourse($course)['report']['issues'] === []
            ? []
            : array_values(array_filter(
                $this->validateCourse($course)['report']['issues'],
                fn (array $issue) => ($issue['blocks_publication'] ?? false) === true,
            ));
    }

    /**
     * @return array<string, array{publishable:bool,errors:int,warnings:int,issues:list<array<string,mixed>>}>
     */
    private function groupByScope(ValidationReport $report): array
    {
        $grouped = [];

        foreach ($report->issues() as $issue) {
            $scope = $issue->scope;
            $grouped[$scope] ??= ['publishable' => true, 'errors' => 0, 'warnings' => 0, 'issues' => []];
            $grouped[$scope]['issues'][] = $issue->toArray();

            if ($issue->severity->value === 'ERROR') {
                $grouped[$scope]['errors']++;
                $grouped[$scope]['publishable'] = false;
            }

            if ($issue->severity->value === 'WARNING') {
                $grouped[$scope]['warnings']++;
            }
        }

        return $grouped;
    }
}
