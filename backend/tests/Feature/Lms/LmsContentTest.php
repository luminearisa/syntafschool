<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** §44 Content: page, file, URL, video, book. */
class LmsContentTest extends LmsTestCase
{
    public function test_guru_dapat_membuat_materi_halaman_yang_disimpan_bersih(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $course = $this->makeCourse($world);
        $section = $this->makeSection($course);

        $response = $this->postJson("/api/v1/lms/sections/{$section->id}/items", [
            'title' => 'Pengenalan Aljabar',
            'item_type' => 'RESOURCE',
            'resource_type' => 'PAGE',
            'completion_type' => 'VIEW',
            'content' => '<h2>Aljabar</h2><p>Aman</p><script>alert(1)</script><img src=x onerror=alert(2)>',
        ])->assertCreated();

        $item = LmsLearningItem::query()->findOrFail($response->json('data.id'));

        $this->assertStringContainsString('<h2>Aljabar</h2>', $item->resource->content);
        $this->assertStringNotContainsString('<script>', $item->resource->content);
        $this->assertStringNotContainsString('onerror', $item->resource->content);
    }

    public function test_unggahan_berkas_disimpan_di_disk_dan_unduhannya_berotorisasi(): void
    {
        Storage::fake('local');

        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();

        $item = $section->items()->create([
            'course_id' => $course->id, 'title' => 'Modul PDF', 'item_type' => 'RESOURCE',
            'resource_type' => 'FILE', 'completion_type' => 'VIEW', 'sort_order' => 2,
        ]);

        $this->postJson("/api/v1/lms/items/{$item->id}/upload", [
            'file' => UploadedFile::fake()->create('modul.pdf', 120, 'application/pdf'),
        ])->assertCreated();

        $resource = $item->fresh()->resource;
        $this->assertNotNull($resource->file_path);
        Storage::disk('local')->assertExists($resource->file_path);

        // Peserta terdaftar boleh mengunduh.
        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->get("/api/v1/lms/resources/{$resource->id}/download")->assertOk();

        // Guest tidak boleh mengunduh berkas course privat.
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/lms/resources/{$resource->id}/download")->assertStatus(401);
    }

    public function test_materi_tautan_dan_video_embed_diurai_dengan_benar(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();

        $url = $this->postJson("/api/v1/lms/sections/{$section->id}/items", [
            'title' => 'Referensi Eksternal', 'item_type' => 'RESOURCE', 'resource_type' => 'URL',
            'external_url' => 'https://www.khanacademy.org/math', 'open_in' => 'NEW_TAB',
        ])->assertCreated();

        $this->assertSame(
            'https://www.khanacademy.org/math',
            LmsLearningItem::query()->find($url->json('data.id'))->resource->external_url,
        );

        $video = $this->postJson("/api/v1/lms/sections/{$section->id}/items", [
            'title' => 'Video Persamaan Linear', 'item_type' => 'RESOURCE', 'resource_type' => 'VIDEO',
            'external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'open_in' => 'EMBED',
        ])->assertCreated();

        $item = LmsLearningItem::query()->find($video->json('data.id'));

        $this->assertSame('youtube', $item->resource->video_provider);

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->getJson("/api/v1/lms/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.item.resource.embed_url', 'https://www.youtube.com/embed/dQw4w9WgXcQ');
    }

    public function test_resource_buku_memiliki_bab_yang_dapat_diurutkan(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $course = $this->makeCourse($world);
        $section = $this->makeSection($course);

        $response = $this->postJson("/api/v1/lms/sections/{$section->id}/items", [
            'title' => 'Buku Ajar Aljabar', 'item_type' => 'RESOURCE', 'resource_type' => 'BOOK',
            'chapters' => [
                ['title' => 'Bab 1: Bilangan', 'content' => '<p>Bilangan bulat</p>'],
                ['title' => 'Bab 2: Persamaan', 'content' => '<p>Persamaan linear</p>'],
            ],
        ])->assertCreated();

        $item = LmsLearningItem::query()->findOrFail($response->json('data.id'));
        $this->assertSame(2, $item->chapters()->count());

        // Susun ulang bab: bab 2 naik ke posisi pertama.
        $chapters = $item->chapters()->orderBy('sort_order')->get();
        $this->putJson("/api/v1/lms/items/{$item->id}/chapters", [
            'chapters' => [
                ['id' => $chapters[1]->id, 'title' => 'Bab 2: Persamaan', 'content' => '<p>Persamaan linear</p>'],
                ['id' => $chapters[0]->id, 'title' => 'Bab 1: Bilangan', 'content' => '<p>Bilangan bulat</p>'],
                ['title' => 'Bab 3: Pertidaksamaan', 'content' => '<p>Pertidaksamaan</p>'],
            ],
        ])->assertOk();

        $this->assertSame(3, $item->chapters()->count());

        $this->getJson("/api/v1/lms/items/{$item->id}/chapters")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Bab 2: Persamaan');
    }

    public function test_section_dapat_diurutkan_ulang_dan_diduplikasi(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $course = $this->makeCourse($world);
        $first = $this->makeSection($course, ['title' => 'Bagian A', 'sort_order' => 1]);
        $second = $this->makeSection($course, ['title' => 'Bagian B', 'sort_order' => 2]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/sections/reorder", [
            'ids' => [$second->id, $first->id],
        ])->assertOk();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);

        $this->makePageItem($first);
        $this->postJson("/api/v1/lms/sections/{$first->id}/duplicate")->assertCreated();

        $this->assertSame(3, $course->sections()->count());
        $this->assertSame(2, $course->items()->count());
    }
}
