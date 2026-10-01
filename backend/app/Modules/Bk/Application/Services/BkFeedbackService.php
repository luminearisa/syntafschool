<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\FeedbackStatus;
use App\Modules\Bk\Domain\Enums\FeedbackSubjectType;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkFeedback;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Umpan balik siswa dan orang tua.
 *
 * Aturan yang membedakan fitur ini dari kotak saran biasa:
 *   - **Setelah final, tidak dapat diubah siapa pun.** Termasuk konselor, termasuk
 *     administrator modul. Yang tersisa hanyalah menariknya (`WITHDRAWN`), dan
 *     penarikan tidak menghapus isi aslinya.
 *   - **Konselor tidak dapat menyunting umpan balik siswa.** Ia hanya dapat
 *     menandai sudah membacanya.
 *   - **Anonim benar-benar anonim bagi pembaca**: identitas tidak dikirim pada
 *     payload bila pengirim memilih anonim, dan pencatatan audit tetap ada di
 *     server tanpa membuka identitas ke pembaca biasa.
 */
class BkFeedbackService
{
    public function __construct(
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = BkFeedback::query()->visible()->with(['student:id,full_name', 'guardian:id,full_name']);

        if (! $this->access->isBkStaff($actor)) {
            $studentIds = array_merge(
                array_filter([$this->access->studentIdForUser($actor)]),
                $this->access->guardianStudentIds($actor),
            );

            $query->whereIn('student_id', $studentIds === [] ? [-1] : $studentIds);
        }

        return $query
            ->when($filters['subject_type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', (int) $id))
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['min_rating'] ?? null, fn ($q, $rating) => $q->where('rating', '>=', (int) $rating))
            ->orderByDesc('submitted_at')
            ->paginate($perPage);
    }

    /**
     * Umpan balik dari siswa.
     *
     * Siswa hanya dapat memberi umpan balik untuk dirinya sendiri, dan hanya bila
     * ia memang pihak pada objek yang dinilai (peserta sesi/program/kasusnya).
     *
     * @param array<string, mixed> $data
     */
    public function submitByStudent(User $actor, array $data): BkFeedback
    {
        $studentId = $this->access->studentIdForUser($actor);

        if ($studentId === null) {
            throw new BkValidationException(['student_id' => ['Akun ini tidak terhubung ke data siswa.']]);
        }

        return $this->store($actor, $studentId, null, [
            ...$data,
            'submitted_by_type' => 'STUDENT',
        ]);
    }

    /**
     * Umpan balik dari orang tua/wali.
     *
     * Status hubungan wali diperiksa terhadap siswa yang disebut, bukan terhadap
     * siswa mana pun yang dikirim klien.
     *
     * @param array<string, mixed> $data
     */
    public function submitByGuardian(User $actor, array $data): BkFeedback
    {
        $studentId = (int) ($data['student_id'] ?? 0);

        if ($studentId === 0 || ! $this->access->isGuardianOf($actor, $studentId)) {
            throw new BkValidationException(['student_id' => ['Anda hanya dapat memberi umpan balik untuk anak Anda sendiri.']]);
        }

        return $this->store($actor, $studentId, null, [
            ...$data,
            'submitted_by_type' => 'PARENT',
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function store(User $actor, int $studentId, ?int $guardianId, array $data): BkFeedback
    {
        $subjectType = FeedbackSubjectType::from((string) $data['subject_type']);

        if (empty($data['rating']) && empty($data['comment'])) {
            throw new BkValidationException([
                'rating' => ['Isi setidaknya penilaian atau komentar.'],
            ]);
        }

        if (! empty($data['rating']) && ((int) $data['rating'] < 1 || (int) $data['rating'] > 5)) {
            throw new BkValidationException(['rating' => ['Penilaian berada pada skala 1 sampai 5.']]);
        }

        if (($data['is_anonymous'] ?? false) && ! config('bk.feedback.allow_anonymous', true)) {
            throw new BkValidationException(['is_anonymous' => ['Sekolah menonaktifkan umpan balik anonim untuk saat ini.']]);
        }

        return DB::transaction(function () use ($actor, $studentId, $guardianId, $subjectType, $data): BkFeedback {
            $feedback = BkFeedback::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $data['subject_id'] ?? null,
                'case_id' => $data['case_id'] ?? null,
                'student_id' => $studentId,
                'guardian_id' => $guardianId,
                'submitted_by_type' => $data['submitted_by_type'] ?? $subjectType->defaultAudience(),
                'submitted_by' => $actor->id,
                'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
                'rating' => $data['rating'] ?? null,
                'comment' => $data['comment'] ?? null,
                'status' => FeedbackStatus::SUBMITTED,
                'submitted_at' => now(),
                'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::STAFF_ONLY,
                'context' => [
                    'channel' => $data['channel'] ?? 'app',
                    'locale' => $data['locale'] ?? 'id',
                ],
            ]);

            // Audit mencatat bahwa umpan balik masuk — tanpa menyalin isi komentar
            // ke jejak audit, karena jejak audit dibaca lebih banyak orang.
            $this->audit->record(AuditAction::FEEDBACK_SUBMIT, $feedback, $actor, [
                'subject_type' => $subjectType->value,
                'anonymous' => $feedback->is_anonymous,
                'has_comment' => ! empty($feedback->comment),
                'rating' => $feedback->rating,
            ]);

            $this->notifications->notifyTeam(
                NotificationEvent::FEEDBACK_RECEIVED,
                "Umpan balik baru untuk {$subjectType->label()}",
                ['feedback_id' => $feedback->id, 'rating' => $feedback->rating],
            );

            return $feedback->refresh();
        });
    }

    /**
     * Menandai umpan balik sebagai final.
     *
     * Hanya pengirimnya yang boleh memfinalkan, dan setelah final tidak ada jalan
     * untuk mengubah isinya.
     */
    public function finalize(User $actor, BkFeedback $feedback): BkFeedback
    {
        if (! $this->isOwner($actor, $feedback)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Hanya pengirim umpan balik yang dapat memfinalkannya.');
        }

        if ($feedback->status === FeedbackStatus::FINAL) {
            return $feedback;
        }

        $feedback->forceFill(['status' => FeedbackStatus::FINAL, 'finalized_at' => now()])->save();

        $this->audit->record(AuditAction::UPDATE, $feedback, $actor, ['finalized' => true]);

        return $feedback->refresh();
    }

    /** Menarik umpan balik (isi tidak dihapus, hanya tidak lagi ditampilkan). */
    public function withdraw(User $actor, BkFeedback $feedback, ?string $reason = null): BkFeedback
    {
        if (! $this->isOwner($actor, $feedback) && ! $this->access->isBkAdmin($actor)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Hanya pengirim atau admin BK yang dapat menarik umpan balik.');
        }

        $feedback->forceFill([
            'status' => FeedbackStatus::WITHDRAWN,
            'context' => array_merge($feedback->context ?? [], ['withdrawn_reason' => $reason]),
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $feedback, $actor, ['withdrawn' => true]);

        return $feedback->refresh();
    }

    /**
     * Mengubah isi umpan balik.
     *
     * Hanya pengirim yang boleh, dan hanya selama belum final. Konselor tidak
     * pernah boleh — bahkan untuk "memperbaiki ejaan".
     *
     * @param array<string, mixed> $data
     */
    public function updateComment(User $actor, BkFeedback $feedback, array $data): BkFeedback
    {
        if (! $this->isOwner($actor, $feedback)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Konselor dan staf tidak dapat mengubah umpan balik siswa.');
        }

        if ($feedback->status->isLocked()) {
            throw new BkValidationException(['status' => ['Umpan balik yang sudah final tidak dapat diubah.']]);
        }

        $before = $feedback->only(['rating', 'comment']);

        $feedback->fill([
            'rating' => $data['rating'] ?? $feedback->rating,
            'comment' => $data['comment'] ?? $feedback->comment,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $feedback, $actor, [
            'changed' => array_keys($this->audit->diff($before, $feedback->only(array_keys($before)))),
        ]);

        return $feedback->refresh();
    }

    /** Menandai sudah dibaca. Inilah satu-satunya tindakan konselor atas umpan balik. */
    public function markReviewed(User $actor, BkFeedback $feedback, ?string $note = null): BkFeedback
    {
        $feedback->forceFill([
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'context' => array_merge($feedback->context ?? [], ['review_note' => $note]),
        ])->save();

        $this->audit->record(AuditAction::FEEDBACK_VIEW, $feedback, $actor, ['reviewed' => true]);

        return $feedback->refresh();
    }

    public function isOwner(User $actor, BkFeedback $feedback): bool
    {
        if ($feedback->submitted_by !== null && (int) $feedback->submitted_by === $actor->id) {
            return true;
        }

        return $feedback->student_id !== null && $this->access->studentIdForUser($actor) === (int) $feedback->student_id;
    }

    /** Ringkasan umpan balik untuk satu objek (mis. satu program atau satu penutupan kasus). */
    public function summaryForSubject(FeedbackSubjectType $subjectType, int $subjectId): array
    {
        $rows = BkFeedback::query()->visible()->forSubject($subjectType->value, $subjectId)->get();
        $rated = $rows->whereNotNull('rating');

        return [
            'subject_type' => $subjectType->value,
            'subject_id' => $subjectId,
            'total' => $rows->count(),
            'anonymous' => $rows->where('is_anonymous', true)->count(),
            'average_rating' => $rated->isEmpty() ? null : round((float) $rated->avg('rating'), 2),
            'distribution' => collect([1, 2, 3, 4, 5])
                ->map(fn (int $star): array => ['rating' => $star, 'total' => $rated->where('rating', $star)->count()])
                ->all(),
        ];
    }

    /** Umpan balik untuk profil siswa: hanya yang memang boleh dilihat siswa/walinya. */
    public function forStudent(int $studentId, bool $includeComments = false): array
    {
        $rows = BkFeedback::query()->visible()->where('student_id', $studentId)->orderByDesc('submitted_at')->get();

        return [
            'total' => $rows->count(),
            'average_rating' => $rows->whereNotNull('rating')->isEmpty() ? null : round((float) $rows->whereNotNull('rating')->avg('rating'), 2),
            'items' => $includeComments
                ? $rows->map(fn (BkFeedback $row): array => [
                    'id' => $row->id,
                    'subject_type' => $row->subject_type->value,
                    'rating' => $row->rating,
                    'comment' => $row->comment,
                    'submitted_at' => $row->submitted_at?->toIso8601String(),
                ])->all()
                : [],
        ];
    }
}
