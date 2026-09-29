<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Enums\CertificateRequirementType;
use App\Modules\LMS\Domain\Enums\CertificateStatus;
use App\Modules\LMS\Domain\Enums\CourseCompletionRule;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Domain\Enums\GradeReleaseStatus;
use App\Modules\LMS\Domain\Enums\LearningEventType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsCertificate;
use App\Modules\LMS\Models\LmsCertificateTemplate;
use App\Modules\LMS\Models\LmsCertificateVerification;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseProgress;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsGradeRelease;
use App\Modules\LMS\Models\LmsStudentCompetency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sertifikat penyelesaian course (§21–§25).
 *
 * Aturan yang dipegang:
 *  - kelayakan SELALU dihitung di server dan ditampilkan sebagai daftar syarat,
 *    bukan boolean tanpa alasan (§21);
 *  - penerbitan idempoten — `enrollment_id` unik membuat job dobel atau klik
 *    ganda tidak pernah menghasilkan sertifikat kedua (§23);
 *  - nomor & token verifikasi tidak pernah diubah setelah terbit, dan isi
 *    sertifikat disimpan sebagai snapshot supaya nama lama tetap konsisten (§23);
 *  - verifikasi publik hanya membuka data minimum: nama, judul course, tanggal,
 *    status — tanpa data pribadi lain (§24).
 */
class CertificateService
{
    use LogsLmsAudit;

    private const PLACEHOLDERS = [
        '{{student_name}}',
        '{{course_name}}',
        '{{certificate_number}}',
        '{{grade}}',
        '{{completion_percent}}',
        '{{issued_date}}',
        '{{issuer}}',
        '{{signer_name}}',
        '{{signer_title}}',
    ];

    public function __construct(
        private readonly GradeCalculationService $grades,
        private readonly LearningEventService $events,
        private readonly LmsNotifier $notifier,
    ) {}

    /** @return list<array<string, mixed>> */
    public function requirementTypes(): array
    {
        return array_map(fn (CertificateRequirementType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ], CertificateRequirementType::cases());
    }

    /** @return list<string> */
    public function placeholders(): array
    {
        return self::PLACEHOLDERS;
    }

    public function find(int $id): LmsCertificate
    {
        $certificate = LmsCertificate::query()->with(['course', 'template'])->find($id);

        if ($certificate === null) {
            LmsFault::notFound('Sertifikat tidak ditemukan.');
        }

        return $certificate;
    }

    // ------------------------------------------------------------------ kelayakan

    /**
     * Daftar syarat sertifikat beserta statusnya (§21).
     *
     * @return array<string, mixed>
     */
    public function eligibility(LmsEnrollment $enrollment): array
    {
        $course = $enrollment->course ?? LmsCourse::query()->find($enrollment->course_id);
        $certificate = LmsCertificate::query()->where('enrollment_id', $enrollment->id)->first();

        if ($course === null) {
            return [
                'eligible' => false,
                'requirements' => [],
                'missing' => ['Course tidak ditemukan.'],
                'certificate' => null,
            ];
        }

        $progress = $this->progressPercent($enrollment);
        $grade = $this->gradeFor($enrollment);
        $requirements = [];

        $requirements[] = $this->requirement(
            'certificate_enabled',
            'Sertifikat diaktifkan untuk course',
            $course->certificateEnabled() && (bool) config('lms.certificate.enabled', true),
            $course->certificateEnabled() ? 'Aktif' : 'Belum diaktifkan guru',
        );

        $requirements[] = $this->completionRequirement($course, $enrollment, $progress, $grade);

        if ($course->certificate_min_progress !== null) {
            $minimum = (float) $course->certificate_min_progress;

            $requirements[] = $this->requirement(
                CertificateRequirementType::COMPLETION_PERCENTAGE->value,
                'Progress minimum '.$this->formatNumber($minimum).'%',
                $progress >= $minimum,
                $this->formatNumber($progress).'%',
            );
        }

        if ($course->certificate_min_grade !== null) {
            $minimum = (float) $course->certificate_min_grade;

            $requirements[] = $this->requirement(
                CertificateRequirementType::MINIMUM_GRADE->value,
                'Nilai minimum '.$this->formatNumber($minimum),
                $grade !== null && $grade >= $minimum,
                $grade === null ? 'Nilai belum ada' : $this->formatNumber($grade),
            );
        }

        if ($course->certificate_required_competency_id !== null) {
            $competencyId = (int) $course->certificate_required_competency_id;

            $achieved = LmsStudentCompetency::query()
                ->where('competency_id', $competencyId)
                ->where('course_id', $course->id)
                ->where('user_id', $enrollment->user_id)
                ->get()
                ->contains(fn (LmsStudentCompetency $row) => $row->isAchieved());

            $requirements[] = $this->requirement(
                CertificateRequirementType::COMPETENCY->value,
                'Kompetensi wajib tercapai',
                $achieved,
                $achieved ? 'Tercapai' : 'Belum tercapai',
            );
        }

        $missing = array_values(array_map(
            fn (array $row) => $row['label'],
            array_filter($requirements, fn (array $row) => ! $row['met']),
        ));

        return [
            'eligible' => $missing === [],
            'requirements' => $requirements,
            'missing' => $missing,
            'progress_percent' => $progress,
            'grade_value' => $grade,
            'certificate' => $certificate === null ? null : $this->payload($certificate),
        ];
    }

    /**
     * Terbitkan sertifikat untuk satu enrollment (idempoten).
     *
     * @param  array<string, mixed>  $options  template_id, force, note
     */
    public function issue(LmsEnrollment $enrollment, LmsActor $actor, array $options = []): LmsCertificate
    {
        $force = (bool) ($options['force'] ?? false);

        if (! $force) {
            $eligibility = $this->eligibility($enrollment);

            if (! $eligibility['eligible']) {
                LmsFault::conflict('Syarat sertifikat belum terpenuhi: '.implode(', ', $eligibility['missing']).'.');
            }
        }

        $course = $enrollment->course ?? LmsCourse::query()->findOrFail($enrollment->course_id);

        $certificate = DB::transaction(function () use ($enrollment, $course, $actor, $options): LmsCertificate {
            $existing = LmsCertificate::query()
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $template = $this->resolveTemplate($course, $options['template_id'] ?? null);
            $grade = $this->gradeFor($enrollment);
            $progress = $this->progressPercent($enrollment);

            return LmsCertificate::query()->create([
                'template_id' => $template?->id,
                'course_id' => $course->id,
                'enrollment_id' => $enrollment->id,
                'user_id' => (int) $enrollment->user_id,
                'student_id' => $enrollment->student_id === null ? null : (int) $enrollment->student_id,
                'certificate_number' => $this->nextNumber(),
                'verification_token' => $this->nextToken(),
                'status' => CertificateStatus::ISSUED,
                'grade_value' => $grade,
                'completion_percent' => $progress,
                'snapshot' => $this->snapshot($enrollment, $course, $grade, $progress, $actor),
                'issued_at' => now(),
                'issued_by' => $actor->id,
            ]);
        });

        if ($certificate->wasRecentlyCreated) {
            $this->events->record(
                LearningEventType::CERTIFICATE_ISSUED,
                $actor,
                $course,
                ['certificate_number' => $certificate->certificate_number],
            );

            $this->notifier->certificateIssued([(int) $enrollment->user_id], [
                'title' => 'Sertifikat terbit',
                'message' => 'Sertifikat penyelesaian course '.$course->name
                    .' sudah terbit dengan nomor '.$certificate->certificate_number.'.',
                'url' => '/lms/certificates/'.$certificate->id,
                'course_id' => (int) $course->id,
                'course_name' => (string) $course->name,
                'certificate' => [
                    'id' => (int) $certificate->id,
                    'number' => $certificate->certificate_number,
                    'verification_token' => $certificate->verification_token,
                ],
                'student_name' => $certificate->holderName(),
                'issued_at' => $certificate->issued_at?->toIso8601String(),
            ]);

            $this->audit('LMS_CERTIFICATE_ISSUED', $certificate, [
                'enrollment_id' => (int) $enrollment->id,
                'number' => $certificate->certificate_number,
            ]);
        }

        return $certificate;
    }

    /** Terbitkan bila memenuhi syarat, tanpa melempar error bila belum (§23). */
    public function issueIfEligible(LmsEnrollment $enrollment, LmsActor $actor, array $options = []): ?LmsCertificate
    {
        $eligibility = $this->eligibility($enrollment);

        if (! $eligibility['eligible']) {
            return null;
        }

        return $this->issue($enrollment, $actor, $options);
    }

    /**
     * Terbitkan untuk semua siswa yang memenuhi syarat pada satu course.
     *
     * @return array<string, mixed>
     */
    public function issueForCourse(LmsCourse $course, LmsActor $actor, bool $force = false): array
    {
        $issued = [];
        $skipped = [];

        $course->enrollments()
            ->where('role', 'STUDENT')
            ->whereIn('status', [EnrollmentStatus::ACTIVE->value, EnrollmentStatus::COMPLETED->value])
            ->chunkById(100, function ($enrollments) use (&$issued, &$skipped, $actor, $force): void {
                foreach ($enrollments as $enrollment) {
                    $certificate = $force
                        ? $this->issue($enrollment, $actor, ['force' => true])
                        : $this->issueIfEligible($enrollment, $actor);

                    if ($certificate === null) {
                        $skipped[] = ['enrollment_id' => (int) $enrollment->id, 'user_id' => (int) $enrollment->user_id];

                        continue;
                    }

                    $issued[] = [
                        'enrollment_id' => (int) $enrollment->id,
                        'user_id' => (int) $enrollment->user_id,
                        'certificate_number' => $certificate->certificate_number,
                    ];
                }
            });

        return ['issued' => $issued, 'skipped' => $skipped];
    }

    /** Daftar siswa + status kelayakan (dipakai UI guru sebelum menerbitkan). */
    public function eligibleStudents(LmsCourse $course): array
    {
        $rows = [];

        $course->enrollments()
            ->where('role', 'STUDENT')
            ->with('user')
            ->chunkById(100, function ($enrollments) use (&$rows): void {
                foreach ($enrollments as $enrollment) {
                    $eligibility = $this->eligibility($enrollment);

                    $rows[] = [
                        'enrollment_id' => (int) $enrollment->id,
                        'user_id' => (int) $enrollment->user_id,
                        'name' => $enrollment->user?->name,
                        'eligible' => $eligibility['eligible'],
                        'missing' => $eligibility['missing'],
                        'progress_percent' => $eligibility['progress_percent'],
                        'grade_value' => $eligibility['grade_value'],
                        'certificate_number' => $eligibility['certificate']['certificate_number'] ?? null,
                    ];
                }
            });

        return $rows;
    }

    /** Cabut sertifikat (§25) — nomor tetap tercatat, status berubah jadi REVOKED. */
    public function revoke(LmsCertificate $certificate, LmsActor $actor, string $reason): LmsCertificate
    {
        if ($certificate->status === CertificateStatus::REVOKED) {
            return $certificate;
        }

        $certificate->fill([
            'status' => CertificateStatus::REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();

        $this->audit('LMS_CERTIFICATE_REVOKED', $certificate, [
            'number' => $certificate->certificate_number,
            'reason' => $reason,
        ]);

        return $certificate;
    }

    /**
     * Verifikasi publik lewat nomor atau token (§24).
     *
     * Setiap pemeriksaan dicatat tanpa data pribadi berlebih — hanya hasil,
     * waktu, dan alamat pemeriksa.
     *
     * @return array<string, mixed>
     */
    public function verify(string $query, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $needle = trim($query);

        $certificate = $needle === '' ? null : LmsCertificate::query()
            ->where('certificate_number', $needle)
            ->orWhere('verification_token', $needle)
            ->first();

        $result = match (true) {
            $certificate === null => LmsCertificateVerification::RESULT_NOT_FOUND,
            $certificate->status === CertificateStatus::ISSUED => LmsCertificateVerification::RESULT_VALID,
            default => LmsCertificateVerification::RESULT_REVOKED,
        };

        LmsCertificateVerification::query()->create([
            'certificate_id' => $certificate?->id,
            'certificate_number' => $certificate?->certificate_number ?? Str::limit($needle, 60, ''),
            'result' => $result,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 255, ''),
            'checked_at' => now(),
        ]);

        return [
            'result' => $result,
            'valid' => $result === LmsCertificateVerification::RESULT_VALID,
            'certificate' => $certificate === null ? null : $this->publicPayload($certificate),
        ];
    }

    /** Mencatat unduhan & mengembalikan berkas yang boleh diunduh (§25). */
    public function registerDownload(LmsCertificate $certificate): array
    {
        if ($certificate->status !== CertificateStatus::ISSUED) {
            LmsFault::forbidden('Sertifikat yang dicabut tidak dapat diunduh.');
        }

        $certificate->forceFill([
            'download_count' => (int) $certificate->download_count + 1,
            'last_downloaded_at' => now(),
        ])->save();

        return [
            'file_path' => $certificate->file_path,
            'download_count' => (int) $certificate->download_count,
            'certificate_number' => $certificate->certificate_number,
        ];
    }

    /**
     * Isi sertifikat siap cetak: placeholder diganti dari snapshot (§23).
     *
     * @return array<string, mixed>
     */
    public function render(LmsCertificate $certificate): array
    {
        $template = $certificate->template ?? $this->resolveTemplate($certificate->course);

        $body = $template?->body_html ?? $this->defaultBody();

        return [
            'certificate_number' => $certificate->certificate_number,
            'status' => $certificate->status->value,
            'body_html' => str_replace(self::PLACEHOLDERS, [
                $certificate->holderName(),
                $certificate->courseName(),
                $certificate->certificate_number,
                $certificate->grade_value === null ? '-' : $this->formatNumber((float) $certificate->grade_value),
                $certificate->completion_percent === null ? '-' : $this->formatNumber((float) $certificate->completion_percent).'%',
                $certificate->issued_at?->translatedFormat('d F Y') ?? '-',
                $template?->issuer_name ?? config('lms.mail.from_name', config('app.name')),
                $template?->signer_name ?? '-',
                $template?->signer_title ?? '-',
            ], $body),
            'template' => $template === null ? null : [
                'id' => (int) $template->id,
                'title' => $template->title,
                'background_path' => $template->background_path,
                'logo_path' => $template->logo_path,
                'signature_path' => $template->signature_path,
                'orientation' => $template->orientation,
                'paper_size' => $template->paper_size,
            ],
            'verification' => [
                'token' => $certificate->verification_token,
                'url' => '/lms/certificates/verify/'.$certificate->verification_token,
            ],
        ];
    }

    // ------------------------------------------------------------------ daftar & payload

    /** @return list<array<string, mixed>> */
    /**
     * Riwayat verifikasi publik sebuah sertifikat (§25, §23).
     *
     * @return list<array<string, mixed>>
     */
    public function verifications(LmsCertificate $certificate, int $limit = 50): array
    {
        return LmsCertificateVerification::query()
            ->where('certificate_id', $certificate->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (LmsCertificateVerification $verification) => [
                'id' => (int) $verification->id,
                'status' => $verification->status,
                'result' => $verification->result ?? null,
                'ip_address' => $verification->ip_address,
                'created_at' => $verification->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function forUser(int $userId, ?int $courseId = null): array
    {
        return LmsCertificate::query()
            ->with('course')
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (LmsCertificate $certificate) => $this->payload($certificate))
            ->all();
    }

    /**
     * Daftar sertifikat sebuah course untuk guru (§25).
     *
     * @param  array<string, mixed>  $filters  status, user_id, from, to
     * @return list<array<string, mixed>>
     */
    public function forCourse(LmsCourse $course, array $filters = []): array
    {
        return LmsCertificate::query()
            ->with('course')
            ->where('course_id', $course->id)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', (int) $filters['user_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('issued_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('issued_at', '<=', $filters['to']))
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (LmsCertificate $certificate) => $this->payload($certificate))
            ->all();
    }

    /** Statistik singkat untuk dasbor sertifikat course. */
    public function statistics(LmsCourse $course): array
    {
        $rows = LmsCertificate::query()->where('course_id', $course->id)->get();

        return [
            'total' => $rows->count(),
            'issued' => $rows->where('status', CertificateStatus::ISSUED)->count(),
            'revoked' => $rows->where('status', CertificateStatus::REVOKED)->count(),
            'downloads' => (int) $rows->sum('download_count'),
            'last_issued_at' => $rows->max('issued_at')?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function payload(LmsCertificate $certificate): array
    {
        return [
            'id' => (int) $certificate->id,
            'certificate_number' => $certificate->certificate_number,
            'verification_token' => $certificate->verification_token,
            'status' => $certificate->status->value,
            'status_label' => $certificate->status->label(),
            'course_id' => (int) $certificate->course_id,
            'course_name' => $certificate->courseName(),
            'enrollment_id' => $certificate->enrollment_id === null ? null : (int) $certificate->enrollment_id,
            'user_id' => (int) $certificate->user_id,
            'student_name' => $certificate->holderName(),
            'grade_value' => $certificate->grade_value === null ? null : (float) $certificate->grade_value,
            'completion_percent' => $certificate->completion_percent === null ? null : (float) $certificate->completion_percent,
            'file_path' => $certificate->file_path,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'revoked_at' => $certificate->revoked_at?->toIso8601String(),
            'revoked_reason' => $certificate->revoked_reason,
            'download_count' => (int) $certificate->download_count,
        ];
    }

    // ------------------------------------------------------------------ template

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        return LmsCertificateTemplate::query()
            ->orderByDesc('is_default')
            ->orderBy('title')
            ->get()
            ->map(fn (LmsCertificateTemplate $template) => $this->templatePayload($template))
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function createTemplate(array $data, LmsActor $actor): LmsCertificateTemplate
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            LmsFault::invalid('Judul template wajib diisi.', 'title');
        }

        $template = LmsCertificateTemplate::query()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'background_path' => $data['background_path'] ?? null,
            'logo_path' => $data['logo_path'] ?? null,
            'signature_path' => $data['signature_path'] ?? null,
            'signer_name' => $data['signer_name'] ?? null,
            'signer_title' => $data['signer_title'] ?? null,
            'issuer_name' => $data['issuer_name'] ?? null,
            'body_html' => $data['body_html'] ?? null,
            'template_data' => $data['template_data'] ?? null,
            'orientation' => $data['orientation'] ?? 'landscape',
            'paper_size' => $data['paper_size'] ?? 'a4',
            'is_default' => (bool) ($data['is_default'] ?? false),
            'status' => $data['status'] ?? 'ACTIVE',
            'created_by' => $actor->id,
        ]);

        if ($template->is_default) {
            $this->markDefault($template, $actor, false);
        }

        $this->audit('LMS_CERTIFICATE_TEMPLATE_CREATED', $template, ['title' => $template->title]);

        return $template;
    }

    /** @param array<string, mixed> $data */
    public function updateTemplate(LmsCertificateTemplate $template, array $data, LmsActor $actor): LmsCertificateTemplate
    {
        $template->fill(array_filter([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'background_path' => $data['background_path'] ?? null,
            'logo_path' => $data['logo_path'] ?? null,
            'signature_path' => $data['signature_path'] ?? null,
            'signer_name' => $data['signer_name'] ?? null,
            'signer_title' => $data['signer_title'] ?? null,
            'issuer_name' => $data['issuer_name'] ?? null,
            'orientation' => $data['orientation'] ?? null,
            'paper_size' => $data['paper_size'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($value) => $value !== null));

        foreach (['body_html', 'template_data'] as $key) {
            if (array_key_exists($key, $data)) {
                $template->{$key} = $data[$key];
            }
        }

        if (array_key_exists('is_default', $data)) {
            $template->is_default = (bool) $data['is_default'];
        }

        $template->save();

        if ($template->is_default) {
            $this->markDefault($template, $actor, false);
        }

        $this->audit('LMS_CERTIFICATE_TEMPLATE_UPDATED', $template, ['title' => $template->title]);

        return $template->refresh();
    }

    public function deleteTemplate(LmsCertificateTemplate $template, LmsActor $actor): void
    {
        $this->audit('LMS_CERTIFICATE_TEMPLATE_DELETED', $template, ['title' => $template->title]);

        $template->delete();
    }

    public function markDefault(LmsCertificateTemplate $template, LmsActor $actor, bool $audit = true): LmsCertificateTemplate
    {
        DB::transaction(function () use ($template): void {
            LmsCertificateTemplate::query()
                ->whereKeyNot($template->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $template->forceFill(['is_default' => true])->save();
        });

        if ($audit) {
            $this->audit('LMS_CERTIFICATE_TEMPLATE_DEFAULT', $template, ['title' => $template->title]);
        }

        return $template->refresh();
    }

    /** @return array<string, mixed> */
    public function templatePayload(LmsCertificateTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'title' => $template->title,
            'description' => $template->description,
            'background_path' => $template->background_path,
            'logo_path' => $template->logo_path,
            'signature_path' => $template->signature_path,
            'signer_name' => $template->signer_name,
            'signer_title' => $template->signer_title,
            'issuer_name' => $template->issuer_name,
            'body_html' => $template->body_html,
            'template_data' => $template->template_data,
            'orientation' => $template->orientation,
            'paper_size' => $template->paper_size,
            'is_default' => (bool) $template->is_default,
            'status' => $template->status,
        ];
    }

    // ------------------------------------------------------------------ internal

    /** @return array<string, mixed> */
    private function requirement(string $key, string $label, bool $met, ?string $detail = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'met' => $met,
            'detail' => $detail,
        ];
    }

    /**
     * Syarat tuntas course mengikuti `completion_rule` course (§40) supaya
     * sertifikat tidak memakai definisi kelulusan yang berbeda dari rapor.
     *
     * @return array<string, mixed>
     */
    private function completionRequirement(LmsCourse $course, LmsEnrollment $enrollment, float $progress, ?float $grade): array
    {
        $rule = $course->completion_rule ?? CourseCompletionRule::ALL_REQUIRED_ACTIVITIES;

        $met = match ($rule) {
            CourseCompletionRule::MINIMUM_PROGRESS => $progress >= (float) ($course->completion_min_progress ?? 100),
            CourseCompletionRule::MINIMUM_GRADE => $grade !== null && $grade >= (float) ($course->completion_min_grade ?? config('lms.certificate.passing_grade', 70)),
            CourseCompletionRule::REQUIRED_FINAL_ASSESSMENT => $this->finalAssessmentPassed($course, $enrollment),
            CourseCompletionRule::MANUAL => $enrollment->status === EnrollmentStatus::COMPLETED,
            default => $progress >= 100.0,
        };

        return $this->requirement(
            'course_completion',
            'Course tuntas ('.$rule->label().')',
            $met,
            $this->formatNumber($progress).'%',
        );
    }

    private function finalAssessmentPassed(LmsCourse $course, LmsEnrollment $enrollment): bool
    {
        if ($course->required_final_activity_id === null) {
            return false;
        }

        $itemId = LmsActivity::query()
            ->whereKey((int) $course->required_final_activity_id)
            ->value('learning_item_id');

        if ($itemId === null) {
            return false;
        }

        return LmsActivityCompletion::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('learning_item_id', $itemId)
            ->where('status', 'COMPLETE')
            ->exists();
    }

    private function progressPercent(LmsEnrollment $enrollment): float
    {
        $progress = LmsCourseProgress::query()->where('enrollment_id', $enrollment->id)->first();

        return (float) ($progress?->progress_percent ?? $enrollment->progress_percent ?? 0);
    }

    /**
     * Nilai yang dipakai sertifikat: nilai yang sudah DIRILIS bila ada, selain
     * itu perhitungan terbaru — supaya sertifikat tidak pernah memakai angka
     * yang belum pernah dilihat siswa.
     */
    private function gradeFor(LmsEnrollment $enrollment): ?float
    {
        $released = LmsGradeRelease::query()
            ->where('user_id', $enrollment->user_id)
            ->whereHas('gradebook', fn ($query) => $query->where('course_id', $enrollment->course_id))
            ->whereIn('status', [GradeReleaseStatus::RELEASED->value, GradeReleaseStatus::LOCKED->value])
            ->orderByDesc('id')
            ->first();

        if ($released !== null && $released->normalized_score !== null) {
            return (float) $released->normalized_score;
        }

        $gradebook = LmsGradebook::query()->where('course_id', $enrollment->course_id)->first();

        if ($gradebook === null) {
            return null;
        }

        $calculation = $this->grades->calculate($gradebook, (int) $enrollment->user_id);

        return $calculation['normalized'] === null ? null : (float) $calculation['normalized'];
    }

    /** @return array<string, mixed> */
    private function snapshot(LmsEnrollment $enrollment, LmsCourse $course, ?float $grade, float $progress, LmsActor $actor): array
    {
        return [
            'student_name' => $enrollment->user?->name,
            'course_name' => (string) $course->name,
            'course_code' => $course->code ?? null,
            'grade_value' => $grade,
            'completion_percent' => $progress,
            'issued_by' => $actor->name,
            'issued_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function publicPayload(LmsCertificate $certificate): array
    {
        return [
            'certificate_number' => $certificate->certificate_number,
            'status' => $certificate->status->value,
            'status_label' => $certificate->status->label(),
            'student_name' => $certificate->holderName(),
            'course_name' => $certificate->courseName(),
            'grade_value' => $certificate->grade_value === null ? null : (float) $certificate->grade_value,
            'completion_percent' => $certificate->completion_percent === null ? null : (float) $certificate->completion_percent,
            'issued_at' => $certificate->issued_at?->toDateString(),
            'revoked_reason' => $certificate->revoked_reason,
        ];
    }

    private function resolveTemplate(?LmsCourse $course, ?int $templateId = null): ?LmsCertificateTemplate
    {
        $id = $templateId ?? $course?->certificate_template_id;

        if ($id !== null) {
            $template = LmsCertificateTemplate::query()->active()->find($id);

            if ($template !== null) {
                return $template;
            }
        }

        return LmsCertificateTemplate::query()->active()->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** Nomor urut per tahun: CERT-2026-000001 (§23). */
    private function nextNumber(): string
    {
        $prefix = (string) config('lms.certificate.sequence_prefix', 'CERT');
        $padding = max(3, (int) config('lms.certificate.sequence_padding', 6));
        $year = now()->year;

        $last = LmsCertificate::query()
            ->where('certificate_number', 'like', $prefix.'-'.$year.'-%')
            ->orderByDesc('certificate_number')
            ->value('certificate_number');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, -$padding)) + 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $prefix.'-'.$year.'-'.str_pad((string) ($sequence + $attempt), $padding, '0', STR_PAD_LEFT);

            if (! LmsCertificate::query()->where('certificate_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $prefix.'-'.$year.'-'.str_pad((string) random_int(100000, 999999), $padding, '0', STR_PAD_LEFT);
    }

    private function nextToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = (string) config('lms.certificate.verify_prefix', 'LMS-CERT').'-'.Str::random(32);

            if (! LmsCertificate::query()->where('verification_token', $token)->exists()) {
                return $token;
            }
        }

        return (string) config('lms.certificate.verify_prefix', 'LMS-CERT').'-'.Str::uuid()->toString();
    }

    private function defaultBody(): string
    {
        return '<h1>Sertifikat Penyelesaian</h1>'
            .'<p>Diberikan kepada <strong>{{student_name}}</strong></p>'
            .'<p>atas penyelesaian course {{course_name}} dengan nilai {{grade}} ({{completion_percent}}).</p>'
            .'<p>Nomor: {{certificate_number}} — {{issued_date}}</p>'
            .'<p>{{issuer}}</p>';
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** Dipakai saat job/queue menerbitkan sertifikat tanpa aktor manusia. */
    public function systemActor(): LmsActor
    {
        return new LmsActor(
            id: 0,
            name: 'Sistem LMS',
            email: null,
            avatarPath: null,
            primaryRole: ActorRole::ADMIN,
            roles: [],
            permissions: [],
            superAdmin: false,
            standalone: (bool) config('lms.standalone', false),
        );
    }
}
