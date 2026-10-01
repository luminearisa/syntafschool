<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Bk\Domain\Contracts\ParentProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkFollowUp;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi BK — ringkas, tanpa isi catatan.
 *
 * Setiap pesan dibangun dari **fakta operasional** (nomor kasus, tanggal, jenis
 * tindakan). Kelas ini tidak pernah menerima `BkCaseNote` atau
 * `counselor_note`, sehingga kebocoran lewat notifikasi tidak mungkin terjadi
 * karena kelalaian pemanggil.
 */
class BkNotificationService
{
    public function __construct(
        private readonly NotificationProviderInterface $notifications,
        private readonly ParentProviderInterface $parents,
        private readonly StudentProviderInterface $students,
    ) {
    }

    /** Koordinator/administrator BK penerima pemberitahuan operasional. */
    public function notifyTeam(NotificationEvent $event, string $summary, array $payload = []): int
    {
        $sent = 0;

        $receivers = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['BK_ADMIN', 'BK_COORDINATOR']))
            ->where('is_active', true)
            ->pluck('id');

        foreach ($receivers as $userId) {
            $this->notifications->send((int) $userId, $event->value, $summary, $payload);
            $sent++;
        }

        return $sent;
    }

    public function notifyUser(int $userId, NotificationEvent $event, string $summary, array $payload = []): void
    {
        $this->notifications->send($userId, $event->value, $summary, $payload);
    }

    /** Wali yang sah dari seorang siswa (untuk persetujuan & undangan pertemuan). */
    public function notifyGuardians(int $studentId, NotificationEvent $event, string $summary, array $payload = []): int
    {
        $sent = 0;

        foreach ($this->parents->guardianUserIdsForStudent($studentId) as $userId) {
            $this->notifications->send($userId, $event->value, $summary, $payload);
            $sent++;
        }

        return $sent;
    }

    /** Wali kelas siswa (bila memang ada) — hanya untuk pesan tingkat ringkas. */
    public function notifyHomeroomTeacher(int $studentId, NotificationEvent $event, string $summary, array $payload = []): bool
    {
        $snapshot = $this->students->snapshot($studentId);
        $teacherId = $snapshot['homeroom_teacher_id'] ?? null;

        if ($teacherId === null) {
            return false;
        }

        $this->notifications->send((int) $teacherId, $event->value, $summary, $payload);

        return true;
    }

    public function notifyCaseAssigned(BkCase $case, int $counselorUserId): void
    {
        $summary = sprintf('Kasus %s ditugaskan kepada Anda.', $case->case_number);

        $this->notifyUser($counselorUserId, NotificationEvent::CASE_ASSIGNED, $summary, [
            'case_id' => $case->id,
            'case_number' => $case->case_number,
            'priority' => $case->priority->value,
        ]);
    }

    /**
     * Pengingat tindak lanjut — dipanggil perintah terjadwal.
     *
     * Ringkasan memuat nomor kasus, tanggal, dan pemiliknya saja.
     */
    public function notifyFollowUp(BkFollowUp $followUp, bool $overdue): void
    {
        $event = $overdue ? NotificationEvent::FOLLOW_UP_OVERDUE : NotificationEvent::FOLLOW_UP_DUE;
        $summary = sprintf(
            '%s: tindak lanjut "%s" untuk kasus %s %s.',
            $overdue ? 'Terlambat' : 'Jatuh tempo',
            $followUp->title,
            $followUp->case?->case_number ?? '-',
            $overdue ? 'melewati tenggat '.$followUp->due_date?->toDateString() : 'pada '.$followUp->due_date?->toDateString(),
        );

        $payload = [
            'case_id' => $followUp->case_id,
            'follow_up_id' => $followUp->id,
            'due_date' => $followUp->due_date?->toDateString(),
        ];

        if ($followUp->assigned_to !== null) {
            $this->notifyUser((int) $followUp->assigned_to, $event, $summary, $payload);
        }

        // Koordinator hanya diberi tahu bila sudah terlambat, agar tidak bising.
        if ($overdue) {
            $this->notifyTeam($event, $summary, $payload);
        }

        Log::info('BK: notifikasi tindak lanjut dikirim.', ['follow_up' => $followUp->id, 'overdue' => $overdue]);
    }
}
