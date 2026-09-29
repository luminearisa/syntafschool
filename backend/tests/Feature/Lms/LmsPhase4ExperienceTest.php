<?php

namespace Tests\Feature\Lms;

use App\Models\User;
use App\Modules\LMS\Application\Services\CourseArchiveService;
use App\Modules\LMS\Application\Services\CourseCopyService;
use App\Modules\LMS\Application\Services\CourseReviewService;
use App\Modules\LMS\Application\Services\CourseTemplateService;
use App\Modules\LMS\Application\Services\LearningHealthService;
use App\Modules\LMS\Application\Services\LmsMessagingService;
use App\Modules\LMS\Domain\Enums\LearningHealthStatus;
use App\Modules\LMS\Domain\Enums\ReviewStatus;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseCopy;
use App\Modules\LMS\Models\LmsCourseReview;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 4 — ulasan, kesehatan belajar, template, salin, dan arsip course
 * (§31–§32, §46–§51, §58–§61).
 */
class LmsPhase4ExperienceTest extends LmsTestCase
{
    // ==================================================================
    // Ulasan & umpan balik (§31–§32)
    // ==================================================================

    public function test_ulasan_menunggu_moderasi_lalu_memperbarui_cache_rating(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $enrollment = $this->enroll($course, $world['studentUsers'][0], ['status' => 'COMPLETED', 'completed_at' => now()]);

        $actor = $this->actorFor($world['studentUsers'][0]);
        $reviews = app(CourseReviewService::class);

        $review = $reviews->submit($course, $actor, ['rating' => 5, 'review' => 'Materinya jelas']);

        $this->assertSame(ReviewStatus::PENDING, $review->status, 'Default menunggu moderasi (§31).');
        $this->assertTrue($review->is_verified_completion, 'Ulasan dari peserta yang tuntas ditandai terverifikasi.');

        // Belum disetujui → belum tampil & cache rating masih kosong.
        $this->assertSame(0, $reviews->summary($course)['count']);
        $this->assertSame([], $reviews->publicFor($course)['reviews']);

        $moderator = $this->actorFor($this->actingAsRole('admin-sekolah'));
        $reviews->moderate($review, $moderator, ReviewStatus::APPROVED->value);

        $summary = $reviews->summary($course);
        $this->assertSame(1, $summary['count']);
        $this->assertSame(5.0, $summary['average']);
        $this->assertSame(1, $summary['distribution'][5], 'Satu ulasan bintang 5 tercatat pada distribusi.');
        $this->assertSame(5.0, (float) $course->fresh()->rating_average);
        $this->assertSame(1, (int) $course->fresh()->rating_count);

        // Kirim ulang = memperbarui ulasan sendiri, bukan menambah baris baru.
        $reviews->submit($course, $actor, ['rating' => 3, 'review' => 'Revisi pendapat']);

        $this->assertSame(1, LmsCourseReview::query()->count());
        $this->assertSame(ReviewStatus::PENDING, LmsCourseReview::query()->firstOrFail()->status, 'Perubahan isi kembali ke antrean moderasi.');
        $this->assertSame(0, $reviews->summary($course->fresh())['count']);
    }

    public function test_ulasan_menolak_rating_tidak_valid_dan_penulis_bukan_peserta(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $reviews = app(CourseReviewService::class);

        // Bukan peserta → 403.
        try {
            $reviews->submit($course, $this->actorFor($world['studentUsers'][0]), ['rating' => 5]);
            $this->fail('Pengguna non-peserta seharusnya ditolak.');
        } catch (HttpException|HttpResponseException $e) {
            $this->assertTrue(true);
        }

        $this->enroll($course, $world['studentUsers'][0]);
        $actor = $this->actorFor($world['studentUsers'][0]);

        $this->expectException(ValidationException::class);

        $reviews->submit($course, $actor, ['rating' => 6]);
    }

    public function test_umpan_balik_kualitatif_direkap_terpisah_dari_rating(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $feedback = app(CourseReviewService::class)->submitFeedback($course, $this->actorFor($world['studentUsers'][0]), [
            'liked' => 'Penjelasan video mudah diikuti',
            'improve' => 'Perbanyak latihan soal',
            'rating' => 4,
        ]);

        $this->assertSame('SUBMITTED', $feedback->status);
        $this->assertNotNull($feedback->submitted_at);

        $rows = app(CourseReviewService::class)->feedbackForCourse($course);
        $this->assertCount(1, $rows);
        $this->assertSame('Perbanyak latihan soal', $rows[0]['improve']);

        $summary = app(CourseReviewService::class)->feedbackSummary($course);
        $this->assertSame(1, $summary['total']);
        $this->assertSame(4.0, $summary['average_rating']);
    }

    // ==================================================================
    // Kesehatan belajar (§58–§61)
    // ==================================================================

    public function test_kesehatan_belajar_menandai_selesai_tidak_aktif_dan_berisiko(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $finished = $this->enroll($course, $world['studentUsers'][0], ['status' => 'COMPLETED', 'completed_at' => now(), 'progress_percent' => 100]);
        $inactive = $this->enroll($course, $world['studentUsers'][1], ['progress_percent' => 20, 'last_event_at' => now()->subDays(30)]);
        $atRisk = $this->enroll($course, $world['studentUsers'][2], ['progress_percent' => 10, 'last_event_at' => now()->subDay()]);

        $health = app(LearningHealthService::class);

        $this->assertSame(LearningHealthStatus::COMPLETED, $health->statusFor($finished));
        $this->assertSame(LearningHealthStatus::INACTIVE, $health->statusFor($inactive));
        $this->assertSame(LearningHealthStatus::AT_RISK, $health->statusFor($atRisk), 'Progress rendah menandai perlu perhatian.');

        // Status disimpan sebagai cache pada enrollment.
        $health->computeForEnrollment($inactive);

        $this->assertSame(LearningHealthStatus::INACTIVE, $inactive->fresh()->health_status);
        $this->assertNotNull($inactive->fresh()->health_computed_at);
    }

    public function test_aktivitas_terlambat_menaikkan_status_berisiko(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();

        foreach ([1, 2] as $index) {
            $activity = $this->makeActivity($section, ['title' => 'Tugas Terlambat '.$index]);
            $activity->forceFill([
                'status' => 'PUBLISHED',
                'published_at' => now()->subDays(10),
                'due_at' => now()->subDays(3),
            ])->save();
        }

        config(['lms.engagement.health.low_completion_percent' => 0]);

        $enrollment = $this->enroll($course, $world['studentUsers'][0], ['progress_percent' => 0, 'last_event_at' => now()->subHour()]);

        $health = app(LearningHealthService::class);
        $metrics = $health->metricsFor($enrollment);

        $this->assertSame(2, $metrics['overdue_activities']);
        $this->assertSame(LearningHealthStatus::AT_RISK, $health->statusFor($enrollment, $metrics));

        // Setelah tugas pertama selesai, jumlah terlambat turun.
        LmsActivityCompletion::query()->create([
            'enrollment_id' => $enrollment->id,
            'learning_item_id' => $section->items()->where('item_type', 'ACTIVITY')->firstOrFail()->id,
            'course_id' => $course->id,
            'user_id' => $enrollment->user_id,
            'completion_type' => 'MANUAL',
            'status' => 'COMPLETE',
            'completed_at' => now(),
        ]);

        $this->assertSame(1, $health->metricsFor($enrollment->fresh())['overdue_activities']);
    }

    public function test_rekap_kesehatan_course_dan_daftar_perlu_perhatian(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $this->enroll($course, $world['studentUsers'][0], ['status' => 'COMPLETED', 'completed_at' => now(), 'progress_percent' => 100]);
        $this->enroll($course, $world['studentUsers'][1], ['progress_percent' => 80, 'last_event_at' => now()]);
        $this->enroll($course, $world['studentUsers'][2], ['progress_percent' => 5, 'last_event_at' => now()->subDays(40)]);

        $health = app(LearningHealthService::class);

        $rekap = $health->computeForCourse($course);

        $this->assertSame(3, $rekap['total']);
        $this->assertSame(1, $rekap['counts'][LearningHealthStatus::COMPLETED->value]);
        $this->assertSame(1, $rekap['counts'][LearningHealthStatus::ON_TRACK->value]);
        $this->assertSame(1, $rekap['counts'][LearningHealthStatus::INACTIVE->value]);

        $summary = $health->summaryForCourse($course);

        $this->assertSame(1, $summary['attention_total']);
        $this->assertSame($world['studentUsers'][2]->id, $summary['attention'][0]['user_id']);
    }

    // ==================================================================
    // Template course (§47–§48)
    // ==================================================================

    public function test_struktur_course_dapat_disimpan_sebagai_template_dan_diterapkan(): void
    {
        $world = $this->world();
        $source = $this->publishCourse($this->makeCourse($world), $world);
        $source->sections->first()->items()->first()->resource()->update(['content' => '<p>Aljabar dasar</p>']);

        $actor = $this->actorFor($this->actingAsRole('guru'));
        $templates = app(CourseTemplateService::class);

        $template = $templates->createFromCourse($source, ['title' => 'Template Aljabar'], $actor);

        $this->assertSame(1, (int) $template->sections_count);
        $this->assertSame(1, (int) $template->items_count);
        $this->assertSame((int) $source->id, (int) $template->source_course_id);

        $preview = $templates->preview($template);
        $this->assertSame('Pengenalan Aljabar', $preview['sections'][0]['title']);
        $this->assertTrue($preview['sections'][0]['items'][0]['has_resource']);

        $target = $this->makeCourse($world, ['name' => 'Kelas Baru', 'slug' => 'kelas-baru-'.uniqid()]);

        $stats = $templates->apply($template, $target, [], $actor);

        $this->assertSame(1, $stats['sections']);
        $this->assertSame(1, $stats['items']);
        $this->assertSame(1, $stats['resources']);

        $target = $target->fresh();
        $this->assertCount(1, $target->sections);
        $this->assertStringContainsString('Aljabar dasar', (string) $target->sections->first()->items->first()->resource->content);

        // Tanpa data siswa: template tidak membawa peserta.
        $this->assertSame(0, $target->enrollments()->count());
    }

    public function test_template_tidak_dapat_menghapus_struktur_course_yang_sudah_punya_peserta(): void
    {
        $world = $this->world();
        $source = $this->publishCourse($this->makeCourse($world), $world);
        $actor = $this->actorFor($this->actingAsRole('guru'));

        $template = app(CourseTemplateService::class)->createFromCourse($source, ['title' => 'Template'], $actor);

        $target = $this->publishCourse($this->makeCourse($world, ['name' => 'Course Berjalan', 'slug' => 'berjalan-'.uniqid()]), $world);
        $this->enroll($target, $world['studentUsers'][0]);

        $this->expectException(ValidationException::class);

        app(CourseTemplateService::class)->apply($template, $target, ['mode' => 'REPLACE'], $actor);
    }

    // ==================================================================
    // Salin konten (§46, §50–§51)
    // ==================================================================

    public function test_salin_konten_tidak_membawa_peserta_nilai_atau_jejak_belajar(): void
    {
        $world = $this->world();
        $source = $this->publishCourse($this->makeCourse($world), $world);
        $sourceItem = $source->sections->first()->items->first();
        $sourceItem->resource()->update(['content' => '<p>Materi sumber</p>']);

        $target = $this->makeCourse($world, ['name' => 'Course Salinan', 'slug' => 'salinan-'.uniqid()]);

        $actor = $this->actorFor($this->actingAsRole('guru'));

        $result = app(CourseCopyService::class)->copyContent($source, $target, $actor);

        $this->assertSame('COMPLETED', $result['copy']->status);
        $this->assertSame(1, $result['stats']['sections']);
        $this->assertSame(1, $result['stats']['items']);

        $target = $target->fresh();
        $this->assertCount(1, $target->sections);
        $this->assertSame(0, $target->enrollments()->count(), 'Peserta tidak pernah ikut tersalin.');
        $this->assertSame(0, LmsActivityCompletion::query()->where('course_id', $target->id)->count());

        $history = app(CourseCopyService::class)->history($source);
        $this->assertCount(1, $history);
        $this->assertSame((int) $target->id, $history[0]['target_course_id']);
        $this->assertSame('CONTENT', $history[0]['mode']);
    }

    public function test_salin_menolak_course_sumber_dan_tujuan_yang_sama(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $actor = $this->actorFor($this->actingAsRole('guru'));

        $this->expectException(ValidationException::class);

        app(CourseCopyService::class)->copyContent($course, $course, $actor);
    }

    public function test_course_baru_dapat_dibangun_langsung_dari_template(): void
    {
        $world = $this->world();
        $source = $this->publishCourse($this->makeCourse($world), $world);
        $actor = $this->actorFor($this->actingAsRole('guru'));

        $template = app(CourseTemplateService::class)->createFromCourse($source, ['title' => 'Template Semester'], $actor);

        $result = app(CourseCopyService::class)->createCourseFromTemplate($template, [
            'name' => 'Matematika Semester Ganjil',
            'code' => 'MAT-GANJIL-'.strtoupper(uniqid()),
        ], $actor);

        /** @var LmsCourse $course */
        $course = $result['course'];

        $this->assertSame('DRAFT', $course->status->value, 'Course dari template selalu DRAFT — guru memeriksa dulu.');
        $this->assertCount(1, $course->sections);
        $this->assertSame('TEMPLATE', LmsCourseCopy::query()->where('target_course_id', $course->id)->value('mode'));
    }

    // ==================================================================
    // Arsip course (§49–§51)
    // ==================================================================

    public function test_arsip_menjadikan_course_read_only_dan_dapat_dipulihkan(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0], ['status' => 'COMPLETED', 'completed_at' => now(), 'progress_percent' => 100]);

        $actor = $this->actorFor($this->actingAsRole('admin-sekolah'));
        $archives = app(CourseArchiveService::class);

        // Jejak belajar nyata sebelum diarsipkan.
        LmsActivityCompletion::query()->create([
            'enrollment_id' => LmsEnrollment::query()->where('course_id', $course->id)->value('id'),
            'learning_item_id' => $course->sections->first()->items->first()->id,
            'course_id' => $course->id,
            'user_id' => $world['studentUsers'][0]->id,
            'completion_type' => 'MANUAL',
            'status' => 'COMPLETE',
            'completed_at' => now(),
        ]);

        $archive = $archives->archive($course, 'Tahun ajaran berganti', $actor);

        $course = $course->fresh();
        $this->assertTrue($course->isReadOnly(), 'Course terarsip bersifat read-only (§49).');
        $this->assertNotNull($course->archived_at);
        $this->assertSame(1, (int) $archive->students_count);
        $this->assertSame('PUBLISHED', $archive->snapshot['previous_status']);
        $this->assertSame(0, (int) $archive->snapshot['certificates_count'], 'Snapshot mencatat jumlah sertifikat saat arsip dibuat.');
        $this->assertTrue($archive->isActive());

        // Riwayat belajar tidak terhapus oleh arsip.
        $this->assertSame(1, LmsEnrollment::query()->where('course_id', $course->id)->count());
        $this->assertSame(1, LmsActivityCompletion::query()->where('course_id', $course->id)->count());

        $restored = $archives->restore($archive->fresh(), $actor);

        $this->assertSame('PUBLISHED', $restored->status->value, 'Status kembali seperti sebelum diarsipkan (§50).');
        $this->assertNull($restored->archived_at);
        $this->assertFalse($archive->fresh()->isActive());
    }

    public function test_arsip_lama_yang_sudah_dipulihkan_dapat_dibersihkan_tanpa_menghapus_course(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $actor = $this->actorFor($this->actingAsRole('admin-sekolah'));

        $archives = app(CourseArchiveService::class);
        $archive = $archives->archive($course, 'Uji bersih-bersih', $actor);
        $archives->restore($archive, $actor);

        DB::table('lms_course_archives')->where('id', $archive->id)->update(['restored_at' => now()->subDays(400)]);

        $this->assertSame(1, $archives->prune(30, $actor));
        $this->assertDatabaseHas('lms_courses', ['id' => $course->id]);
        $this->assertNotNull($course->fresh()->published_at, 'Data course tidak tersentuh oleh pembersihan arsip (§51).');
    }

    public function test_template_dan_salin_tidak_menyalin_item_yang_di_soft_delete(): void
    {
        $world = $this->world();
        $source = $this->publishCourse($this->makeCourse($world), $world);
        $section = $source->sections->first();

        /** @var LmsLearningItem $removed */
        $removed = $this->makePageItem($section, ['title' => 'Materi Dihapus', 'sort_order' => 2]);
        $removed->delete();

        $actor = $this->actorFor($this->actingAsRole('guru'));
        $target = $this->makeCourse($world, ['name' => 'Course Bersih', 'slug' => 'bersih-'.uniqid()]);

        app(CourseCopyService::class)->copyContent($source, $target, $actor);

        $this->assertSame(1, $target->fresh()->sections->first()->items->count(), 'Item yang dihapus tidak ikut disalin.');
        $this->assertSame(0, LmsLearningItem::query()->where('course_id', $target->id)->where('title', 'Materi Dihapus')->count());
    }

    // ==================================================================
    // Pesan & sebutan (§33–§35)
    // ==================================================================

    public function test_guru_dapat_mengirim_pesan_ke_seluruh_peserta_course(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);
        $this->enroll($course, $world['studentUsers'][1]);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $messaging = app(LmsMessagingService::class);

        $result = $messaging->broadcastToCourse($course, $teacher, 'Jangan lupa tugas dikumpulkan besok.', [
            'subject' => 'Pengingat tugas',
            'mentions' => [(int) $world['studentUsers'][0]->id],
        ]);

        $this->assertSame(2, $result['sent']);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $world['studentUsers'][1]->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_siswa_tidak_dapat_mengirim_pesan_langsung(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);
        $this->enroll($course, $world['studentUsers'][1]);

        $student = $this->actorFor($world['studentUsers'][0]);

        $this->expectException(HttpException::class);

        app(LmsMessagingService::class)->sendToStudents(
            $course,
            $student,
            [(int) $world['studentUsers'][1]->id],
            'Halo',
        );
    }

    public function test_pesan_ke_wali_mengikuti_data_wali_yang_ada(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $teacher = $this->actorFor($this->actingAsRole('guru'));
        $messaging = app(LmsMessagingService::class);

        // Tanpa data wali, pengiriman tidak gagal — hanya tidak ada penerima.
        $result = $messaging->sendToGuardians($course, $teacher, (int) $world['students'][0]->id, 'Mohon konfirmasi kehadiran.');

        $this->assertSame(0, $result['sent']);
        $this->assertSame((int) $world['students'][0]->id, $result['student_id']);
        $this->assertSame([], $messaging->guardianUserIdsFor(0));
    }
}
