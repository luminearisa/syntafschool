<?php

namespace App\Services\Academic;

use App\Models\ReportCard;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AcademicActivityNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Mengirim notifikasi akademik (kanal database) ke akun wali murid.
 *
 * Hanya mengirim ke akun yang aktif; wali tanpa akun tetap menerima rapor
 * cetak yang dibagikan sekolah, jadi tidak ada kegagalan berarti.
 */
class GuardianNotifier
{
    public function __construct(private readonly GuardianUserResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $context
     * @return int jumlah penerima
     */
    public function notifyStudent(int $studentId, string $type, string $title, string $body, array $context = []): int
    {
        $userIds = $this->resolver->userIdsFor($studentId);

        if ($userIds === []) {
            return 0;
        }

        $recipients = User::query()
            ->whereIn('id', $userIds)
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        Notification::send($recipients, new AcademicActivityNotification(
            $type,
            $title,
            $body,
            ['student_id' => $studentId] + $context,
        ));

        return $recipients->count();
    }

    /**
     * Beri tahu wali bahwa rapor telah diterbitkan. Bila ada mata pelajaran di
     * bawah KKTP, nama-namanya dicantumkan (maksimal lima, sisanya dihitung).
     *
     * @param  list<string>  $belowKktpSubjects
     */
    public function notifyReportPublished(ReportCard $card, array $belowKktpSubjects = []): int
    {
        $studentName = $card->student?->full_name ?? 'Ananda';
        $semester = $card->semester?->name ?? 'semester ini';

        $body = "Rapor {$semester} untuk {$studentName} telah diterbitkan dan dapat dilihat orang tua/wali.";

        if ($belowKktpSubjects !== []) {
            $shown = array_slice($belowKktpSubjects, 0, 5);
            $suffix = count($belowKktpSubjects) > 5
                ? ' dan '.(count($belowKktpSubjects) - 5).' mata pelajaran lain'
                : '';

            $body .= ' Perlu perhatian: nilai '.implode(', ', $shown).$suffix.' masih di bawah KKTP.';
        }

        return $this->notifyStudent((int) $card->student_id, 'REPORT_CARD_PUBLISHED', 'Rapor diterbitkan', $body, [
            'reference_type' => 'report_card',
            'reference_id' => (int) $card->id,
            'route' => '/assessments/report-cards',
            'meta' => ['below_kktp' => $belowKktpSubjects],
        ]);
    }

    /** Beri tahu wali bahwa siswa alpa beberapa hari berturut-turut. */
    public function notifyConsecutiveAbsence(Student $student, int $streak, string $latestDate): int
    {
        $name = $student->full_name ?? 'Siswa';

        return $this->notifyStudent((int) $student->id, 'ATTENDANCE_CONSECUTIVE_ABSENCE', 'Alpa berturut-turut', "{$name} tercatat alpa {$streak} hari berturut-turut (terakhir {$latestDate}). Mohon konfirmasi ke sekolah.", [
            'reference_type' => 'attendance',
            'route' => '/attendance/recap',
            'meta' => ['streak' => $streak, 'date' => $latestDate],
        ]);
    }
}
