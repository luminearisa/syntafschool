<?php

namespace Tests\Feature\Lms;

use App\Models\Student;
use App\Modules\LMS\Application\Services\CertificateService;
use App\Modules\LMS\Application\Services\CourseBackupService;
use App\Modules\LMS\Application\Services\CourseExportService;
use App\Modules\LMS\Application\Services\CourseImportService;
use App\Modules\LMS\Application\Services\CoursePrerequisiteService;
use App\Modules\LMS\Application\Services\CoursePublishService;
use App\Modules\LMS\Application\Services\GradeSyncQueueService;
use App\Modules\LMS\Application\Services\GradeSyncService;
use App\Modules\LMS\Application\Services\LmsAuditService;
use App\Modules\LMS\Application\Services\LmsGateway;
use App\Modules\LMS\Application\Services\LmsIntegrityService;
use App\Modules\LMS\Application\Services\LmsReportService;
use App\Modules\LMS\Application\Services\ScormPackageService;
use App\Modules\LMS\Application\Services\ScormTrackingService;
use App\Modules\LMS\Application\Services\SystemHealthService;
use App\Modules\LMS\Application\Validation\ContentValidationService;
use App\Modules\LMS\Domain\Enums\ImportConflictStrategy;
use App\Modules\LMS\Domain\Enums\ReportFormat;
use App\Modules\LMS\Domain\Enums\ReportKey;
use App\Modules\LMS\Domain\Enums\ScormPackageStatus;
use App\Modules\LMS\Domain\Support\LmsSystemActor;
use App\Modules\LMS\Models\LmsAuditLog;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCoursePrerequisite;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGradeSync;
use App\Modules\LMS\Models\LmsItemPrerequisite;
use App\Modules\LMS\Models\LmsLearningItem;
use App\Modules\LMS\Models\LmsScormAttempt;
use App\Modules\LMS\Models\LmsScormPackage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/**
 * Phase 5 \u2014 validasi, integrasi, antrean, interoperabilitas, dan tata kelola
 * (\u00a76, \u00a712, \u00a714\u2013\u00a718, \u00a722\u2013\u00a724, \u00a742).
 */
class LmsPhase5GovernanceTest extends LmsTestCase
{
    // ---------------------------------------------------------------- validasi

    public function test_validasi_course_menahan_publikasi_ketika_isi_rusak(): void
    {
        $world = $this->world();
        $course = $this->makeCourse($world);
        $this->makeSection($course, ['title' => '']); // judul bagian kosong

        $teacher = $this->actingAsTeacher($world['teacher']);

        $readiness = app(CoursePublishService::class)->readiness($course);

        $this->assertFalse($readiness['ready']);
        $this->assertNotEmpty($readiness['validation']['issues']);

        $this->expectException(ValidationException::class);

        app(CoursePublishService::class)->publish($course, $this->actorFor($teacher));
    }

    public function test_siklus_prasyarat_item_ditolak_validator(): void
    {
        $world = $this->world();
        $course = $this->makeCourse($world);
        $section = $this->makeSection($course);

        $first = $this->makePageItem($section, ['title' => 'A']);
        $second = $this->makePageItem($section, ['title' => 'B']);
        $third = $this->makePageItem($section, ['title' => 'C']);

        LmsItemPrerequisite::query()->create([
            'course_id' => $course->id,
            'item_id' => $second->id,
            'prerequisite_item_id' => $first->id,
            'type' => 'COMPLETION',
        ]);
        LmsItemPrerequisite::query()->create([
            'course_id' => $course->id,
            'item_id' => $third->id,
            'prerequisite_item_id' => $second->id,
            'type' => 'COMPLETION',
        ]);
        LmsItemPrerequisite::query()->create([
            'course_id' => $course->id,
            'item_id' => $first->id,
            'prerequisite_item_id' => $third->id,
            'type' => 'COMPLETION',
        ]);

        $report = app(ContentValidationService::class)->validateCourse($course);

        $codes = array_column($report['report']['issues'], 'code');

        $this->assertContains('course.prerequisite_cycle', $codes);
        $this->assertFalse($report['publishable']);
    }

    public function test_siklus_prasyarat_antar_course_ditolak_saat_ditetapkan(): void
    {
        $world = $this->world();
        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        $a = $this->makeCourse($world, ['name' => 'Course A', 'code' => 'A-1']);
        $b = $this->makeCourse($world, ['name' => 'Course B', 'code' => 'B-1']);
        $c = $this->makeCourse($world, ['name' => 'Course C', 'code' => 'C-1']);

        $service = app(CoursePrerequisiteService::class);

        $this->assertTrue($service->attach($b, $a, $actor)['attached']);
        $this->assertTrue($service->attach($c, $b, $actor)['attached']);

        $cycle = $service->attach($a, $c, $actor);

        $this->assertFalse($cycle['attached']);
        $this->assertNotNull($cycle['cycle']);
        $this->assertSame(0, LmsCoursePrerequisite::query()->where('course_id', $a->id)->count());
    }

    public function test_prasyarat_course_menahan_akses_sampai_tuntas(): void
    {
        $world = $this->world();
        $teacher = $this->actingAsTeacher($world['teacher']);
        $student = $world['students'][0];
        $user = $this->actingAsStudent($student);
        $actor = $this->actorFor($user);

        $intro = $this->makeCourse($world, ['name' => 'Pengantar', 'code' => 'INTRO-1']);
        $advanced = $this->publishCourse($this->makeCourse($world, ['name' => 'Lanjutan', 'code' => 'ADV-1']), $world);

        app(CoursePrerequisiteService::class)->attach($advanced, $intro, $this->actorFor($teacher));

        $enrollment = $this->enroll($advanced, $user, ['status' => 'ACTIVE']);
        $this->assertNotNull($enrollment);

        $decision = app(\App\Modules\LMS\Application\Services\CourseAccessService::class)->decide($advanced, $actor);

        $this->assertFalse($decision->allowed);
        $this->assertSame('course_prerequisite', $decision->reason);
        $this->assertSame((int) $intro->id, $decision->blockedByCourseId);

        // Setelah course prasyarat tuntas, akses dibuka.
        $introEnrollment = $this->enroll($intro, $user, ['status' => 'COMPLETED']);

        $this->assertNotNull($introEnrollment);

        $this->assertTrue(app(\App\Modules\LMS\Application\Services\CourseAccessService::class)->decide($advanced, $actor)->allowed);
    }

    // ------------------------------------------------------------ antrean nilai

    public function test_antrean_sinkronisasi_nilai_mencatat_riwayat_dan_idempoten(): void
    {
        $world = $this->world();
        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        $course = $this->makeCourse($world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];

        // Siswa yang sudah punya enrollment (sekaligus tautan student_id).
        $user = $world['studentUsers'][0];

        $item = $this->makeManualItem($gradebook, ['name' => 'Nilai Akhir', 'maximum_grade' => 100]);
        $this->recordGrade($item, (int) $user->id, 88);

        // Sinkronisasi hanya mengirim nilai yang sudah dirilis (§6): nilai yang
        // belum dirilis tidak boleh keluar dari LMS.
        app(\App\Modules\LMS\Application\Services\GradeReleaseService::class)
            ->releaseSelected($gradebook, [(int) $user->id], $actor);

        $queue = app(GradeSyncQueueService::class);

        $result = $queue->enqueue($gradebook, [(int) $user->id], $actor, dispatch: false);

        $this->assertSame(1, $result['queued']);

        $sync = LmsGradeSync::query()->where('gradebook_id', $gradebook->id)->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('PENDING', $sync->status->value);
        $this->assertNotNull($sync->idempotency_key);

        $processed = $queue->process($sync, $actor);

        $this->assertTrue($processed['processed']);
        $this->assertSame(1, $processed['attempt_no']);

        $history = $queue->history($sync->fresh());
        $this->assertCount(1, $history);
        $this->assertNotNull($history[0]['finished_at']);

        // Mengantrekan ulang kiriman dengan isi sama tidak menggandakan percobaan.
        $again = $queue->enqueue($gradebook, [(int) $user->id], $actor, dispatch: false);
        $this->assertSame(0, $again['queued']);

        $status = $queue->status($gradebook);
        $this->assertSame(1, $status['sync_id'] ?? $status['rows'][0]['sync_id']);
    }

    public function test_sinkronisasi_nilai_dapat_dibatalkan_dan_dibandingkan(): void
    {
        $world = $this->world();
        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        $course = $this->makeCourse($world);
        $scenario = $this->gradebookScenario($world, $course);
        $gradebook = $scenario['gradebook'];

        $user = $world['studentUsers'][0];

        $item = $this->makeManualItem($gradebook, ['name' => 'Nilai Akhir', 'maximum_grade' => 100]);
        $this->recordGrade($item, (int) $user->id, 70);

        app(\App\Modules\LMS\Application\Services\GradeReleaseService::class)
            ->releaseSelected($gradebook, [(int) $user->id], $actor);

        $queue = app(GradeSyncQueueService::class);
        $queue->enqueue($gradebook, [(int) $user->id], $actor, dispatch: false);

        $sync = LmsGradeSync::query()->where('gradebook_id', $gradebook->id)->firstOrFail();

        $cancelled = $queue->cancel($sync, $actor, 'Tidak jadi dikirim.');
        $this->assertSame('CANCELLED', $cancelled->status->value);

        $comparison = $queue->compare($gradebook, (int) $user->id);
        $this->assertSame((int) $user->id, $comparison['user_id']);
        $this->assertArrayHasKey('siakad', $comparison);
        $this->assertArrayHasKey('difference', $comparison);
    }

    // ------------------------------------------------------------- audit trail

    public function test_audit_trail_mencatat_aktor_ip_dan_korelasi_serta_immutable(): void
    {
        $world = $this->world();
        $teacher = $this->actingAsTeacher($world['teacher']);

        $service = app(LmsAuditService::class);
        $course = $this->makeCourse($world);
        $actor = $this->actorFor($teacher);

        $entry = $service->record(
            action: 'LMS_TEST_ACTION',
            entityType: LmsCourse::class,
            entityId: (int) $course->id,
            before: ['name' => 'Lama'],
            after: ['name' => 'Baru'],
            actor: $actor,
            courseId: (int) $course->id,
            context: ['sumber' => 'tes'],
        );

        $this->assertSame('LMS_TEST_ACTION', $entry->action);
        $this->assertSame($teacher->id, $entry->actor_id);
        $this->assertSame('Lama', $entry->before['name']);
        $this->assertNotNull($entry->correlation_id);

        $search = $service->search(['action' => 'LMS_TEST_ACTION']);
        $this->assertSame(1, $search['meta']['total']);

        $summary = $service->summary();
        $this->assertGreaterThanOrEqual(1, $summary['total']);

        $this->expectException(RuntimeException::class);
        $entry->forceFill(['action' => 'DIUBAH'])->save();
    }

    // ------------------------------------------------------------ report center

    public function test_report_center_menghasilkan_laporan_dan_berkas_csv(): void
    {
        Storage::fake('local');

        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $student = $world['students'][0];
        $this->enroll($course, $this->actingAsStudent($student), ['status' => 'ACTIVE']);

        $reports = app(LmsReportService::class);

        $this->assertNotEmpty($reports->catalog());

        $report = $reports->run(ReportKey::COURSE);

        $this->assertSame('COURSE', $report['key']);
        $this->assertNotEmpty($report['rows']);
        $this->assertContains('course', $report['columns']);

        $export = $reports->request(ReportKey::COURSE, ReportFormat::CSV, [], LmsSystemActor::make(), 'tes-export-1');

        $this->assertSame('READY', $export['status']);
        $this->assertGreaterThan(0, $export['rows_count']);

        $stored = app(LmsReportService::class)->contents(\App\Modules\LMS\Models\LmsReportExport::query()->findOrFail($export['id']));
        $this->assertStringContainsString('course', $stored);
    }

    // -------------------------------------------------------------- integritas

    public function test_pemeriksaan_integritas_bersih_pada_data_baru(): void
    {
        $world = $this->world();

        // Pemeriksaan integritas adalah wewenang tata kelola: kepala sekolah
        // (pemantau) melakukannya, bukan guru biasa (\u00a753).
        $kepsek = $this->actingAsRole('kepala-sekolah');

        $result = app(LmsIntegrityService::class)->run($this->actorFor($kepsek));

        $this->assertContains($result['status'], ['OK', 'WARNINGS']);
        $this->assertGreaterThan(0, $result['checks_run']);
        $this->assertNotEmpty(app(LmsIntegrityService::class)->history(1));

        $suggestion = app(LmsIntegrityService::class)->repairSuggestion('duplicate_enrollment');
        $this->assertTrue($suggestion['repairable']);
    }

    // ------------------------------------------------------------------ health

    public function test_health_check_dan_ringkasan_publik(): void
    {
        $health = app(SystemHealthService::class);

        $report = $health->check();

        $this->assertArrayHasKey('status', $report);
        $this->assertArrayHasKey('database', $report['checks']);
        $this->assertArrayHasKey('storage', $report['checks']);

        $public = $health->publicSummary();

        $this->assertArrayHasKey('status', $public);
        $this->assertArrayNotHasKey('checks', $public);
        $this->assertArrayNotHasKey('providers', $public);
    }

    // -------------------------------------------------------- export / import

    public function test_ekspor_impor_course_mengikuti_tahapan_dan_idempoten(): void
    {
        Storage::fake('local');

        $world = $this->world();
        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        $course = $this->makeCourse($world, ['name' => 'Course Sumber', 'code' => 'SRC-1']);
        $section = $this->makeSection($course, ['title' => 'Bagian 1']);
        $this->makePageItem($section, ['title' => 'Materi 1']);
        $this->publishActivity($this->makeActivity($section, ['title' => 'Tugas 1']));
        $this->makeQuestion([
            'question_type' => 'ESSAY',
            'question_text' => 'Jelaskan konsep dasar.',
            'options' => [],
        ], $course);

        $export = app(CourseExportService::class);
        $payload = $export->export($course, $actor);

        $this->assertSame(CourseExportService::FORMAT, $payload['format']);
        $this->assertArrayNotHasKey('quiz_password', $payload);
        $this->assertSame('not_exported', $payload['course']['teacher_assignment']);

        $summary = $export->summary($payload);
        $this->assertGreaterThan(0, $summary['counts']['items']);

        $imports = app(CourseImportService::class);
        $validation = $imports->validate((string) json_encode($payload));

        $this->assertTrue($validation['valid'], implode('; ', $validation['errors']));
        $this->assertNotEmpty($validation['preview']['sections']);
        $this->assertNotEmpty($validation['conflicts'], 'Kode course sumber masih ada, sehingga konflik terdeteksi.');

        $result = $imports->import(
            payload: $payload,
            actor: $actor,
            strategy: ImportConflictStrategy::DUPLICATE,
            idempotencyKey: 'import-tes-1',
        );

        $this->assertSame('VERIFIED', $result['verification']['status']);

        $imported = LmsCourse::query()->findOrFail($result['course_id']);
        $this->assertNotSame($course->code, $imported->code);
        $this->assertSame(1, $imported->sections()->count());
        // Berkas memuat satu materi dan satu aktivitas → dua item tersalin.
        $this->assertSame(2, $imported->items()->count());

        // Idempotensi: kunci sama tidak membuat course kedua.
        $repeat = $imports->import($payload, $actor, ImportConflictStrategy::DUPLICATE, null, 'import-tes-1');
        $this->assertTrue($repeat['duplicate']);
        $this->assertSame(2, LmsCourse::query()->where('name', 'Course Sumber')->count(), 'Impor idempotent tidak boleh membuat course kedua.');
    }

    public function test_backup_dan_pemulihan_course(): void
    {
        Storage::fake('local');

        $world = $this->world();
        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        $course = $this->makeCourse($world, ['name' => 'Course Backup', 'code' => 'BKP-1']);
        $section = $this->makeSection($course);
        $this->makePageItem($section, ['title' => 'Materi backup']);

        $backups = app(CourseBackupService::class);
        $backup = $backups->backup($course, $actor, 'sebelum revisi');

        $this->assertTrue(Storage::disk($backup['disk'])->exists($backup['path']));

        $verification = $backups->verify($backup['path']);
        $this->assertTrue($verification['valid']);

        $restore = $backups->restore($backup['path'], $actor);

        $this->assertTrue($restore['restored']);
        $restored = LmsCourse::query()->findOrFail($restore['course_id']);
        $this->assertSame(1, $restored->items()->count());
    }

    // ------------------------------------------------------------------- SCORM

    public function test_scorm_diimpor_dipasang_dan_dilacak(): void
    {
        Storage::fake('local');

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Ekstensi zip tidak tersedia.');
        }

        $world = $this->world();
        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        $course = $this->makeCourse($world);
        $section = $this->makeSection($course);

        $package = $this->scormPackage();

        $import = app(ScormPackageService::class)->import($package, $actor, 'Paket latihan');

        $this->assertTrue($import['validation']['valid'], implode('; ', $import['validation']['errors']));
        $this->assertSame(ScormPackageStatus::IMPORTED, $import['package']->status);

        $activity = app(ScormPackageService::class)->createActivity($import['package'], $course, $section, $actor);

        $student = $world['students'][0];
        $user = $this->actingAsStudent($student);
        $enrollment = $this->enroll($course, $user, ['status' => 'ACTIVE']);

        $tracking = app(ScormTrackingService::class);
        $launch = $tracking->launch($activity, $enrollment);

        $this->assertSame(0, (int) $launch['attempt']->total_time_seconds);

        $commit = $tracking->commit($launch['attempt'], [
            'cmi.core.lesson_status' => 'completed',
            'cmi.core.score.raw' => '85',
            'cmi.core.score.min' => '0',
            'cmi.core.score.max' => '100',
            'cmi.core.session_time' => '00:05:30',
        ], ['session_time' => '00:05:30', 'idempotency_key' => 'scorm-commit-1']);

        $this->assertSame('COMPLETED', $commit['attempt']['status']);
        $this->assertTrue($commit['attempt']['completed']);
        $this->assertSame(85.0, $commit['attempt']['score']['normalized']);
        $this->assertSame(330, $commit['attempt']['total_time_seconds']);
        $this->assertTrue($commit['effects']['applied']);
        $this->assertTrue($commit['effects']['completed']);

        // Commit ulang dengan kunci sama tidak menggandakan jejak pelacakan.
        $again = $tracking->commit(
            LmsScormAttempt::query()->findOrFail($launch['attempt']->id),
            ['cmi.core.score.raw' => '85'],
            ['idempotency_key' => 'scorm-commit-1'],
        );

        $this->assertSame(0, $again['events_recorded']);
    }

    // ---------------------------------------------------------------- gateway

    public function test_gateway_menegakkan_kebijakan_keuangan_dan_idempotensi_notifikasi(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $gateway = app(LmsGateway::class);

        $this->assertSame('integrated', $gateway->mode());
        $this->assertTrue($gateway->financialDecision((int) $student->id, 'view')['allowed']);

        // Kebijakan pembatasan: hanya kemampuan yang tercantum yang ditahan.
        config()->set('lms.finance.enabled', true);
        config()->set('lms.finance.allow_learning_when_restricted', false);
        config()->set('lms.finance.restricted_capabilities', ['enroll_new_course']);

        $provider = new class implements \App\Modules\LMS\Domain\Contracts\FinancialEligibilityProviderInterface
        {
            public function key(): string
            {
                return 'test';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function eligibilityFor(int $studentId): array
            {
                return [
                    'status' => 'RESTRICTED',
                    'outstanding_amount' => 150000.0,
                    'overdue_count' => 2,
                    'reason' => 'Ada tagihan lewat jatuh tempo.',
                    'checked_at' => now()->toIso8601String(),
                    'source' => 'test',
                ];
            }

            public function restrictedStudentIds(int $limit = 100): array
            {
                return [];
            }
        };

        $this->app->instance(\App\Modules\LMS\Domain\Contracts\FinancialEligibilityProviderInterface::class, $provider);

        // Gateway adalah singleton: instance lama harus dibuang agar provider
        // pengganti benar-benar dipakai.
        $this->app->forgetInstance(LmsGateway::class);

        $scoped = app(LmsGateway::class);

        $this->assertFalse($scoped->financialDecision((int) $student->id, 'enroll_new_course')['allowed']);
        $this->assertTrue($scoped->financialDecision((int) $student->id, 'submit')['allowed'], 'Mengerjakan dan mengumpulkan tugas tidak pernah ditahan.');

        // Notifikasi idempotent: kunci yang sama tidak dikirim dua kali.
        $recipient = $this->actingAsTeacher($world['teacher']);

        $first = $scoped->notify([$recipient->id], 'ACTIVITY_DUE_SOON', ['title' => 'Tes', 'body' => 'Isi'], ['in_app'], 'notif-tes-1');
        $second = $scoped->notify([$recipient->id], 'ACTIVITY_DUE_SOON', ['title' => 'Tes', 'body' => 'Isi'], ['in_app'], 'notif-tes-1');

        $this->assertGreaterThanOrEqual(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertSame(1, $second['skipped']);
    }

    // ------------------------------------------------------------ penyimpanan

    public function test_penyimpanan_menolak_path_traversal_dan_menjaga_visibilitas(): void
    {
        Storage::fake('local');

        $files = app(\App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface::class);

        $stored = $files->put('lms/tes', '../../.env', 'RAHASIA', 'PRIVATE');

        $this->assertStringNotContainsString('..', $stored['path']);
        $this->assertStringContainsString('private', $stored['path']);
        $this->assertTrue($files->exists($stored['path']));
    }

    // -------------------------------------------------------- HTTP (fase baru)

    public function test_endpoint_fase_4_menegakkan_otorisasi(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $user = $this->actingAsStudent($student);

        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $user, ['status' => 'ACTIVE']);

        // Rekap kesehatan course hanya untuk pengelola.
        $this->getJson("/api/v1/lms/courses/{$course->id}/health")->assertForbidden();

        // Guru dapat membuka daftar badge (izin lms.badge.view).
        $this->actingAsTeacher($world['teacher']);
        $this->getJson('/api/v1/lms/badges')->assertOk();
    }

    public function test_verifikasi_sertifikat_publik_tanpa_login(): void
    {
        $response = $this->getJson('/api/v1/lms/public/certificates/verify?query=TEST-TOKEN');

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_endpoint_antrean_nilai_dan_laporan_dapat_diakses_pengelola(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);

        $course = $this->makeCourse($world);
        $scenario = $this->gradebookScenario($world, $course);

        $this->getJson("/api/v1/lms/gradebooks/{$scenario['gradebook']->id}/sync")->assertOk();

        $this->getJson('/api/v1/lms/reports')->assertOk();
        $this->getJson('/api/v1/lms/reports/COURSE')->assertOk();

        // Status integrasi & kesehatan sistem adalah wewenang pemantau.
        $this->actingAsRole('kepala-sekolah');

        $this->getJson('/api/v1/lms/integration')->assertOk();
        $this->getJson('/api/v1/lms/integrity')->assertOk();
        $this->getJson('/api/v1/lms/audit')->assertOk();
    }

    /** Paket SCORM minimum yang sah (1.2) untuk pengujian impor. */
    private function scormPackage(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'scorm').'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('imsmanifest.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="PKG-1" version="1.2">
  <metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata>
  <organizations default="ORG-1">
    <organization identifier="ORG-1">
      <title>Latihan SCORM</title>
      <item identifier="ITEM-1" identifierref="RES-1"><title>Materi SCORM</title></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="RES-1" type="webcontent" adlcp:scormtype="sco" href="index.html">
      <file href="index.html"/>
    </resource>
  </resources>
</manifest>
XML);
        $zip->addFromString('index.html', '<html><body>SCORM</body></html>');
        $zip->close();

        return new UploadedFile($path, 'latihan.zip', 'application/zip', null, true);
    }
}
