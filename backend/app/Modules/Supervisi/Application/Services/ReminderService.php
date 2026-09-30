<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use App\Modules\Supervisi\Models\SupActionItem;
use App\Modules\Supervisi\Models\SupCoachingSession;
use App\Modules\Supervisi\Models\SupPipPlan;
use App\Modules\Supervisi\Models\SupVisit;
use App\Models\Employee;
use App\Modules\Supervisi\Notifications\SupReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Pengingat terjadwal supervisi (Fase 4).
 *
 * Perintah `supervisi:remind` menyapu lima hal yang paling mudah terlupakan:
 *   1. kegiatan terjadwal yang belum diamati,
 *   2. kegiatan selesai yang belum difinalkan,
 *   3. butir RTL jatuh tempo/terlambat,
 *   4. tindak lanjut coaching yang jatuh tempo,
 *   5. tinjauan PIP yang tertunda.
 *
 * Idempoten: satu (penerima, jenis, kunci) hanya dinotifikasi sekali per hari,
 * sehingga penjadwal boleh berjalan dua kali tanpa membanjiri kotak masuk.
 */
class ReminderService
{
    public const TYPE_VISIT_SCHEDULED = 'supervisi.visit.scheduled';

    public const TYPE_VISIT_UNFINALIZED = 'supervisi.visit.unfinalized';

    public const TYPE_ACTION_ITEM_DUE = 'supervisi.action_item.due';

    public const TYPE_COACHING_DUE = 'supervisi.coaching.due';

    public const TYPE_PIP_REVIEW_DUE = 'supervisi.pip.review_due';

    /** @return array<string, int> jumlah pengingat per jenis */
    public function run(?Carbon $today = null, bool $dryRun = false): array
    {
        $today = ($today ?? now())->startOfDay();
        $counts = [
            self::TYPE_VISIT_SCHEDULED => 0,
            self::TYPE_VISIT_UNFINALIZED => 0,
            self::TYPE_ACTION_ITEM_DUE => 0,
            self::TYPE_COACHING_DUE => 0,
            self::TYPE_PIP_REVIEW_DUE => 0,
        ];

        foreach ($this->visitReminders($today) as $type => $reminders) {
            $counts[$type] = $this->dispatch($reminders, $type, $today, $dryRun);
        }

        $counts[self::TYPE_ACTION_ITEM_DUE] = $this->dispatch($this->actionItemReminders($today), self::TYPE_ACTION_ITEM_DUE, $today, $dryRun);
        $counts[self::TYPE_COACHING_DUE] = $this->dispatch($this->coachingReminders($today), self::TYPE_COACHING_DUE, $today, $dryRun);
        $counts[self::TYPE_PIP_REVIEW_DUE] = $this->dispatch($this->pipReminders($today), self::TYPE_PIP_REVIEW_DUE, $today, $dryRun);

        return $counts;
    }

    /**
     * @return array{supervisi.visit.scheduled: list<array<string,mixed>>, supervisi.visit.unfinalized: list<array<string,mixed>>}
     */
    protected function visitReminders(Carbon $today): array
    {
        $scheduled = SupVisit::query()
            ->with(['supervisor:id,full_name', 'supervisee:id,full_name'])
            ->whereNotNull('observed_at')
            ->whereBetween('observed_at', [$today, $today->copy()->addDays(3)->endOfDay()])
            ->whereIn('status', [VisitStatus::SCHEDULED->value, VisitStatus::PRE_CONFERENCE->value])
            ->get()
            ->map(fn (SupVisit $visit) => [
                'user_ids' => $this->userIdsFor([$visit->supervisor_employee_id]),
                'title' => 'Kegiatan '.$visit->code.' dijadwalkan '.$visit->observed_at?->translatedFormat('d M Y H:i'),
                'body' => 'Supervisi '.$visit->supervisee?->full_name.' ('.(string) $visit->activity_kind->label().') menunggu pelaksanaan.',
                'url' => '/supervisi/visits/'.$visit->id,
                'key' => 'visit-scheduled-'.$visit->id,
                'payload' => ['visit_id' => $visit->id, 'code' => $visit->code],
            ])
            ->all();

        $unfinalized = SupVisit::query()
            ->with(['supervisor:id,full_name', 'supervisee:id,full_name'])
            ->whereNotNull('observed_at')
            ->where('observed_at', '<', $today)
            ->whereNotIn('status', [VisitStatus::COMPLETED->value, VisitStatus::CANCELLED->value, VisitStatus::DISPUTED->value])
            ->get()
            ->map(fn (SupVisit $visit) => [
                'user_ids' => $this->userIdsFor([$visit->supervisor_employee_id]),
                'title' => 'Kegiatan '.$visit->code.' belum difinalkan',
                'body' => 'Observasi '.$visit->supervisee?->full_name.' pada '
                    .$visit->observed_at?->translatedFormat('d M Y')
                    .' masih draf; nilai belum dapat dipakai untuk pembinaan.',
                'url' => '/supervisi/visits/'.$visit->id,
                'key' => 'visit-unfinalized-'.$visit->id,
                'payload' => ['visit_id' => $visit->id, 'code' => $visit->code],
            ])
            ->all();

        return [
            self::TYPE_VISIT_SCHEDULED => $scheduled,
            self::TYPE_VISIT_UNFINALIZED => $unfinalized,
        ];
    }

    /** @return list<array<string,mixed>> */
    protected function actionItemReminders(Carbon $today): array
    {
        $items = SupActionItem::query()
            ->with(['plan:id,title,supervisee_employee_id,created_by'])
            ->whereNotIn('status', ['DONE', 'VERIFIED', 'DROPPED'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->copy()->addDays(3)->toDateString())
            ->get();

        return $items->map(function (SupActionItem $item) use ($today) {
            $overdue = $item->due_date->lessThan($today);
            // Penerima: penanggung jawab butir, pegawai yang dibina, dan
            // pencatat RTL (atasan) — ketiganya sedang menunggu pekerjaan ini.
            $recipients = array_unique(array_filter([
                ...$this->userIdsFor([$item->owner_employee_id, $item->plan?->supervisee_employee_id]),
                $item->plan?->created_by,
            ]));

            return [
                'user_ids' => $recipients,
                'title' => ($overdue ? 'Terlambat: ' : 'Segera jatuh tempo: ').mb_substr((string) $item->statement, 0, 60),
                'body' => ($overdue ? 'Tenggat '.$item->due_date->translatedFormat('d M Y').' sudah lewat. ' : 'Tenggat '.$item->due_date->translatedFormat('d M Y').'. ')
                    .'Kemajuan saat ini '.$item->progress_percent.'%.',
                'url' => '/supervisi/action-plans',
                'key' => 'action-item-'.($overdue ? 'overdue' : 'due').'-'.$item->id,
                'payload' => ['action_item_id' => $item->id, 'due_date' => $item->due_date->toDateString(), 'overdue' => $overdue],
            ];
        })->all();
    }

    /** @return list<array<string,mixed>> */
    protected function coachingReminders(Carbon $today): array
    {
        return SupCoachingSession::query()
            ->with(['supervisor:id,full_name', 'supervisee:id,full_name'])
            ->where('status', 'OPEN')
            ->whereNotNull('follow_up_at')
            ->whereDate('follow_up_at', '<=', $today->toDateString())
            ->get()
            ->map(fn (SupCoachingSession $session) => [
                'user_ids' => $this->userIdsFor([$session->supervisor_employee_id]),
                'title' => 'Tindak lanjut coaching '.$session->supervisee?->full_name,
                'body' => 'Sesi '.$session->session_at?->translatedFormat('d M Y').' memiliki komitmen yang belum ditutup.',
                'url' => '/supervisi/growth',
                'key' => 'coaching-'.$session->id,
                'payload' => ['coaching_id' => $session->id],
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    protected function pipReminders(Carbon $today): array
    {
        return SupPipPlan::query()
            ->with(['supervisee:id,full_name'])
            ->where('status', 'ACTIVE')
            ->whereNotNull('final_review_date')
            ->whereDate('final_review_date', '<=', $today->copy()->addDays(7)->toDateString())
            ->get()
            ->map(fn (SupPipPlan $plan) => [
                // PIP tidak menyimpan kolom supervisor; pencatatnya (created_by)
                // adalah pengelola yang harus menindaklanjuti tinjauan.
                'user_ids' => array_values(array_filter([$plan->created_by])),
                'title' => 'Tinjauan PIP '.$plan->supervisee?->full_name.' mendekati tenggat',
                'body' => 'Tinjauan akhir '.$plan->final_review_date?->translatedFormat('d M Y')
                    .'; status butir: '.$plan->items()->whereIn('status', ['DONE', 'VERIFIED'])->count()
                    .'/'.$plan->items()->count().' selesai.',
                'url' => '/supervisi/growth',
                'key' => 'pip-review-'.$plan->id,
                'payload' => ['pip_id' => $plan->id],
            ])->all();
    }

    /**
     * Kirim pengingat, lewati yang sudah dikirim hari ini.
     *
     * @param  list<array<string,mixed>>  $reminders
     */
    protected function dispatch(array $reminders, string $type, Carbon $today, bool $dryRun): int
    {
        $sent = 0;

        foreach ($reminders as $reminder) {
            $userIds = array_values(array_unique(array_filter($reminder['user_ids'] ?? [])));

            if ($userIds === []) {
                continue;
            }

            $already = $this->alreadySent($type, $reminder['key'], $userIds, $today);

            if ($already >= count($userIds)) {
                continue;
            }

            if ($dryRun) {
                $sent += count($userIds) - $already;

                continue;
            }

            $users = User::query()->whereIn('id', $userIds)->get();

            Notification::send($users, new SupReminderNotification([
                'title' => $reminder['title'],
                'body' => $reminder['body'],
                'url' => $reminder['url'],
                'type' => $type,
                'key' => $reminder['key'],
                'payload' => $reminder['payload'] ?? [],
            ]));

            $sent += $users->count();
        }

        return $sent;
    }

    /** Berapa penerima yang sudah menerima pengingat dengan kunci ini hari ini. */
    protected function alreadySent(string $type, string $key, array $userIds, Carbon $today): int
    {
        return (int) DB::table('notifications')
            ->where('type', SupReminderNotification::class)
            ->whereIn('notifiable_id', $userIds)
            ->whereDate('created_at', $today->toDateString())
            ->where('data', 'like', '%"key":"'.$key.'"%')
            ->count();
    }

    /**
     * Ubah daftar employee_id menjadi daftar user_id yang aktif.
     *
     * @param  list<int|null>  $employeeIds
     * @return list<int>
     */
    protected function userIdsFor(array $employeeIds): array
    {
        $ids = array_values(array_filter($employeeIds, fn ($id) => $id !== null));

        if ($ids === []) {
            return [];
        }

        // Akun terhubung polimorfik: users.userable_type = Employee.
        return DB::table('users')
            ->where('userable_type', Employee::class)
            ->whereIn('userable_id', $ids)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();
    }
}
