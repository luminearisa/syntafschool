<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ReviewStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseFeedback;
use App\Modules\LMS\Models\LmsCourseReview;
use App\Modules\LMS\Models\LmsEnrollment;

/**
 * Ulasan & umpan balik course (§31–§32).
 *
 * Aturan yang dipegang:
 *  - satu siswa satu ulasan per course; mengirim ulang berarti memperbarui
 *    ulasan sendiri, bukan menumpuk baris baru;
 *  - ulasan tidak tampil sebelum dimoderasi (default `auto_approve = false`),
 *    dan nama tampilan publik mengikuti pengaturan privasi sekolah;
 *  - cache `rating_average`/`rating_count` pada course dihitung ulang HANYA dari
 *    ulasan yang sudah disetujui, supaya katalog publik tidak memuat ulasan
 *    yang belum diperiksa;
 *  - umpan balik kualitatif terpisah dari rating bintang: yang pertama untuk
 *    perbaikan course, yang kedua untuk katalog.
 */
class CourseReviewService
{
    use LogsLmsAudit;

    public function __construct(private readonly LmsNotifier $notifier) {}

    /** Ulasan aktif bila modul dinyalakan admin DAN course mengizinkannya. */
    public function enabled(LmsCourse $course): bool
    {
        if (! (bool) config('lms.reviews.enabled', true)) {
            return false;
        }

        return (bool) ($course->review_enabled ?? true);
    }

    public function find(int $id): LmsCourseReview
    {
        $review = LmsCourseReview::query()->with('course')->find($id);

        if ($review === null) {
            LmsFault::notFound('Ulasan tidak ditemukan.');
        }

        return $review;
    }

    /**
     * Kirim/perbarui ulasan milik sendiri.
     *
     * @param  array<string, mixed>  $data  rating (1–5), review
     */
    public function submit(LmsCourse $course, LmsActor $actor, array $data): LmsCourseReview
    {
        if (! $this->enabled($course)) {
            LmsFault::forbidden('Ulasan belum diaktifkan untuk course ini.');
        }

        $rating = (int) ($data['rating'] ?? 0);

        if ($rating < 1 || $rating > 5) {
            LmsFault::invalid('Rating harus antara 1 sampai 5.', 'rating');
        }

        $enrollment = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $actor->id)
            ->first();

        if ((bool) config('lms.reviews.require_enrollment', true) && $enrollment === null) {
            LmsFault::forbidden('Hanya peserta course yang dapat menulis ulasan.');
        }

        $minimumCompletion = (float) config('lms.reviews.min_completion_percent', 0);

        if ($minimumCompletion > 0 && (float) ($enrollment?->progress_percent ?? 0) < $minimumCompletion) {
            LmsFault::conflict('Ulasan dapat ditulis setelah menyelesaikan minimal '.$minimumCompletion.'% course.');
        }

        $completed = $enrollment !== null
            && ($enrollment->completed_at !== null || $enrollment->status->value === 'COMPLETED');

        $status = (bool) config('lms.reviews.auto_approve', false) ? ReviewStatus::APPROVED : ReviewStatus::PENDING;

        $review = LmsCourseReview::query()->updateOrCreate(
            ['course_id' => $course->id, 'user_id' => $actor->id],
            [
                'enrollment_id' => $enrollment?->id,
                'rating' => $rating,
                'review' => $data['review'] ?? null,
                'status' => $status,
                'is_verified_completion' => $completed,
                'display_name' => $this->displayName($actor),
                'moderated_by' => null,
                'moderated_at' => $status === ReviewStatus::APPROVED ? now() : null,
                'moderation_note' => null,
            ],
        );

        $this->refreshRatingCache($course);
        $this->audit('LMS_COURSE_REVIEW_SUBMITTED', $review, [
            'course_id' => (int) $course->id,
            'rating' => $rating,
        ]);

        return $review->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(LmsCourseReview $review, array $data, LmsActor $actor): LmsCourseReview
    {
        if ($review->user_id !== $actor->id && ! $actor->can('lms.course.manage')) {
            LmsFault::forbidden('Hanya penulis ulasan yang dapat mengubahnya.');
        }

        if (array_key_exists('rating', $data)) {
            $rating = (int) $data['rating'];

            if ($rating < 1 || $rating > 5) {
                LmsFault::invalid('Rating harus antara 1 sampai 5.', 'rating');
            }

            $review->rating = $rating;
        }

        if (array_key_exists('review', $data)) {
            $review->review = $data['review'];
        }

        // Perubahan isi mengembalikan ulasan ke antrean moderasi.
        if ($review->isDirty() && ! (bool) config('lms.reviews.auto_approve', false)) {
            $review->status = ReviewStatus::PENDING;
            $review->moderated_at = null;
            $review->moderated_by = null;
        }

        $review->save();

        $this->refreshRatingCache($review->course);

        return $review->refresh();
    }

    public function delete(LmsCourseReview $review, LmsActor $actor): void
    {
        if ($review->user_id !== $actor->id && ! $actor->can('lms.course.manage')) {
            LmsFault::forbidden('Hanya penulis ulasan yang dapat menghapusnya.');
        }

        $course = $review->course;

        $this->audit('LMS_COURSE_REVIEW_DELETED', $review, ['course_id' => (int) $review->course_id]);

        $review->delete();

        if ($course !== null) {
            $this->refreshRatingCache($course);
        }
    }

    /**
     * Moderasi ulasan (§31) — keputusan beserta alasannya dicatat dan dikirim
     * ke penulis bila ditolak.
     */
    public function moderate(LmsCourseReview $review, LmsActor $actor, string $decision, ?string $note = null): LmsCourseReview
    {
        $status = ReviewStatus::tryFrom($decision);

        if ($status === null) {
            LmsFault::invalid('Keputusan moderasi tidak dikenal.', 'status');
        }

        $review->fill([
            'status' => $status,
            'moderated_by' => $actor->id,
            'moderated_at' => now(),
            'moderation_note' => $note,
        ])->save();

        $course = $review->course;

        if ($course !== null) {
            $this->refreshRatingCache($course);
        }

        if ($status === ReviewStatus::REJECTED) {
            $this->notifier->reviewModerated([(int) $review->user_id], [
                'title' => 'Ulasan tidak ditampilkan',
                'message' => 'Ulasan Anda pada course '.($course?->name ?? '-').' belum dapat ditampilkan.'.($note === null ? '' : ' Catatan: '.$note),
                'url' => '/lms/courses/'.$review->course_id,
                'course_id' => (int) $review->course_id,
                'course_name' => $course?->name,
                'review_id' => (int) $review->id,
                'status' => $status->value,
            ]);
        }

        $this->audit('LMS_COURSE_REVIEW_MODERATED', $review, ['status' => $status->value]);

        return $review->refresh();
    }

    public function markHelpful(LmsCourseReview $review): LmsCourseReview
    {
        $review->increment('helpful_count');

        return $review->refresh();
    }

    /**
     * Ulasan yang tampil untuk katalog publik (§31).
     *
     * @return array<string, mixed>
     */
    public function publicFor(LmsCourse $course, int $limit = 10): array
    {
        $query = LmsCourseReview::query()
            ->where('course_id', $course->id)
            ->where('status', ReviewStatus::APPROVED->value)
            ->orderByDesc('helpful_count')
            ->orderByDesc('created_at');

        return [
            'enabled' => $this->enabled($course),
            'summary' => $this->summary($course),
            'reviews' => $query->limit(max(1, $limit))->get()
                ->map(fn (LmsCourseReview $review) => $this->publicRow($review))
                ->all(),
        ];
    }

    /**
     * Ringkasan rating — dihitung dari ulasan yang sudah disetujui saja.
     *
     * @return array<string, mixed>
     */
    public function summary(LmsCourse $course): array
    {
        $rows = LmsCourseReview::query()
            ->where('course_id', $course->id)
            ->where('status', ReviewStatus::APPROVED->value)
            ->get();

        $distribution = [];

        for ($star = 5; $star >= 1; $star--) {
            $distribution[$star] = $rows->where('rating', $star)->count();
        }

        return [
            'average' => $rows->isEmpty() ? null : round((float) $rows->avg('rating'), 2),
            'count' => $rows->count(),
            'distribution' => $distribution,
            'verified_count' => $rows->where('is_verified_completion', true)->count(),
            'pending_count' => LmsCourseReview::query()
                ->where('course_id', $course->id)
                ->where('status', ReviewStatus::PENDING->value)
                ->count(),
        ];
    }

    /**
     * Daftar ulasan untuk moderasi guru (§31).
     *
     * @param  array<string, mixed>  $filters  status
     * @return list<array<string, mixed>>
     */
    public function forModeration(LmsCourse $course, array $filters = []): array
    {
        return LmsCourseReview::query()
            ->where('course_id', $course->id)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LmsCourseReview $review) => $this->publicRow($review) + [
                'user_id' => (int) $review->user_id,
                'moderation_note' => $review->moderation_note,
                'moderated_at' => $review->moderated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Ulasan milik pengguna pada sebuah course (bila ada). */
    public function forUser(LmsCourse $course, int $userId): ?array
    {
        $review = LmsCourseReview::query()
            ->where('course_id', $course->id)
            ->where('user_id', $userId)
            ->first();

        return $review === null ? null : $this->publicRow($review) + [
            'moderation_note' => $review->moderation_note,
        ];
    }

    /**
     * Hitung ulang cache rating course (§31).
     *
     * Hanya ulasan APPROVED yang dihitung — satu tempat, supaya nilai di
     * katalog tidak pernah berbeda dari daftar ulasan.
     */
    public function refreshRatingCache(LmsCourse $course): LmsCourse
    {
        $rows = LmsCourseReview::query()
            ->where('course_id', $course->id)
            ->where('status', ReviewStatus::APPROVED->value)
            ->get();

        $course->forceFill([
            'rating_average' => $rows->isEmpty() ? null : round((float) $rows->avg('rating'), 2),
            'rating_count' => $rows->count(),
        ])->save();

        return $course;
    }

    /** Hitung ulang cache rating seluruh course (dipakai setelah moderasi massal). */
    public function refreshAllRatingCaches(): int
    {
        $count = 0;

        LmsCourse::query()->chunkById(100, function ($courses) use (&$count): void {
            foreach ($courses as $course) {
                $this->refreshRatingCache($course);
                $count++;
            }
        });

        return $count;
    }

    // ------------------------------------------------------------------ umpan balik (§32)

    /**
     * Umpan balik kualitatif: apa yang disukai & apa yang perlu diperbaiki.
     *
     * @param  array<string, mixed>  $data  liked, improve, rating, meta
     */
    public function submitFeedback(LmsCourse $course, LmsActor $actor, array $data): LmsCourseFeedback
    {
        $liked = trim((string) ($data['liked'] ?? ''));
        $improve = trim((string) ($data['improve'] ?? ''));

        if ($liked === '' && $improve === '') {
            LmsFault::invalid('Isi minimal satu bagian umpan balik.', 'liked');
        }

        $enrollment = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $actor->id)
            ->first();

        $feedback = LmsCourseFeedback::query()->create([
            'course_id' => $course->id,
            'user_id' => $actor->id,
            'enrollment_id' => $enrollment?->id,
            'liked' => $liked === '' ? null : $liked,
            'improve' => $improve === '' ? null : $improve,
            'rating' => isset($data['rating']) ? (int) $data['rating'] : null,
            'status' => 'SUBMITTED',
            'meta' => $data['meta'] ?? null,
            'submitted_at' => now(),
        ]);

        $this->audit('LMS_COURSE_FEEDBACK_SUBMITTED', $course, [
            'feedback_id' => (int) $feedback->id,
            'user_id' => $actor->id,
        ]);

        return $feedback;
    }

    /**
     * Daftar umpan balik sebuah course untuk guru.
     *
     * @return list<array<string, mixed>>
     */
    public function feedbackForCourse(LmsCourse $course, ?string $status = null): array
    {
        return LmsCourseFeedback::query()
            ->where('course_id', $course->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (LmsCourseFeedback $feedback) => [
                'id' => (int) $feedback->id,
                'user_id' => $feedback->user_id === null ? null : (int) $feedback->user_id,
                'liked' => $feedback->liked,
                'improve' => $feedback->improve,
                'rating' => $feedback->rating === null ? null : (int) $feedback->rating,
                'status' => $feedback->status,
                'submitted_at' => $feedback->submitted_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function feedbackSummary(LmsCourse $course): array
    {
        $rows = LmsCourseFeedback::query()->where('course_id', $course->id)->get();

        return [
            'total' => $rows->count(),
            'with_rating' => $rows->whereNotNull('rating')->count(),
            'average_rating' => $rows->whereNotNull('rating')->isEmpty()
                ? null
                : round((float) $rows->whereNotNull('rating')->avg('rating'), 2),
            'latest_at' => $rows->max('submitted_at')?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ internal

    /** @return array<string, mixed> */
    private function publicRow(LmsCourseReview $review): array
    {
        return [
            'id' => (int) $review->id,
            'rating' => (int) $review->rating,
            'review' => $review->review,
            'status' => $review->status->value,
            'status_label' => $review->status->label(),
            'display_name' => $review->displayName(),
            'is_verified_completion' => (bool) $review->is_verified_completion,
            'helpful_count' => (int) $review->helpful_count,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    /** Nama tampilan mengikuti pengaturan privasi sekolah (`name` | `initials`). */
    private function displayName(LmsActor $actor): string
    {
        if ((string) config('lms.reviews.public_display_name', 'name') !== 'initials') {
            return $actor->name;
        }

        $parts = preg_split('/\s+/', trim($actor->name)) ?: [];
        $initials = array_map(
            fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)).'.',
            array_slice(array_filter($parts), 0, 3),
        );

        return $initials === [] ? 'Peserta' : implode(' ', $initials);
    }
}
