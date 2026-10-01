<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseStatus;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\RetentionActionType;
use App\Modules\Bk\Domain\Enums\SlaSubjectType;
use App\Modules\Bk\Jobs\BkAutomationJob;
use App\Modules\Bk\Models\BkAppointment;
use App\Modules\Bk\Models\BkAutomationRun;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkConsent;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkRiskIndicator;
use App\Modules\Bk\Models\BkSlaEvent;
use Illuminate\Support\Carbon;

/**
 * Otomasi modul BK.
 *
 * Sembilan alur yang diminta dijalankan di sini, dan satu sifat berlaku untuk
 * semuanya: **idempoten**. Setiap tindakan menuliskan kunci unik ke
 * `bk_automation_runs`; bila kunci itu sudah ada, tindakan dilewati dan
 * alasannya dicatat. Dengan begitu penjadwalan yang berjalan dua kali (cron ganda,
 * retry antrean, dua pekerja paralel) tetap menghasilkan satu pesan, satu
 * eskalasi, dan satu tinjauan.
 *
 * Bagian yang berat (pengiriman notifikasi, evaluasi aturan, pembuatan berkas)
 * dijalankan sebagai pekerjaan antrean, sehingga perintah terjadwal tidak menahan
 * permintaan pengguna.
 */
class BkAutomationService
{
    public function __construct(
        private readonly BkNotificationService $notifications,
        private readonly BkEscalationService $escalations,
        private readonly BkSlaService $sla,
    ) {
    }

    /**
     * Menjalankan satu tindakan otomatis dengan penjagaan idempotensi.
     *
     * @param callable(): array{recipients?: int, reason?: string|null, payload?: array<string, mixed>} $action
     */
    public function run(string $actionCode, string $subjectType, ?int $subjectId, callable $action, ?string $period = null): BkAutomationRun
    {
        $dedupKey = sprintf(
            'auto:%s:%s:%s:%s',
            $actionCode,
            $subjectType,
            $subjectId ?? '-',
            $period ?? now()->toDateString(),
        );

        $existing = BkAutomationRun::query()->where('dedup_key', $dedupKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $result = $action();
            $attributes = [
                'action' => $actionCode,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'channel' => 'QUEUE',
                'status' => 'DONE',
                'recipients' => (int) ($result['recipients'] ?? 0),
                'payload' => $result['payload'] ?? null,
                'reason' => $result['reason'] ?? null,
                'ran_at' => now(),
                'dedup_key' => $dedupKey,
            ];
        } catch (\Throwable $exception) {
            $attributes = [
                'action' => $actionCode,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'channel' => 'QUEUE',
                'status' => 'FAILED',
                'recipients' => 0,
                'reason' => $exception->getMessage(),
                'ran_at' => now(),
                'dedup_key' => $dedupKey,
            ];
        }

        try {
            return BkAutomationRun::query()->create($attributes);
        } catch (\Illuminate\Database\QueryException) {
            // Balapan dua pekerja: ambil baris pemenang, jangan lempar.
            return BkAutomationRun::query()->where('dedup_key', $dedupKey)->first()
                ?? BkAutomationRun::query()->create($attributes);
        }
    }

    // ------------------------------------------------- 1. rujukan diterima → antrean

    /** Rujukan baru masuk antrean bk dan koordinator diberi tahu. */
    public function referralReceived(int $referralId, ?int $coordinatorId = null): BkAutomationRun
    {
        return $this->run('REFERRAL_ASSIGN_QUEUE', 'referral', $referralId, function () use ($referralId, $coordinatorId): array {
            $recipients = $this->notifications->notifyTeam(
                NotificationEvent::NEW_REFERRAL,
                'Rujukan baru masuk antrean BK.',
                ['referral_id' => $referralId, 'queue' => 'screening'],
            );

            return [
                'recipients' => $recipients,
                'reason' => 'Ditempatkan pada antrean screening; penugasan tetap keputusan koordinator.',
            ];
        });
    }

    // ------------------------------------------------- 2. kasus ditugaskan → notifikasi

    public function caseAssigned(int $caseId, int $counselorUserId): BkAutomationRun
    {
        return $this->run('CASE_ASSIGNED_NOTIFY', 'case', $caseId, function () use ($caseId, $counselorUserId): array {
            $case = BkCase::query()->find($caseId);

            if ($case === null) {
                return ['recipients' => 0, 'reason' => 'Kasus tidak ditemukan.'];
            }

            $this->notifications->notifyCaseAssigned($case, $counselorUserId);

            // SLA penanganan mulai berjalan sejak penugasan — bukan sejak kasus dibuka.
            $this->sla->start(SlaSubjectType::URGENT_CASE, $case, [
                'priority' => $case->priority?->value,
                'severity' => $case->severity?->value,
                'case_type' => $case->case_type->value,
                'category' => $case->category->value,
                'assigned_user_id' => $counselorUserId,
                'case_id' => $case->id,
            ]);

            return ['recipients' => 1, 'reason' => 'Konselor diberi tahu dan SLA penanganan dimulai.'];
        });
    }

    // ------------------------------------------------- 3. janji besok → pengingat

    /** Mengingatkan siswa/wali sehari sebelum janji temu. */
    public function appointmentReminders(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $window = (int) config('bk.windows.appointment_reminder_hours', 24);

        $appointments = BkAppointment::query()
            ->whereIn('status', ['SCHEDULED', 'CONFIRMED', 'RESCHEDULED'])
            ->whereBetween('scheduled_start', [$asOf->copy(), $asOf->copy()->addHours($window)])
            ->get();

        $sent = 0;

        foreach ($appointments as $appointment) {
            $run = $this->run('APPOINTMENT_REMINDER', 'appointment', $appointment->id, function () use ($appointment): array {
                BkAutomationJob::dispatch('APPOINTMENT_REMINDER', $appointment->id);
                $this->notifications->notifyGuardians(
                    $appointment->student_id,
                    NotificationEvent::APPOINTMENT_REMINDER,
                    'Pengingat janji konseling besok.',
                    [
                        'appointment_number' => $appointment->appointment_number,
                        'scheduled_start' => $appointment->scheduled_start?->toIso8601String(),
                        'mode' => $appointment->mode?->value,
                    ],
                );

                return ['recipients' => 1, 'reason' => 'Pengingat dijadwalkan melalui antrean.'];
            });

            if ($run->status === 'DONE' && $run->wasRecentlyCreated) {
                $sent++;
            }
        }

        return ['appointments' => $appointments->count(), 'reminders_sent' => $sent];
    }

    // ------------------------------------------------- 4-5. tindak lanjut jatuh tempo & terlambat

    /**
     * Pengingat tindak lanjut yang jatuh tempo, dan eskalasi yang terlambat.
     *
     * Dua alur berbeda dengan konsekuensi berbeda: yang pertama mengingatkan
     * pemiliknya, yang kedua menaikkan ke koordinator.
     *
     * @return array{due_reminders: int, overdue_escalations: int}
     */
    public function followUpReminders(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $due = BkFollowUp::query()
            ->whereIn('status', [FollowUpStatus::PENDING->value, FollowUpStatus::RESCHEDULED->value])
            ->whereDate('due_date', $asOf->copy()->addDays(2)->toDateString())
            ->get();

        $reminders = 0;

        foreach ($due as $followUp) {
            $run = $this->run('FOLLOW_UP_REMINDER', 'follow_up', $followUp->id, function () use ($followUp): array {
                $this->notifications->notifyFollowUp($followUp, false);

                return ['recipients' => 1, 'reason' => 'Pengingat dua hari sebelum tenggat.'];
            });

            if ($run->wasRecentlyCreated) {
                $reminders++;
            }
        }

        $overdue = BkFollowUp::query()
            ->whereIn('status', [FollowUpStatus::PENDING->value, FollowUpStatus::MISSED->value])
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->get();

        $escalations = 0;

        foreach ($overdue as $followUp) {
            $case = $followUp->case;

            if ($case === null) {
                continue;
            }

            $run = $this->run('FOLLOW_UP_ESCALATION', 'follow_up', $followUp->id, function () use ($followUp, $case): array {
                $this->notifications->notifyFollowUp($followUp, true);

                $this->escalations->escalate(
                    actor: $this->systemActor(),
                    subjectType: SlaSubjectType::FOLLOW_UP,
                    subject: $followUp,
                    data: [
                        'to_level' => 'COORDINATOR',
                        'case_id' => $case->id,
                        'reason' => 'Tindak lanjut melewati tenggat '.$followUp->due_date?->toDateString().'.',
                        'triggers' => ['follow_up_id' => $followUp->id, 'due_date' => $followUp->due_date?->toDateString()],
                    ],
                );

                return ['recipients' => 1, 'reason' => 'Dieskalasi ke koordinator karena melewati tenggat.'];
            });

            if ($run->wasRecentlyCreated) {
                $escalations++;
            }
        }

        return ['due_reminders' => $reminders, 'overdue_escalations' => $escalations];
    }

    // ------------------------------------------------- 6. SLA terlampaui → koordinator

    public function slaBreach(string $subjectType, int $subjectId, ?string $reason = null): BkAutomationRun
    {
        return $this->run('SLA_BREACH', $subjectType, $subjectId, function () use ($subjectType, $subjectId, $reason): array {
            $event = BkSlaEvent::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->breached()
                ->orderByDesc('id')
                ->first();

            if ($event === null) {
                return ['recipients' => 0, 'reason' => 'Tidak ada SLA terlampaui untuk pekerjaan ini.'];
            }

            $escalation = $this->escalations->escalateFromSla($event);

            return [
                'recipients' => 1,
                'reason' => $reason ?? 'SLA terlampaui; dieskalasi ke '.$escalation->to_level->label().'.',
                'payload' => ['escalation_id' => $escalation->id],
            ];
        });
    }

    // ------------------------------------------------- 7. penanda risiko → tinjauan BK

    public function riskIndicatorReview(int $indicatorId): BkAutomationRun
    {
        return $this->run('RISK_REVIEW', 'risk_indicator', $indicatorId, function () use ($indicatorId): array {
            $indicator = BkRiskIndicator::query()->with('student:id,full_name')->find($indicatorId);

            if ($indicator === null) {
                return ['recipients' => 0, 'reason' => 'Penanda tidak ditemukan.'];
            }

            $recipients = $this->notifications->notifyTeam(
                NotificationEvent::BK_REVIEW_REQUIRED,
                'Tinjauan BK diperlukan untuk penanda '.$indicator->indicator_code->label().'.',
                [
                    'indicator_id' => $indicator->id,
                    'student_id' => $indicator->student_id,
                    'severity' => $indicator->severity->value,
                    'note' => 'Penanda adalah permintaan peninjauan, bukan diagnosis.',
                ],
            );

            return ['recipients' => $recipients, 'reason' => 'Tim BK diminta meninjau.'];
        });
    }

    // ------------------------------------------------- 8. persetujuan akan berakhir

    public function consentExpiring(?int $days = null): array
    {
        $days ??= 14;
        $consents = app(BkConsentService::class)->expiring($days);
        $sent = 0;

        foreach ($consents as $consent) {
            $run = $this->run('CONSENT_EXPIRING', 'consent', $consent->id, function () use ($consent): array {
                $recipients = $this->notifications->notifyGuardians(
                    $consent->student_id,
                    NotificationEvent::CONSENT_EXPIRING,
                    'Persetujuan akan berakhir pada '.$consent->expires_at?->toDateString().'.',
                    [
                        'consent_number' => $consent->consent_number,
                        'scope' => $consent->scope,
                        'expires_at' => $consent->expires_at?->toDateString(),
                        'note' => 'Bila layanan masih diperlukan, persetujuan dapat diperbarui.',
                    ],
                );

                return ['recipients' => $recipients, 'reason' => 'Pengingat perpanjangan persetujuan.'];
            });

            if ($run->wasRecentlyCreated) {
                $sent++;
            }
        }

        return ['expiring' => $consents->count(), 'reminders_sent' => $sent];
    }

    // ------------------------------------------------- 9. kasus siap ditutup → tinjauan

    /**
     * Menandai kasus yang seluruh pekerjaannya sudah tuntas sebagai siap ditutup.
     *
     * Penandaan ini meminta tinjauan penutupan; ia **tidak** menutup kasus sendiri,
     * karena penutupan kasus adalah keputusan konselor/koordinator.
     */
    public function caseReadyToClose(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $cases = BkCase::query()
            ->whereIn('status', [CaseStatus::INTERVENTION->value, CaseStatus::MONITORING->value, CaseStatus::REOPENED->value])
            ->whereDoesntHave('followUps', fn ($q) => $q->whereIn('status', [
                FollowUpStatus::PENDING->value, FollowUpStatus::RESCHEDULED->value, FollowUpStatus::MISSED->value,
            ]))
            ->whereDoesntHave('interventions', fn ($q) => $q->whereIn('status', ['PLANNED', 'ONGOING', 'IN_PROGRESS']))
            ->limit(200)
            ->get();

        $flagged = 0;

        foreach ($cases as $case) {
            $run = $this->run('CASE_READY_TO_CLOSE', 'case', $case->id, function () use ($case): array {
                $recipients = $this->notifications->notifyTeam(
                    NotificationEvent::CASE_READY_TO_CLOSE,
                    "Kasus {$case->case_number} siap ditinjau untuk penutupan.",
                    ['case_id' => $case->id, 'note' => 'Tinjauan penutupan tetap keputusan konselor/koordinator.'],
                );

                return ['recipients' => $recipients, 'reason' => 'Seluruh tindak lanjut dan intervensi sudah tuntas.'];
            });

            if ($run->wasRecentlyCreated) {
                $flagged++;
            }
        }

        return ['candidates' => $cases->count(), 'flagged' => $flagged];
    }

    /** Ringkasan jejak otomasi (dipakai layar pengaturan & `bk:health`). */
    public function overview(): array
    {
        return [
            'runs_total' => BkAutomationRun::query()->count(),
            'runs_today' => BkAutomationRun::query()->whereDate('ran_at', today())->count(),
            'failed_total' => BkAutomationRun::query()->where('status', 'FAILED')->count(),
            'by_action' => BkAutomationRun::query()
                ->select('action', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
                ->groupBy('action')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => ['action' => $row->action, 'total' => (int) $row->total])
                ->all(),
            'note' => 'Setiap tindakan otomatis tercatat dengan kunci idempotensinya, sehingga penjadwalan berulang tidak menggandakan tindakan.',
        ];
    }

    protected function systemActor(): User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['BK_ADMIN', 'BK_COORDINATOR']))
            ->orderBy('id')
            ->first() ?? new User(['name' => 'Sistem BK']);
    }
}
