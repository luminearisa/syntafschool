<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\ParentProviderInterface;
use App\Modules\Activity\Domain\Contracts\TeacherProviderInterface;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistration;

/**
 * Notifikasi aktivitas (§67).
 *
 * Dua hal yang dijaga di sini:
 *  - **penerima yang benar** — siswa, orang tua/wali, atau pembina, bergantung
 *    peristiwa; bukan "broadcast ke semua",
 *  - **tidak berulang** — setiap peristiwa menyusun `dedupe_key` yang stabil,
 *    sehingga pengiriman ulang (retry, perintah ganda) tidak mengirim dua kali.
 */
class ActivityNotificationService
{
    public function __construct(
        private readonly ActivityIntegrationService $integrations,
        private readonly ParentProviderInterface $parents,
        private readonly TeacherProviderInterface $teachers,
    ) {}

    /** Katalog peristiwa — dipakai layar pengaturan agar sekolah tahu apa yang dikirim. */
    public function events(): array
    {
        return [
            'REGISTRATION_SUBMITTED' => 'Pendaftaran diajukan',
            'REGISTRATION_SELECTED' => 'Lolos seleksi',
            'REGISTRATION_WAITLISTED' => 'Masuk daftar tunggu',
            'REGISTRATION_REJECTED' => 'Pendaftaran tidak lolos',
            'REGISTRATION_APPROVED' => 'Diterima sebagai anggota',
            'CONSENT_REQUIRED' => 'Persetujuan orang tua diperlukan',
            'CONSENT_DECIDED' => 'Persetujuan orang tua diputuskan',
            'SESSION_TOMORROW' => 'Pertemuan besok',
            'SESSION_CANCELLED' => 'Pertemuan dibatalkan',
            'ATTENDANCE_MISSING' => 'Kehadiran belum diisi',
            'COMPETITION_UPCOMING' => 'Kompetisi akan berlangsung',
            'ACHIEVEMENT_AWARDED' => 'Prestasi dicatat',
            'CERTIFICATE_ISSUED' => 'Sertifikat diterbitkan',
            'SCHEDULE_CHANGED' => 'Jadwal berubah',
            'PAYMENT_REQUIRED' => 'Tagihan aktivitas',
            'WITHDRAWAL_DECIDED' => 'Pengunduran diri diputuskan',
            'TRANSFER_DECIDED' => 'Pindah aktivitas diputuskan',
        ];
    }

    /**
     * @param  array{event: string, program_id?: int|null, student_id?: int|null, title?: string, body?: string,
     *               url?: string|null, recipient_user_ids?: list<int>, audience?: string, marker?: string}  $context
     * @return array{sent: int, suppressed: int, channels: list<string>, recipients: list<int>}
     */
    public function notify(array $context): array
    {
        $event = (string) $context['event'];
        $recipients = $context['recipient_user_ids'] ?? $this->resolveRecipients($context);

        $payload = [
            'event' => 'activity.'.$event,
            'title' => $context['title'] ?? ($this->events()[$event] ?? 'Kegiatan aktivitas'),
            'body' => $context['body'] ?? '',
            'url' => $context['url'] ?? null,
            'program_id' => $context['program_id'] ?? null,
            'student_id' => $context['student_id'] ?? null,
            'dedupe_key' => $this->dedupeKey($event, $context),
        ];

        $result = $this->integrations->call('NOTIFICATION', $event, $payload, fn () => array_merge(
            ['status' => 'OK'],
            $this->integrations->notifications()->send($recipients, $payload),
        ));

        return [
            'sent' => (int) ($result['sent'] ?? 0),
            'suppressed' => (int) ($result['suppressed'] ?? 0),
            'channels' => (array) ($result['channels'] ?? []),
            'recipients' => $recipients,
        ];
    }

    // ------------------------------------------------------ pembungkus peristiwa

    public function registrationSubmitted(ActivityRegistration $registration): array
    {
        return $this->notify([
            'event' => 'REGISTRATION_SUBMITTED',
            'program_id' => $registration->program_id,
            'student_id' => $registration->student_id,
            'body' => 'Pendaftaran '.$registration->code.' telah diterima dan sedang ditinjau.',
            'marker' => $registration->code,
        ]);
    }

    public function registrationDecided(ActivityRegistration $registration, string $decision): array
    {
        $event = match ($decision) {
            'APPROVE' => 'REGISTRATION_APPROVED',
            'REJECT' => 'REGISTRATION_REJECTED',
            'WAITLIST' => 'REGISTRATION_WAITLISTED',
            'SELECT' => 'REGISTRATION_SELECTED',
            default => 'REGISTRATION_SUBMITTED',
        };

        return $this->notify([
            'event' => $event,
            'program_id' => $registration->program_id,
            'student_id' => $registration->student_id,
            'body' => 'Status pendaftaran '.$registration->code.': '.$registration->status->label().'.',
            'marker' => $registration->code.'|'.$registration->status->value,
        ]);
    }

    public function paymentRequired(ActivityProgram $program, int $studentId, float $amount, ?string $marker = null): array
    {
        return $this->notify([
            'event' => 'PAYMENT_REQUIRED',
            'program_id' => $program->id,
            'student_id' => $studentId,
            'body' => 'Aktivitas '.$program->name.' memerlukan biaya '.number_format($amount, 0, ',', '.').'.',
            'marker' => $marker,
        ]);
    }

    public function sessionReminder(array $session): array
    {
        return $this->notify([
            'event' => 'SESSION_TOMORROW',
            'program_id' => $session['program_id'] ?? null,
            'body' => 'Pertemuan '.($session['program_name'] ?? 'aktivitas').' besok '.substr((string) $session['start_time'], 0, 5).'.',
            'marker' => 'session:'.($session['id'] ?? ''),
            'audience' => 'MEMBERS',
            'program_id_for_audience' => $session['program_id'] ?? null,
        ]);
    }

    // ------------------------------------------------------------- penerima

    /** @return list<int> */
    protected function resolveRecipients(array $context): array
    {
        $audience = (string) ($context['audience'] ?? 'STUDENT_AND_PARENTS');
        $studentId = $context['student_id'] ?? null;
        $programId = $context['program_id_for_audience'] ?? $context['program_id'] ?? null;

        $recipients = [];

        if (in_array($audience, ['STUDENT', 'STUDENT_AND_PARENTS', 'ALL'], true) && $studentId !== null) {
            $recipients = array_merge($recipients, $this->studentUserIds((int) $studentId));
        }

        if (in_array($audience, ['PARENTS', 'STUDENT_AND_PARENTS', 'ALL'], true) && $studentId !== null) {
            $recipients = array_merge($recipients, $this->parents->userIdsFor((int) $studentId));
        }

        if (in_array($audience, ['COACHES', 'ALL'], true) && $programId !== null) {
            $recipients = array_merge($recipients, $this->coachUserIds((int) $programId));
        }

        return array_values(array_unique(array_filter($recipients)));
    }

    /** @return list<int> */
    protected function studentUserIds(int $studentId): array
    {
        return User::query()
            ->where('userable_type', (new \App\Models\Student)->getMorphClass())
            ->where('userable_id', $studentId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return list<int> */
    protected function coachUserIds(int $programId): array
    {
        $program = ActivityProgram::query()->with('coaches')->find($programId);

        if ($program === null) {
            return [];
        }

        $ids = [];

        if ($program->coordinator_user_id !== null) {
            $ids[] = (int) $program->coordinator_user_id;
        }

        foreach ([$program->advisor_employee_id, $program->coach_employee_id] as $employeeId) {
            if ($employeeId !== null) {
                $ids = array_merge($ids, $this->teachers->userIdsForEmployee((int) $employeeId));
            }
        }

        foreach ($program->coaches as $coach) {
            if ($coach->user_id !== null) {
                $ids[] = (int) $coach->user_id;
            }

            if ($coach->employee_id !== null) {
                $ids = array_merge($ids, $this->teachers->userIdsForEmployee((int) $coach->employee_id));
            }
        }

        return array_values(array_unique($ids));
    }

    protected function dedupeKey(string $event, array $context): string
    {
        $marker = $context['marker'] ?? now()->toDateString();

        return implode(':', [
            'activity',
            strtolower($event),
            (string) ($context['student_id'] ?? '-'),
            (string) ($context['program_id'] ?? '-'),
            (string) $marker,
        ]);
    }
}
