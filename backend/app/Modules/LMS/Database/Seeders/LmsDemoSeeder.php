<?php

namespace App\Modules\LMS\Database\Seeders;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Modules\LMS\Application\Services\CourseContentService;
use App\Modules\LMS\Application\Services\CourseEnrollmentService;
use App\Modules\LMS\Application\Services\LmsCategoryService;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Models\LmsCategory;
use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Data contoh LMS (opsional).
 *
 * Dipakai untuk demo/uji manual: membuat pohon kategori, satu course umum
 * publik dengan beragam resource, dan satu course akademik yang pesertanya
 * disinkronkan dari rombel SIAKAD — memperlihatkan alur §6/§17 tanpa mengetik
 * siswa satu per satu.
 *
 * Aman dijalankan berulang: seluruh pembuatan diawali pemeriksaan keberadaan.
 */
class LmsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->orderBy('id')->first();

        if ($admin === null) {
            $this->command?->warn('LMS demo dilewati: belum ada akun pengguna.');

            return;
        }

        $actor = app(UserProviderInterface::class)->find((int) $admin->id);

        if ($actor === null) {
            return;
        }

        $categories = app(LmsCategoryService::class);
        $root = LmsCategory::query()->firstOrCreate(
            ['slug' => 'teknologi'],
            ['name' => 'Teknologi', 'status' => 'ACTIVE', 'visibility' => 'PUBLIC', 'sort_order' => 1],
        );

        $child = LmsCategory::query()->firstOrCreate(
            ['slug' => 'literasi-digital'],
            [
                'name' => 'Literasi Digital',
                'parent_id' => $root->id,
                'depth' => 1,
                'status' => 'ACTIVE',
                'visibility' => 'PUBLIC',
                'sort_order' => 1,
            ],
        );

        // --- Course umum publik dengan beragam jenis resource (§13) ---------
        $general = LmsCourse::query()->firstOrCreate(
            ['code' => 'LMS-LITDIG-2026'],
            [
                'category_id' => $child->id,
                'name' => 'Literasi Digital untuk Pelajar',
                'slug' => 'literasi-digital-untuk-pelajar',
                'short_description' => 'Kursus terbuka: etika digital, keamanan data, dan produktivitas belajar.',
                'description' => '<h2>Selamat datang</h2><p>Kursus ini memperkenalkan cara belajar aman dan produktif di dunia digital.</p>',
                'learning_objectives' => '<ul><li>Mengenali jejak digital</li><li>Menjaga keamanan akun</li><li>Memanfaatkan alat belajar</li></ul>',
                'course_type' => 'GENERAL',
                'level' => 'BEGINNER',
                'language' => 'id',
                'status' => CourseStatus::PUBLISHED->value,
                'visibility' => CourseVisibility::PUBLIC->value,
                'enrollment_method' => 'SELF',
                'is_featured' => true,
                'published_at' => now(),
                'created_by' => $actor->id,
            ],
        );

        if ($general->sections()->count() === 0) {
            $content = app(CourseContentService::class);
            $section = $content->createSection($general, [
                'title' => 'Dasar Literasi Digital',
                'summary' => 'Konsep jejak digital dan keamanan akun.',
            ]);

            $content->createItem($section, [
                'title' => 'Mengenal Jejak Digital',
                'item_type' => 'RESOURCE',
                'resource_type' => 'PAGE',
                'completion_type' => 'VIEW',
                'is_preview' => true,
                'estimated_minutes' => 10,
                'content' => '<h3>Jejak digital</h3><p>Setiap interaksi daring meninggalkan jejak yang dapat bertahan lama.</p><blockquote data-callout="info">Periksa pengaturan privasi akun Anda minimal dua kali setahun.</blockquote>',
            ], $actor);

            $content->createItem($section, [
                'title' => 'Video: Keamanan Kata Sandi',
                'item_type' => 'RESOURCE',
                'resource_type' => 'VIDEO',
                'completion_type' => 'VIEW',
                'estimated_minutes' => 8,
                'external_url' => 'https://www.youtube.com/watch?v=aQ9XjT4FvLg',
                'open_in' => 'EMBED',
            ], $actor);

            $content->createItem($section, [
                'title' => 'Panduan Belajar (PDF)',
                'item_type' => 'RESOURCE',
                'resource_type' => 'URL',
                'completion_type' => 'VIEW',
                'external_url' => 'https://www.kominfo.go.id/',
            ], $actor);

            $book = $content->createItem($section, [
                'title' => 'Buku Saku: Etika Digital',
                'item_type' => 'RESOURCE',
                'resource_type' => 'BOOK',
                'completion_type' => 'VIEW',
                'chapters' => [
                    ['title' => 'Bab 1 — Etika berkomunikasi', 'content' => '<p>Berkomunikasi sopan dan menghargai privasi orang lain.</p>'],
                    ['title' => 'Bab 2 — Menghindari hoaks', 'content' => '<p>Periksa sumber sebelum menyebarkan informasi.</p>'],
                ],
            ], $actor);

            $content->createItem($section, [
                'title' => 'Tugas: Rencana Belajar Digital',
                'item_type' => 'ACTIVITY',
                'activity_type' => 'ASSIGNMENT',
                'completion_type' => 'SUBMIT',
                'description' => '<p>Susun rencana belajar daring Anda selama satu minggu.</p>',
                'estimated_minutes' => 30,
            ], $actor);

            $content->createSection($general, [
                'title' => 'Praktik Produktif',
                'summary' => 'Alat bantu belajar dan manajemen waktu.',
            ]);

            unset($book);
        }

        // --- Course akademik yang tersinkron dari SIAKAD (§6, §17) ----------
        $class = SchoolClass::query()->with('academicYear')->first();
        $subject = Subject::query()->first();
        $year = AcademicYear::query()->where('is_active', true)->first();

        if ($class !== null && $subject !== null) {
            $academic = LmsCourse::query()->firstOrCreate(
                ['code' => 'LMS-'.$subject->code.'-'.$class->code],
                [
                    'category_id' => $root->id,
                    'academic_course_id' => $subject->id,
                    'academic_year_id' => $year?->id ?? $class->academic_year_id,
                    'class_id' => $class->id,
                    'name' => $subject->name.' — '.$class->name,
                    'slug' => 'lms-'.strtolower($subject->code).'-'.strtolower($class->code),
                    'short_description' => 'Course LMS yang terhubung ke rombel '.$class->name.' pada SIAKAD.',
                    'course_type' => 'ACADEMIC',
                    'level' => 'INTERMEDIATE',
                    'status' => CourseStatus::DRAFT->value,
                    'visibility' => CourseVisibility::ENROLLED_ONLY->value,
                    'enrollment_method' => 'ACADEMIC',
                    'created_by' => $actor->id,
                ],
            );

            if ($academic->sections()->count() === 0) {
                $content = app(CourseContentService::class);
                $section = $content->createSection($academic, ['title' => 'Pertemuan 1', 'summary' => 'Pengantar materi.']);

                $content->createItem($section, [
                    'title' => 'Pengantar Materi',
                    'item_type' => 'RESOURCE',
                    'resource_type' => 'PAGE',
                    'completion_type' => 'VIEW',
                    'content' => '<p>Materi pembuka sebelum pertemuan tatap muka.</p>',
                ], $actor);

                $content->createItem($section, [
                    'title' => 'Latihan Mandiri',
                    'item_type' => 'ACTIVITY',
                    'activity_type' => 'ASSIGNMENT',
                    'completion_type' => 'SUBMIT',
                    'description' => '<p>Kerjakan soal latihan pada buku paket halaman 12.</p>',
                ], $actor);
            }

            try {
                $result = app(CourseEnrollmentService::class)->syncAcademicEnrollments($academic, $actor);
                $this->command?->info(sprintf(
                    'Course akademik "%s": %d siswa tersinkron dari rombel %s.',
                    $academic->name,
                    $result['created'],
                    $class->name,
                ));
            } catch (Throwable $exception) {
                $this->command?->warn('Sinkronisasi contoh dilewati: '.$exception->getMessage());
            }
        }

        $this->command?->info('Data contoh LMS siap: kategori, course publik, dan course akademik.');
    }
}
