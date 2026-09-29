<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Notifications\LmsCourseAnnouncementNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pesan guru ↔ siswa/wali dan sebutan (§33–§35).
 *
 * Sesuai konfigurasi (`lms.messaging.driver = notification`), pesan menumpang
 * notification center ERP yang sudah ada — tidak ada tabel pesan baru dan tidak
 * ada sistem chat paralel. Jejak pengirimannya tetap dapat diaudit lewat
 * activity log (`lms.activity`), sedangkan isi pesan tidak disimpan permanen
 * supaya data pribadi tidak menumpuk.
 *
 * Batas yang dijaga:
 *  - hanya guru/admin course yang boleh mengirim ke peserta (§33);
 *  - wali murid hanya menerima pesan tentang anaknya sendiri (§34);
 *  - panjang pesan dibatasi konfigurasi (`max_length`) (§35).
 */
class LmsMessagingService
{
    use LogsLmsAudit;

    public function __construct(private readonly LmsNotifier $notifier) {}

    /** Pesan aktif bila modulnya dinyalakan (default notification center). */
    public function enabled(): bool
    {
        return (string) config('lms.messaging.driver', 'notification') !== 'none';
    }

    /**
     * Kirim pesan ke peserta course tertentu (§33).
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $options  subject, mentions (list<int>), url
     * @return array<string, mixed>
     */
    public function sendToStudents(LmsCourse $course, LmsActor $sender, array $userIds, string $message, array $options = []): array
    {
        $this->assertCanSend($sender);

        $body = $this->normalizeMessage($message);
        $recipients = $this->studentsOf($course, $userIds);

        return $this->deliver($course, $sender, $recipients, $body, $options);
    }

    /**
     * Kirim pesan ke seluruh peserta course (§33).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function broadcastToCourse(LmsCourse $course, LmsActor $sender, string $message, array $options = []): array
    {
        $this->assertCanSend($sender);

        $userIds = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', 'STUDENT')
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->deliver($course, $sender, $userIds, $this->normalizeMessage($message), $options);
    }

    /**
     * Kirim pesan tentang seorang siswa kepada walinya (§34).
     *
     * Wali dipilih otomatis dari data ERP (`student_guardians` +
     * `ppdb_parent_accounts`); pada Standalone Mode dari `lms_guardians`.
     * Bila tidak ada akun wali aktif, tidak ada yang dikirim — bukan error.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendToGuardians(LmsCourse $course, LmsActor $sender, int $studentId, string $message, array $options = []): array
    {
        if (! (bool) config('lms.messaging.parent_recipients', true)) {
            LmsFault::forbidden('Pesan ke wali dimatikan oleh pengaturan sekolah.');
        }

        $this->assertCanSend($sender);

        $recipients = $this->guardianUserIdsFor($studentId);

        $result = $this->deliver($course, $sender, $recipients, $this->normalizeMessage($message), $options);
        $result['student_id'] = $studentId;

        return $result;
    }

    /**
     * Siswa yang boleh dikirimi pesan oleh aktor ini (§33).
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function studentsOf(LmsCourse $course, array $userIds): array
    {
        $enrolled = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', 'STUDENT')
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $requested = array_map('intval', $userIds);

        $allowed = array_values(array_intersect($requested, $enrolled));

        if ($allowed === []) {
            LmsFault::conflict('Tidak ada penerima yang terdaftar pada course ini.');
        }

        return $allowed;
    }

    /**
     * @param  list<int>  $recipients
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function deliver(LmsCourse $course, LmsActor $sender, array $recipients, string $message, array $options): array
    {
        if (! $this->enabled()) {
            return ['sent' => 0, 'recipients' => [], 'message' => 'Modul pesan dinonaktifkan.'];
        }

        $recipients = array_values(array_unique(array_filter($recipients, fn ($id) => (int) $id > 0)));

        if ($recipients === []) {
            return ['sent' => 0, 'recipients' => [], 'message' => 'Tidak ada penerima aktif.'];
        }

        $mentions = array_values(array_unique(array_map('intval', (array) ($options['mentions'] ?? []))));
        $subject = (string) ($options['subject'] ?? 'Pesan dari '.$sender->name);

        $sent = $this->notifier->send($recipients, new LmsCourseAnnouncementNotification(
            [
                'title' => $subject,
                'message' => $message,
                'url' => (string) ($options['url'] ?? '/lms/courses/'.$course->id),
                'course_id' => (int) $course->id,
                'course_name' => (string) $course->name,
                'sender' => [
                    'id' => $sender->id,
                    'name' => $sender->name,
                    'role' => $sender->primaryRole->value,
                ],
                'mentions' => $mentions,
                'sent_at' => now()->toIso8601String(),
            ],
            (array) config('lms.notifications.announcement_channels', ['database']),
        ));

        $this->audit('LMS_MESSAGE_SENT', $course, [
            'recipients' => count($recipients),
            'mentions' => $mentions,
        ]);

        return [
            'sent' => $sent,
            'recipients' => $recipients,
            'mentions' => $mentions,
            'message' => null,
        ];
    }

    /**
     * Akun wali aktif untuk seorang siswa (§34).
     *
     * Mengembalikan daftar kosong bila data wali tidak tersedia — pemanggil
     * tidak perlu tahu detail penyimpanan mode yang aktif.
     *
     * @return list<int>
     */
    public function guardianUserIdsFor(int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }

        try {
            if (config('lms.standalone', false)) {
                if (! Schema::hasTable('lms_guardians')) {
                    return [];
                }

                return DB::table('lms_guardians')
                    ->where('lms_learner_id', $studentId)
                    ->pluck('lms_user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            if (! Schema::hasTable('student_guardians') || ! Schema::hasTable('ppdb_parent_accounts')) {
                return [];
            }

            return DB::table('student_guardians')
                ->join('guardians', 'guardians.id', '=', 'student_guardians.guardian_id')
                ->join('ppdb_parent_accounts', 'ppdb_parent_accounts.guardian_id', '=', 'guardians.id')
                ->where('student_guardians.student_id', $studentId)
                ->where('ppdb_parent_accounts.account_status', 'active')
                ->pluck('ppdb_parent_accounts.user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Guru/admin course boleh mengirim; siswa hanya membalas lewat forum (§33). */
    private function assertCanSend(LmsActor $sender): void
    {
        if ($sender->primaryRole === ActorRole::STUDENT) {
            LmsFault::forbidden('Siswa tidak mengirim pesan langsung; gunakan forum diskusi course.');
        }

        if ($sender->primaryRole === ActorRole::PARENT && ! (bool) config('lms.messaging.parent_recipients', true)) {
            LmsFault::forbidden('Wali murid hanya dapat membaca pesan.');
        }
    }

    private function normalizeMessage(string $message): string
    {
        $body = trim($message);
        $max = max(50, (int) config('lms.messaging.max_length', 2000));

        if ($body === '') {
            LmsFault::invalid('Pesan tidak boleh kosong.', 'message');
        }

        return mb_substr($body, 0, $max);
    }
}
