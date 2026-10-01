<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Enums\MaintenanceFrequency;
use App\Modules\Asset\Domain\Enums\WorkOrderStatus;
use App\Modules\Asset\Domain\Enums\WorkOrderType;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\MaintenanceSchedule;
use App\Modules\Asset\Models\MaintenanceWorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pemeliharaan preventif: jadwal berkala yang berubah menjadi work order.
 *
 * Generator ini IDEMPOTEN dengan sengaja: satu jadwal hanya melahirkan satu work
 * order per jatuh tempo (`last_work_order_id` + tanggalnya). Perintah terjadwal
 * yang dijalankan dua kali karena percobaan ulang antrean tidak boleh menggandakan
 * pekerjaan — teknisi yang menerima dua tiket untuk satu AC akan berhenti
 * mempercayai sistemnya.
 */
class PreventiveMaintenanceService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
    ) {}

    public function createSchedule(User $actor, array $data): MaintenanceSchedule
    {
        $frequency = MaintenanceFrequency::from((string) ($data['frequency'] ?? MaintenanceFrequency::DAYS_90->value));
        $interval = (int) ($data['interval_days'] ?? $frequency->intervalDays() ?? 90);

        return MaintenanceSchedule::query()->create([
            'asset_id' => $data['asset_id'] ?? null,
            'facility_id' => $data['facility_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'frequency' => $frequency->value,
            'interval_days' => $interval,
            'checklist' => $data['checklist'] ?? null,
            'next_due_at' => $data['next_due_at'] ?? now()->addDays($interval),
            'assigned_role' => $data['assigned_role'] ?? 'MAINTENANCE_MANAGER',
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Buat jadwal bawaan untuk aset baru (dari kebijakan kategorinya).
     *
     * Ini yang membuat pemeliharaan preventif benar-benar "berjalan": jadwalnya
     * lahir bersama asetnya, bukan disetel manual setelah alatnya rusak.
     */
    public function ensureSchedulesForAsset(User $actor, Asset $asset): array
    {
        $category = $asset->category;

        if ($category === null) {
            return [];
        }

        $policy = (array) ($category->maintenance_policy ?? []);
        $frequency = MaintenanceFrequency::tryFrom((string) ($policy['frequency'] ?? ''))
            ?? ($category->inspection_frequency_days !== null
                ? MaintenanceFrequency::CUSTOM
                : null);

        if ($frequency === null) {
            return [];
        }

        $interval = (int) ($policy['interval_days'] ?? $category->inspection_frequency_days ?? 90);

        $exists = MaintenanceSchedule::query()
            ->where('asset_id', $asset->id)
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            return [];
        }

        $schedule = $this->createSchedule($actor, [
            'asset_id' => $asset->id,
            'category_id' => $category->id,
            'title' => 'Perawatan berkala '.$asset->name,
            'frequency' => $frequency === MaintenanceFrequency::CUSTOM ? MaintenanceFrequency::CUSTOM->value : $frequency->value,
            'interval_days' => $interval,
            'checklist' => $policy['checklist'] ?? null,
            'next_due_at' => now()->addDays($interval),
        ]);

        return [$schedule];
    }

    /**
     * Jalankan jadwal yang jatuh tempo.
     *
     * @return array{generated:list<string>, skipped:list<string>}
     */
    public function generateDue(User $actor, ?Carbon $now = null): array
    {
        $now ??= now();

        $generated = [];
        $skipped = [];

        $schedules = MaintenanceSchedule::query()
            ->where('is_active', true)
            ->where('next_due_at', '<=', $now)
            ->orderBy('next_due_at')
            ->get();

        foreach ($schedules as $schedule) {
            $already = MaintenanceWorkOrder::query()
                ->where('status', '!=', WorkOrderStatus::CANCELLED->value)
                ->where(function ($query) use ($schedule): void {
                    $query->where('id', $schedule->last_work_order_id);
                })
                ->exists();

            if ($already) {
                $skipped[] = $schedule->title;

                continue;
            }

            $workOrder = DB::transaction(function () use ($actor, $schedule, $now): MaintenanceWorkOrder {
                $workOrder = MaintenanceWorkOrder::query()->create([
                    'work_order_number' => $this->numbers->next('work_order', ['tahun' => $now->year]),
                    'asset_id' => $schedule->asset_id,
                    'facility_id' => $schedule->facility_id,
                    'type' => WorkOrderType::PREVENTIVE->value,
                    'status' => WorkOrderStatus::OPEN->value,
                    'priority' => 'NORMAL',
                    'title' => 'Preventif: '.$schedule->title,
                    'description' => 'Dibuat otomatis dari jadwal pemeliharaan preventif.',
                    'due_at' => $now->copy()->addDays(7),
                ]);

                foreach ((array) ($schedule->checklist ?? []) as $index => $item) {
                    $workOrder->tasks()->create([
                        'sequence' => $index + 1,
                        'title' => is_array($item) ? ($item['label'] ?? 'Langkah '.($index + 1)) : (string) $item,
                        'status' => 'PENDING',
                    ]);
                }

                $schedule->forceFill([
                    'last_run_at' => $now,
                    'last_work_order_id' => $workOrder->id,
                    'last_notified_at' => $now,
                    'next_due_at' => $this->nextDue($schedule, $now),
                ])->save();

                $this->notifications->notify(
                    [],
                    ['MAINTENANCE_MANAGER', $schedule->assigned_role ?? 'MAINTENANCE_MANAGER'],
                    'MAINTENANCE_DUE',
                    'Pemeliharaan preventif jatuh tempo: '.$schedule->title,
                    'Work order '.$workOrder->work_order_number.' dibuat otomatis.',
                    $workOrder,
                );

                $this->audit->record(\App\Modules\Asset\Domain\Enums\AuditAction::MAINTENANCE_REQUESTED, $workOrder, $actor, [
                    'status_after' => $workOrder->status,
                    'reason' => 'Jadwal preventif jatuh tempo.',
                    'context' => ['schedule_id' => $schedule->id, 'due_at' => $schedule->next_due_at?->toDateTimeString()],
                ]);

                return $workOrder;
            });

            $generated[] = $workOrder->work_order_number;
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /** Seluruh jadwal yang akan jatuh tempo dalam N hari (untuk pengingat & layar). */
    public function dueSoon(int $days = 7): array
    {
        return MaintenanceSchedule::query()
            ->with(['asset:id,asset_code,name', 'facility:id,facility_code,name'])
            ->where('is_active', true)
            ->whereBetween('next_due_at', [now(), now()->addDays($days)])
            ->orderBy('next_due_at')
            ->get()
            ->map(fn (MaintenanceSchedule $schedule): array => [
                'id' => $schedule->id,
                'title' => $schedule->title,
                'asset' => $schedule->asset?->only(['id', 'asset_code', 'name']),
                'facility' => $schedule->facility?->only(['id', 'facility_code', 'name']),
                'next_due_at' => $schedule->next_due_at?->toDateTimeString(),
            ])
            ->all();
    }

    protected function nextDue(MaintenanceSchedule $schedule, Carbon $from): Carbon
    {
        $interval = (int) ($schedule->interval_days ?? 90);

        if ($interval < 1) {
            $interval = 90;
        }

        $next = $from->copy();

        do {
            $next = $next->copy()->addDays($interval);
        } while ($next->isPast());

        return $next;
    }
}
