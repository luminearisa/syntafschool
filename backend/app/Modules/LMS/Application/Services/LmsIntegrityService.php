<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Application\Validation\PrerequisiteGraphService;
use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Enums\IntegrityStatus;
use App\Modules\LMS\Domain\Enums\ValidationSeverity;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsIntegrityRun;
use Illuminate\Support\Facades\DB;

/**
 * Pemeriksa integritas data LMS (§24).
 *
 * Menemukan data yatim/duplikat/rusak **tanpa memperbaikinya sendiri**:
 * setiap temuan disertai saran perbaikan, dan perbaikan hanya dijalankan bila
 * diminta eksplisit (`--repair`) dengan konfirmasi — sesuai larangan perbaikan
 * destruktif otomatis.
 */
class LmsIntegrityService
{
    use LogsLmsAudit;

    /** @return list<array{key:string,label:string,description:string}> */
    public function checks(): array
    {
        return [
            ['key' => 'orphan_enrollment', 'label' => 'Pendaftaran tanpa course', 'description' => 'Baris lms_enrollments yang course-nya sudah tidak ada.'],
            ['key' => 'orphan_activity', 'label' => 'Aktivitas tanpa course/materi', 'description' => 'Aktivitas yang kehilangan course atau item penampung.'],
            ['key' => 'orphan_submission', 'label' => 'Submission tanpa aktivitas', 'description' => 'Pengumpulan tugas yang tugasnya sudah dihapus.'],
            ['key' => 'orphan_grade', 'label' => 'Nilai tanpa gradebook', 'description' => 'Nilai aktivitas/assessment yang gradebook-nya hilang.'],
            ['key' => 'orphan_completion', 'label' => 'Penyelesaian tanpa enrollment', 'description' => 'Bukti ketuntasan yang pendaftarannya sudah tidak ada.'],
            ['key' => 'orphan_certificate', 'label' => 'Sertifikat tanpa course', 'description' => 'Sertifikat yang course-nya sudah hilang.'],
            ['key' => 'broken_resource', 'label' => 'Berkas materi hilang', 'description' => 'Materi menunjuk berkas yang tidak ada di penyimpanan.'],
            ['key' => 'broken_course_reference', 'label' => 'Rujukan course rusak', 'description' => 'Prasyarat/certificate/learning path menunjuk course yang sudah dihapus.'],
            ['key' => 'invalid_grade', 'label' => 'Nilai tidak sah', 'description' => 'Nilai di luar rentang 0–maksimum atau bernilai negatif.'],
            ['key' => 'duplicate_enrollment', 'label' => 'Pendaftaran ganda', 'description' => 'Siswa yang punya lebih dari satu pendaftaran pada course yang sama.'],
            ['key' => 'invalid_parent_child', 'label' => 'Tautan wali tidak sah', 'description' => 'Tautan wali menunjuk pengguna/siswa yang tidak ada.'],
            ['key' => 'invalid_teacher_course', 'label' => 'Akses guru tidak sah', 'description' => 'Guru pengampu yang akunnya sudah tidak ada.'],
            ['key' => 'prerequisite_cycle', 'label' => 'Prasyarat melingkar', 'description' => 'Rantai prasyarat item/course yang tidak mungkin diselesaikan.'],
        ];
    }

    /**
     * Jalankan pemeriksaan integritas (§24).
     *
     * @return array<string, mixed>
     */
    public function run(LmsActor $actor, string $scope = 'all', bool $persist = true): array
    {
        if (! $actor->can('lms.integrity.run') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak menjalankan pemeriksaan integritas.');
        }

        $started = now();
        $findings = [];

        $findings = array_merge($findings, $this->orphanEnrollments());
        $findings = array_merge($findings, $this->orphanActivities());
        $findings = array_merge($findings, $this->orphanSubmissions());
        $findings = array_merge($findings, $this->orphanGrades());
        $findings = array_merge($findings, $this->orphanCompletions());
        $findings = array_merge($findings, $this->orphanCertificates());
        $findings = array_merge($findings, $this->brokenResources());
        $findings = array_merge($findings, $this->brokenCourseReferences());
        $findings = array_merge($findings, $this->invalidGrades());
        $findings = array_merge($findings, $this->duplicateEnrollments());
        $findings = array_merge($findings, $this->invalidParentChildLinks());
        $findings = array_merge($findings, $this->invalidTeacherCourses());
        $findings = array_merge($findings, $this->prerequisiteCycles());

        $critical = array_filter($findings, fn (array $finding) => $finding['severity'] === ValidationSeverity::ERROR->value);
        $status = $critical !== []
            ? IntegrityStatus::CRITICAL
            : ($findings !== [] ? IntegrityStatus::WARNINGS : IntegrityStatus::OK);

        $result = [
            'status' => $status->value,
            'status_label' => $status->label(),
            'tone' => $status->tone(),
            'checks_run' => count($this->checks()),
            'issues_found' => count($findings),
            'critical' => count($critical),
            'findings' => $findings,
            'duration_ms' => (int) $started->diffInMilliseconds(now()),
            'ran_at' => now()->toIso8601String(),
        ];

        if ($persist) {
            LmsIntegrityRun::query()->create([
                'scope' => $scope,
                'status' => $status->value,
                'checks_run' => $result['checks_run'],
                'issues_found' => $result['issues_found'],
                'findings' => $findings,
                'run_by' => $actor->id,
                'duration_ms' => $result['duration_ms'],
            ]);
        }

        return $result;
    }

    /**
     * Riwayat pemeriksaan.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 20): array
    {
        return LmsIntegrityRun::query()
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (LmsIntegrityRun $run) => [
                'id' => (int) $run->id,
                'scope' => $run->scope,
                'status' => $run->status,
                'checks_run' => (int) $run->checks_run,
                'issues_found' => (int) $run->issues_found,
                'duration_ms' => $run->duration_ms,
                'run_by' => $run->run_by,
                'created_at' => $run->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Perbaikan yang **disarankan** untuk sebuah temuan (§24).
     *
     * Tidak ada perbaikan yang dijalankan otomatis: admin memutuskan.
     *
     * @return array{repairable:bool,suggestion:string,destructive:bool}
     */
    public function repairSuggestion(string $checkKey): array
    {
        return match ($checkKey) {
            'orphan_enrollment' => ['repairable' => true, 'suggestion' => 'Tandai pendaftaran sebagai CANCELLED (bukan dihapus) agar riwayat belajar tetap tersimpan.', 'destructive' => false],
            'orphan_completion', 'orphan_submission', 'orphan_grade' => ['repairable' => false, 'suggestion' => 'Periksa course/aktivitas terkait; bukti belajar sebaiknya dipertahankan, bukan dihapus.', 'destructive' => true],
            'orphan_certificate' => ['repairable' => true, 'suggestion' => 'Ubah status sertifikat menjadi REVOKED dengan alasan, jangan dihapus.', 'destructive' => false],
            'broken_resource' => ['repairable' => true, 'suggestion' => 'Unggah ulang berkas materi atau ubah materinya menjadi tautan eksternal.', 'destructive' => false],
            'duplicate_enrollment' => ['repairable' => true, 'suggestion' => 'Gabungkan pendaftaran (pertahankan yang paling lengkap progresnya) lalu batalkan sisanya.', 'destructive' => false],
            'prerequisite_cycle' => ['repairable' => true, 'suggestion' => 'Putus satu tautan prasyarat pada rantai yang ditampilkan.', 'destructive' => false],
            default => ['repairable' => false, 'suggestion' => 'Telusuri relasi terkait melalui halaman audit sebelum mengubah data.', 'destructive' => true],
        };
    }

    /** @return list<array<string, mixed>> */
    private function orphanEnrollments(): array
    {
        return DB::table('lms_enrollments as e')
            ->leftJoin('lms_courses as c', 'c.id', '=', 'e.course_id')
            ->whereNull('c.id')
            ->limit(50)
            ->get(['e.id', 'e.course_id', 'e.user_id', 'e.status'])
            ->map(fn ($row) => $this->finding(
                'orphan_enrollment',
                ValidationSeverity::ERROR,
                'Pendaftaran #'.$row->id.' menunjuk course yang sudah tidak ada (#'.$row->course_id.').',
                ['enrollment_id' => (int) $row->id, 'course_id' => (int) $row->course_id, 'user_id' => (int) $row->user_id],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function orphanActivities(): array
    {
        return DB::table('lms_activities as a')
            ->leftJoin('lms_courses as c', 'c.id', '=', 'a.course_id')
            ->leftJoin('lms_learning_items as i', 'i.id', '=', 'a.learning_item_id')
            ->where(fn ($query) => $query->whereNull('c.id')->orWhere(fn ($inner) => $inner->whereNull('a.learning_item_id')->orWhereNull('i.id')))
            ->limit(50)
            ->get(['a.id', 'a.title', 'a.course_id', 'a.learning_item_id'])
            ->map(fn ($row) => $this->finding(
                'orphan_activity',
                ValidationSeverity::ERROR,
                'Aktivitas #'.$row->id.' ("'.$row->title.'") kehilangan course atau item penampung.',
                ['activity_id' => (int) $row->id, 'course_id' => $row->course_id !== null ? (int) $row->course_id : null],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function orphanSubmissions(): array
    {
        return DB::table('lms_assignment_submissions as s')
            ->leftJoin('lms_assignments as a', 'a.id', '=', 's.assignment_id')
            ->whereNull('a.id')
            ->limit(50)
            ->get(['s.id', 's.assignment_id', 's.user_id'])
            ->map(fn ($row) => $this->finding(
                'orphan_submission',
                ValidationSeverity::WARNING,
                'Pengumpulan tugas #'.$row->id.' kehilangan aktivitasnya.',
                ['submission_id' => (int) $row->id, 'user_id' => (int) $row->user_id],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function orphanGrades(): array
    {
        return DB::table('lms_activity_grades as g')
            ->leftJoin('lms_activities as a', 'a.id', '=', 'g.activity_id')
            ->whereNull('a.id')
            ->limit(50)
            ->get(['g.id', 'g.activity_id', 'g.user_id', 'g.grade'])
            ->map(fn ($row) => $this->finding(
                'orphan_grade',
                ValidationSeverity::WARNING,
                'Nilai aktivitas #'.$row->id.' kehilangan aktivitasnya.',
                ['grade_id' => (int) $row->id, 'user_id' => (int) $row->user_id],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function orphanCompletions(): array
    {
        return DB::table('lms_activity_completions as ac')
            ->leftJoin('lms_enrollments as e', 'e.id', '=', 'ac.enrollment_id')
            ->whereNull('e.id')
            ->limit(50)
            ->get(['ac.id', 'ac.enrollment_id', 'ac.user_id'])
            ->map(fn ($row) => $this->finding(
                'orphan_completion',
                ValidationSeverity::WARNING,
                'Bukti ketuntasan #'.$row->id.' kehilangan pendaftarannya.',
                ['completion_id' => (int) $row->id, 'user_id' => (int) $row->user_id],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function orphanCertificates(): array
    {
        return DB::table('lms_certificates as cert')
            ->leftJoin('lms_courses as c', 'c.id', '=', 'cert.course_id')
            ->whereNull('c.id')
            ->limit(50)
            ->get(['cert.id', 'cert.certificate_number', 'cert.user_id'])
            ->map(fn ($row) => $this->finding(
                'orphan_certificate',
                ValidationSeverity::WARNING,
                'Sertifikat '.$row->certificate_number.' kehilangan course-nya.',
                ['certificate_id' => (int) $row->id],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function brokenResources(): array
    {
        $files = app(FileStorageProviderInterface::class);

        return DB::table('lms_resources')
            ->whereNotNull('file_path')
            ->limit(200)
            ->get(['id', 'file_path', 'file_name'])
            ->filter(fn ($row) => ! $files->exists((string) $row->file_path))
            ->take(50)
            ->map(fn ($row) => $this->finding(
                'broken_resource',
                ValidationSeverity::ERROR,
                'Berkas materi #'.$row->id.' ("'.($row->file_name ?? basename((string) $row->file_path)).'") tidak ada di penyimpanan.',
                ['resource_id' => (int) $row->id, 'path' => $row->file_path],
            ))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function brokenCourseReferences(): array
    {
        $findings = [];

        $prerequisites = DB::table('lms_course_prerequisites as p')
            ->leftJoin('lms_courses as c', 'c.id', '=', 'p.prerequisite_course_id')
            ->whereNull('c.id')
            ->limit(50)
            ->get(['p.id', 'p.course_id', 'p.prerequisite_course_id']);

        foreach ($prerequisites as $row) {
            $findings[] = $this->finding(
                'broken_course_reference',
                ValidationSeverity::ERROR,
                'Prasyarat #'.$row->id.' menunjuk course yang sudah dihapus (#'.$row->prerequisite_course_id.').',
                ['prerequisite_id' => (int) $row->id, 'course_id' => (int) $row->course_id],
            );
        }

        $certificates = DB::table('lms_courses')
            ->whereNotNull('certificate_required_competency_id')
            ->leftJoin('lms_competencies as k', 'k.id', '=', 'lms_courses.certificate_required_competency_id')
            ->whereNull('k.id')
            ->limit(50)
            ->get(['lms_courses.id as course_id', 'lms_courses.name as name'])
            ->map(fn ($row) => $this->finding(
                'broken_course_reference',
                ValidationSeverity::ERROR,
                'Course "'.$row->name.'" mensyaratkan kompetensi sertifikat yang sudah dihapus.',
                ['course_id' => (int) $row->course_id],
            ))
            ->all();

        return array_merge($findings, $certificates);
    }

    /** @return list<array<string, mixed>> */
    private function invalidGrades(): array
    {
        return DB::table('lms_activity_grades')
            ->where(fn ($query) => $query->where('grade', '<', 0)->orWhereColumn('grade', '>', 'maximum_grade'))
            ->limit(50)
            ->get(['id', 'activity_id', 'user_id', 'grade', 'maximum_grade'])
            ->map(fn ($row) => $this->finding(
                'invalid_grade',
                ValidationSeverity::ERROR,
                'Nilai '.$row->grade.' di luar rentang 0–'.$row->maximum_grade.' (nilai #'.$row->id.').',
                ['grade_id' => (int) $row->id, 'user_id' => (int) $row->user_id],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function duplicateEnrollments(): array
    {
        return DB::table('lms_enrollments')
            ->select('course_id', 'user_id', DB::raw('count(*) as total'))
            ->where('role', 'STUDENT')
            ->groupBy('course_id', 'user_id')
            ->having('total', '>', 1)
            ->limit(50)
            ->get()
            ->map(fn ($row) => $this->finding(
                'duplicate_enrollment',
                ValidationSeverity::WARNING,
                'Siswa #'.$row->user_id.' terdaftar '.$row->total.' kali pada course #'.$row->course_id.'.',
                ['course_id' => (int) $row->course_id, 'user_id' => (int) $row->user_id, 'total' => (int) $row->total],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function invalidParentChildLinks(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('lms_guardians')) {
            return [];
        }

        return DB::table('lms_guardians as g')
            ->leftJoin('lms_users as u', 'u.id', '=', 'g.lms_user_id')
            ->leftJoin('lms_learners as l', 'l.id', '=', 'g.lms_learner_id')
            ->where(fn ($query) => $query->whereNull('u.id')->orWhereNull('l.id'))
            ->limit(50)
            ->get(['g.id', 'g.lms_user_id', 'g.lms_learner_id'])
            ->map(fn ($row) => $this->finding(
                'invalid_parent_child',
                ValidationSeverity::ERROR,
                'Tautan wali #'.$row->id.' menunjuk akun atau siswa yang tidak ada.',
                ['guardian_id' => (int) $row->id],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function invalidTeacherCourses(): array
    {
        return DB::table('lms_course_teachers as ct')
            ->leftJoin('users as u', 'u.id', '=', 'ct.user_id')
            ->whereNotNull('ct.user_id')
            ->whereNull('u.id')
            ->limit(50)
            ->get(['ct.id', 'ct.course_id', 'ct.user_id'])
            ->map(fn ($row) => $this->finding(
                'invalid_teacher_course',
                ValidationSeverity::WARNING,
                'Guru pengampu #'.$row->user_id.' pada course #'.$row->course_id.' sudah tidak ada.',
                ['course_teacher_id' => (int) $row->id, 'course_id' => (int) $row->course_id],
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function prerequisiteCycles(): array
    {
        $graph = app(PrerequisiteGraphService::class);
        $findings = [];

        foreach (LmsCourse::query()->orderBy('id')->limit(500)->get() as $course) {
            foreach ($graph->itemCycles($course) as $cycle) {
                $findings[] = $this->finding(
                    'prerequisite_cycle',
                    ValidationSeverity::ERROR,
                    'Prasyarat materi melingkar pada course "'.$course->name.'": '.implode(' → ', $cycle['labels']).'.',
                    ['course_id' => (int) $course->id, 'cycle' => $cycle['path']],
                );
            }
        }

        foreach ($graph->courseCycles() as $cycle) {
            $findings[] = $this->finding(
                'prerequisite_cycle',
                ValidationSeverity::ERROR,
                'Prasyarat antar-course melingkar: '.implode(' → ', $cycle['labels']).'.',
                ['cycle' => $cycle['path']],
            );
        }

        return $findings;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function finding(string $check, ValidationSeverity $severity, string $message, array $context): array
    {
        return [
            'check' => $check,
            'severity' => $severity->value,
            'severity_label' => $severity->label(),
            'message' => $message,
            'context' => $context,
            'repair' => $this->repairSuggestion($check),
        ];
    }
}
