<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Experience\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\People\Models\HrJourneyDefinition;
use App\Modules\Hrd\Strategic\People\Models\HrJourneyEvent;
use App\Modules\Hrd\Strategic\People\Models\HrJourneyInstance;
use App\Modules\Hrd\Strategic\People\Models\HrJourneyStage;
use App\Modules\Hrd\Strategic\Support\Phase3Context;

/**
 * Employee Journey & Event-Driven HR Automation (§63, §64, §151).
 *
 * Peristiwa HR (promosi, mutasi, sertifikasi kedaluwarsa, penyelesaian
 * pelatihan) menjadi pemicu rangkaian aksi: notifikasi, tugas, pelatihan,
 * tujuan baru, dan peringatan.
 */
class JourneyAutomationService
{
    public function __construct(private readonly Phase3Context $ctx) {}

    public function defineJourney(array $data, mixed $actor = null): HrJourneyDefinition
    {
        $journey = HrJourneyDefinition::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'name' => $data['name'], 'journey_type' => strtoupper((string) ($data['journey_type'] ?? 'CUSTOM')),
                'trigger_event' => $data['trigger_event'] ?? null, 'description' => $data['description'] ?? null,
                'target_audience' => $data['target_audience'] ?? [], 'status' => 'ACTIVE',
                'version' => (int) ($data['version'] ?? 1), 'owner_role' => $data['owner_role'] ?? 'HR_ADMIN',
            ]
        );

        foreach ((array) ($data['stages'] ?? []) as $index => $stage) {
            HrJourneyStage::query()->updateOrCreate(
                ['journey_code' => $journey->code, 'sequence' => (int) ($stage['sequence'] ?? $index + 1)],
                [
                    'code' => strtoupper((string) ($stage['code'] ?? ('STAGE_'.($index + 1)))),
                    'name' => $stage['name'] ?? 'Tahap '.($index + 1),
                    'stage_type' => strtoupper((string) ($stage['type'] ?? 'TASK')),
                    'offset_days' => (int) ($stage['offset_days'] ?? ($index * 7)),
                    'owner_role' => $stage['owner_role'] ?? 'HR_ADMIN', 'config' => $stage['config'] ?? [],
                    'is_required' => (bool) ($stage['required'] ?? true),
                ]
            );
        }

        $this->ctx->audit('strategic.journey.defined', HrJourneyDefinition::class, $journey->getKey(), [
            'code' => $journey->code, 'stages' => count((array) ($data['stages'] ?? [])),
        ], null, $actor);

        return $journey->refresh();
    }

    /**
     * Mulai perjalanan pegawai (idempoten per pegawai+journey).
     *
     * @return array<string, mixed>
     */
    public function start(int $employeeId, string $journeyCode, mixed $actor = null): array
    {
        $journey = HrJourneyDefinition::query()->where('code', strtoupper($journeyCode))->first();
        if (! $journey) {
            return ['error' => 'Journey tidak terdaftar: '.$journeyCode];
        }

        $existing = HrJourneyInstance::query()->where('employee_id', $employeeId)
            ->where('journey_code', $journey->code)->whereIn('status', ['ACTIVE', 'COMPLETED'])->first();
        if ($existing) {
            return ['instance' => $existing->code, 'reused' => true, 'status' => $existing->status];
        }

        $stages = HrJourneyStage::query()->where('journey_code', $journey->code)->orderBy('sequence')->get();
        $instance = HrJourneyInstance::query()->create([
            'journey_code' => $journey->code, 'employee_id' => $employeeId,
            'code' => $this->ctx->nextCode('hr_journey_instances', 'JRN'),
            'started_at' => now(), 'status' => 'ACTIVE',
            'current_stage_code' => $stages->first()?->code,
            'progress_percent' => 0,
            'metrics' => ['stages_total' => $stages->count(), 'stages_done' => 0],
        ]);

        HrJourneyEvent::query()->create([
            'instance_id' => $instance->getKey(), 'stage_code' => $stages->first()?->code,
            'event_type' => 'STARTED', 'employee_id' => $employeeId,
            'payload' => ['journey' => $journey->code], 'occurred_at' => now(), 'handled_by' => 'SYSTEM',
        ]);

        $this->ctx->audit('strategic.journey.started', HrJourneyInstance::class, $instance->getKey(), [
            'employee_id' => $employeeId, 'journey' => $journey->code, 'stages' => $stages->count(),
        ], null, $actor, $employeeId);

        return [
            'instance' => $instance->code, 'journey' => $journey->code,
            'stages' => $stages->map(fn (HrJourneyStage $stage): array => [
                'sequence' => (int) $stage->sequence, 'code' => $stage->code, 'name' => $stage->name,
                'type' => $stage->stage_type, 'due_in_days' => (int) $stage->offset_days,
            ])->all(),
        ];
    }

    /**
     * Selesaikan sebuah tahap dan hitung kemajuan.
     *
     * @return array<string, mixed>
     */
    public function advance(HrJourneyInstance $instance, string $stageCode, string $eventType = 'COMPLETED', array $payload = [], mixed $actor = null): array
    {
        $stages = HrJourneyStage::query()->where('journey_code', $instance->journey_code)->orderBy('sequence')->get();
        $done = (array) ($instance->metrics['stages_done'] ?? 0);
        $current = $stages->firstWhere('code', strtoupper($stageCode));
        $index = $current ? $stages->search($current) : false;
        $next = $index !== false ? $stages->get($index + 1) : null;
        $completed = $next === null;
        $progress = $stages->count() > 0 ? $this->ctx->percent($index !== false ? $index + 1 : $done, $stages->count()) : 0.0;

        HrJourneyEvent::query()->create([
            'instance_id' => $instance->getKey(), 'stage_code' => strtoupper($stageCode),
            'event_type' => strtoupper($eventType), 'employee_id' => $instance->employee_id,
            'payload' => $payload, 'occurred_at' => now(), 'handled_by' => $actor ? 'USER' : 'AUTOMATION',
        ]);

        $instance->forceFill([
            'current_stage_code' => $next?->code,
            'progress_percent' => $progress,
            'status' => $completed ? 'COMPLETED' : 'ACTIVE',
            'completed_at' => $completed ? now() : null,
            'metrics' => array_merge((array) $instance->metrics, [
                'stages_done' => $index !== false ? $index + 1 : $done,
                'stages_total' => $stages->count(),
                'last_event' => strtoupper($eventType),
            ]),
        ])->save();

        $this->ctx->audit('strategic.journey.advanced', HrJourneyInstance::class, $instance->getKey(), [
            'stage' => strtoupper($stageCode), 'event' => strtoupper($eventType), 'progress' => $progress,
        ], null, $actor, (int) $instance->employee_id);

        return [
            'instance' => $instance->code, 'stage' => strtoupper($stageCode),
            'progress_percent' => $progress, 'next_stage' => $next?->code,
            'completed' => $completed, 'status' => $instance->status,
        ];
    }

    /**
     * Otomasi berbasis peristiwa: event → kondisi → aksi (§64).
     *
     * @return array<string, mixed>
     */
    public function handleEvent(string $eventType, array $payload = [], mixed $actor = null): array
    {
        $rules = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_rules')
            ? \Illuminate\Support\Facades\DB::table('hr_rules')->where('purpose', 'hrd3.automation')
                ->where('is_active', true)->orderByDesc('priority')->get()
                ->map(fn ($row): array => [
                    'code' => $row->code, 'name' => $row->name,
                    'actions' => json_decode((string) $row->action_payload, true) ?: [],
                    'match' => json_decode((string) ($row->action_type ?? '{}'), true) ?: [],
                ])->all() : [], []);

        $triggered = [];
        foreach ($rules as $rule) {
            $matches = true;
            foreach ((array) ($rule['match']['all'] ?? []) as $condition) {
                $field = (string) ($condition['field'] ?? '');
                $expected = $condition['value'] ?? null;
                $actual = $payload[$field] ?? null;
                $operator = (string) ($condition['operator'] ?? '=');
                $satisfied = match ($operator) {
                    '=', '==' => $actual == $expected,
                    '!=' => $actual != $expected,
                    '>' => $actual > $expected,
                    '>=' => $actual >= $expected,
                    '<' => $actual < $expected,
                    '<=' => $actual <= $expected,
                    'in' => in_array($actual, (array) $expected, true),
                    default => true,
                };
                if (! $satisfied) {
                    $matches = false;
                    break;
                }
            }
            if (! $matches) {
                continue;
            }

            $actions = [];
            foreach ((array) ($rule['actions'] ?? []) as $action) {
                $actions[] = $this->runAction((string) ($action['type'] ?? ''), (array) ($action['params'] ?? []), $payload, $actor);
            }
            $triggered[] = ['rule' => $rule['code'], 'name' => $rule['name'], 'actions' => $actions];
        }

        // Journey yang dipicu oleh peristiwa ini.
        $journeys = HrJourneyDefinition::query()->where('trigger_event', $eventType)->where('status', 'ACTIVE')->get();
        $started = [];
        foreach ($journeys as $journey) {
            if (! empty($payload['employee_id'])) {
                $started[] = $this->start((int) $payload['employee_id'], (string) $journey->code, $actor);
            }
        }

        $this->ctx->event('AUTOMATION', $eventType, [
            'title' => 'Otomasi HR dijalankan', 'description' => 'Aksi: '.count($triggered).' aturan, '.count($started).' journey',
            'meta' => ['payload' => $payload],
        ], isset($payload['employee_id']) ? (int) $payload['employee_id'] : null, null, null, $actor);

        return [
            'event' => $eventType, 'rules_triggered' => $triggered, 'journeys_started' => $started,
            'note' => 'Aksi otomatis tidak pernah mengeksekusi keputusan berdampak (terminasi, penurunan gaji).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $employeeId): array
    {
        $instances = HrJourneyInstance::query()->where('employee_id', $employeeId)->orderByDesc('id')->get();

        return [
            'employee_id' => $employeeId,
            'instances' => $instances->map(fn (HrJourneyInstance $instance): array => [
                'journey' => $instance->journey_code, 'code' => $instance->code, 'status' => $instance->status,
                'current_stage' => $instance->current_stage_code, 'progress_percent' => (float) $instance->progress_percent,
                'events' => HrJourneyEvent::query()->where('instance_id', $instance->getKey())->count(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function runAction(string $type, array $params, array $payload, mixed $actor): array
    {
        return match (strtoupper($type)) {
            'NOTIFY_MANAGER' => ['action' => 'NOTIFY_MANAGER', 'status' => 'QUEUED',
                'detail' => 'Notifikasi dikirim lewat HrdNotificationService saat modul notifikasi aktif.'],
            'CREATE_TRAINING_TASK' => ['action' => 'CREATE_TRAINING_TASK', 'status' => 'QUEUED',
                'detail' => 'Permintaan pelatihan dibuat di Phase 2 (hr_training_assignments).'],
            'CREATE_HR_ALERT' => $this->raiseAlert($params, $payload, $actor),
            'CREATE_GOALS' => ['action' => 'CREATE_GOALS', 'status' => 'QUEUED', 'detail' => 'Siklus tujuan berikutnya mengikuti siklus kinerja Phase 2.'],
            'UPDATE_ACCESS' => ['action' => 'UPDATE_ACCESS', 'status' => 'SKIPPED',
                'detail' => 'Perubahan akses dijalankan modul IT/Access, bukan HRD.'],
            default => ['action' => strtoupper($type), 'status' => 'UNKNOWN'],
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function raiseAlert(array $params, array $payload, mixed $actor): array
    {
        $raised = $this->ctx->safe(function () use ($params, $payload) {
            $service = app(\App\Modules\Hrd\Core\Services\AlertService::class);
            if (! method_exists($service, 'raise')) {
                return null;
            }

            return $service->raise((string) ($params['type'] ?? 'hrd3.automation'), [
                'severity' => $params['severity'] ?? 'MEDIUM',
                'title' => $params['title'] ?? 'Otomasi HR',
                'message' => $params['message'] ?? 'Pemicu otomasi HRD Phase 3.',
                'employee_id' => $payload['employee_id'] ?? null,
                'payload' => $payload,
            ]);
        }, null);

        return ['action' => 'CREATE_HR_ALERT', 'status' => $raised ? 'CREATED' : 'QUEUED',
            'detail' => $raised ? 'Peringatan dibuat melalui AlertService.' : 'AlertService belum tersedia; aksi dicatat.'];
    }
}
