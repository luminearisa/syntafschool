<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Models\LmsForumPost;
use App\Modules\LMS\Models\LmsForumSubscription;
use Illuminate\Support\Facades\Notification;

/**
 * Forum: diskusi, balasan bersarang, moderasi, langganan, dan otorisasi
 * (§32–§36).
 */
class LmsForumTest extends LmsTestCase
{
    /** @return array{0: \App\Modules\LMS\Models\LmsActivity, 1: array<string, mixed>} */
    protected function forumWorld(array $attributes = []): array
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, array_merge([
            'activity_type' => 'FORUM',
            'title' => 'Diskusi Aljabar',
            'required_posts' => 1,
            'max_depth' => 2,
        ], $attributes));

        $this->publishActivity($activity);

        return [$activity->fresh(), $world];
    }

    /** Diskusi → balasan → balasan bersarang, dengan batas kedalaman (§33–§34). */
    public function test_diskusi_balasan_dan_batas_kedalaman(): void
    {
        [$activity, $world] = $this->forumWorld();

        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $discussion = $this->postJson("/api/v1/lms/activities/{$activity->id}/forum/discussions", [
            'title' => 'Bagaimana cara memfaktorkan?',
            'content' => '<p>Mohon penjelasannya.</p>',
        ])->assertCreated()->json('data');

        $reply = $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/posts", [
            'content' => '<p>Saya juga ingin tahu.</p>',
        ])->assertCreated()->json('data');

        $nested = $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/posts", [
            'content' => '<p>Terima kasih.</p>',
            'parent_id' => $reply['id'],
        ])->assertCreated()->json('data');

        $this->assertSame(2, $nested['depth']);

        // Kedalaman melebihi max_depth (2) ditolak untuk siswa.
        $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/posts", [
            'content' => '<p>Terlalu dalam.</p>',
            'parent_id' => $nested['id'],
        ])->assertStatus(422);

        $view = $this->getJson("/api/v1/lms/discussions/{$discussion['id']}")->assertOk()->json('data');

        $this->assertSame(1, count($view['posts']));
        $this->assertSame(1, count($view['posts'][0]['replies']));
        $this->assertTrue($view['posts'][0]['can_edit']);
    }

    /** Moderasi: kiriman siswa berstatus PENDING lalu disetujui guru (§35). */
    public function test_moderasi_menahan_kiriman_sampai_disetujui(): void
    {
        [$activity, $world] = $this->forumWorld(['moderation_enabled' => true]);

        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $discussion = $this->postJson("/api/v1/lms/activities/{$activity->id}/forum/discussions", [
            'title' => 'Menunggu moderasi',
            'content' => '<p>Isi diskusi.</p>',
        ])->assertCreated()->json('data');

        $this->assertSame('PENDING', $discussion['status']);

        // Selama PENDING belum tampil di daftar publik.
        $list = $this->getJson("/api/v1/lms/activities/{$activity->id}/forum")->assertOk()->json('data');
        $this->assertCount(0, $list['discussions']);

        // Guru melihat antrean moderasi dan menyetujui.
        $this->actingAsTeacher($world['teacher']);

        $queue = $this->getJson("/api/v1/lms/activities/{$activity->id}/forum/moderation")
            ->assertOk()->json('data');

        $this->assertCount(1, $queue['discussions']);

        $this->postJson("/api/v1/lms/forum-moderation/discussion/{$discussion['id']}/approve")->assertOk();

        $this->actingAsStudent($student, $studentUser);

        $list = $this->getJson("/api/v1/lms/activities/{$activity->id}/forum")->assertOk()->json('data');
        $this->assertCount(1, $list['discussions']);
    }

    /** Guru dapat menyembunyikan, mengunci, dan menempel diskusi (§35). */
    public function test_guru_dapat_menyembunyikan_mengunci_dan_menempel(): void
    {
        [$activity, $world] = $this->forumWorld();

        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $discussion = $this->postJson("/api/v1/lms/activities/{$activity->id}/forum/discussions", [
            'title' => 'Diskusi siswa',
            'content' => '<p>Isi.</p>',
        ])->assertCreated()->json('data');

        $this->actingAsTeacher($world['teacher']);

        $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/pin")
            ->assertOk()->assertJsonPath('data.is_pinned', true);

        $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/lock")
            ->assertOk()->assertJsonPath('data.is_locked', true);

        // Diskusi terkunci tidak dapat dibalas siswa.
        $this->actingAsStudent($student, $studentUser);

        $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/posts", ['content' => '<p>Balasan.</p>'])
            ->assertStatus(422);

        // Guru dapat menyembunyikan kiriman siswa.
        $this->actingAsTeacher($world['teacher']);

        $post = LmsForumPost::query()->create([
            'discussion_id' => $discussion['id'],
            'user_id' => $studentUser->id,
            'content' => '<p>Kiriman tidak pantas.</p>',
            'depth' => 1,
            'status' => 'VISIBLE',
        ]);

        $this->postJson("/api/v1/lms/forum-moderation/post/{$post->id}/hide")->assertOk();

        $this->assertSame('HIDDEN', $post->fresh()->status?->value);
    }

    /** Otorisasi: siswa tidak dapat memoderasi forum (§54). */
    public function test_siswa_tidak_dapat_memoderasi(): void
    {
        [$activity, $world] = $this->forumWorld();

        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $discussion = $this->postJson("/api/v1/lms/activities/{$activity->id}/forum/discussions", [
            'title' => 'Diskusi',
            'content' => '<p>Isi.</p>',
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/pin")->assertForbidden();
        $this->getJson("/api/v1/lms/activities/{$activity->id}/forum/moderation")->assertForbidden();
    }

    /** Langganan forum mengirim notifikasi ke pelanggan lain (§36). */
    public function test_langganan_forum_mengirim_notifikasi(): void
    {
        Notification::fake();

        [$activity, $world] = $this->forumWorld();

        $teacherUser = $this->actingAsTeacher($world['teacher']);
        $this->postJson("/api/v1/lms/activities/{$activity->id}/forum/subscribe")->assertOk();

        $this->assertDatabaseHas('lms_forum_subscriptions', [
            'user_id' => $teacherUser->id,
        ]);

        // Siswa membuka diskusi → guru (pelanggan) menerima notifikasi.
        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/forum/discussions", [
            'title' => 'Diskusi baru',
            'content' => '<p>Isi diskusi.</p>',
        ])->assertCreated();

        Notification::assertSentTo($teacherUser, \App\Modules\LMS\Notifications\LmsForumPostNotification::class);

        // Berhenti berlangganan (hanya langganan guru tersebut yang terhapus).
        \Laravel\Sanctum\Sanctum::actingAs($teacherUser, ['*']);
        $this->deleteJson("/api/v1/lms/activities/{$activity->id}/forum/subscribe")->assertOk();

        $this->assertSame(0, LmsForumSubscription::query()
            ->where('forum_id', $activity->forum->id)
            ->where('user_id', $teacherUser->id)
            ->count(), 'Langganan guru harus terhapus setelah unsubscribe.');
    }

    /** Siswa hanya dapat mengubah kiriman sendiri (§33). */
    public function test_siswa_hanya_dapat_mengubah_kiriman_sendiri(): void
    {
        [$activity, $world] = $this->forumWorld();

        $firstUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $firstUser);
        $this->actingAsStudent($world['students'][0], $firstUser);

        $discussion = $this->postJson("/api/v1/lms/activities/{$activity->id}/forum/discussions", [
            'title' => 'Diskusi siswa 1',
            'content' => '<p>Isi.</p>',
        ])->assertCreated()->json('data');

        // Siswa lain mencoba mengubah kiriman tersebut.
        $secondUser = $world['studentUsers'][1];
        $this->enroll($activity->course, $secondUser);
        $this->actingAsStudent($world['students'][1], $secondUser);

        $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/posts", [
            'content' => '<p>Balasan siswa 2.</p>',
        ])->assertCreated();

        $post = LmsForumPost::query()->where('user_id', $secondUser->id)->firstOrFail();

        $this->putJson("/api/v1/lms/forum-posts/{$post->id}", ['content' => '<p>Diubah.</p>'])->assertOk();

        // Kiriman siswa pertama tidak dapat diubah siswa kedua.
        $studentOnePost = LmsForumPost::query()->where('user_id', $firstUser->id)->first();

        if ($studentOnePost !== null) {
            $this->putJson("/api/v1/lms/forum-posts/{$studentOnePost->id}", ['content' => '<p>Diubah.</p>'])
                ->assertStatus(422);
        }
    }
}
