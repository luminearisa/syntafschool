<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Support\EmployeeNumberGenerator;
use App\Modules\Hrd\Application\Support\IntegrationOutbox;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOffboardingProcess;
use App\Modules\Hrd\Models\HrOffboardingTask;
use App\Modules\Hrd\Models\HrOffboardingTemplate;
use App\Modules\Hrd\Models\HrOnboardingProcess;
use App\Modules\Hrd\Models\HrOnboardingTask;
use App\Modules\Hrd\Models\HrOnboardingTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding, pre-boarding, dan offboarding (§38–§40).
 *
 * Kedua alur memakai mesin yang sama — template → proses → tugas — karena
 * keduanya sebenarnya bentuk yang sama: "serangkaian kewajiban berpenanggung jawab
 * yang harus diselesaikan sebelum seorang pegawai boleh berpindah keadaan".
 *
 * Template dipilih berurutan (worker type → posisi → unit → lokasi, dengan
 * `priority` sebagai penentu), sehingga guru kontrak di pesantren mendapat daftar
 * tugas berbeda dari staf kantor tanpa admin menyusun ulang.
 *
 * Yang menahan perpindahan keadaan adalah tugas bertanda `blocks_activation` /
 * `blocks_exit` — bukan seluruh tugas. Tugas opsional tidak pernah menahan orang
 * bekerja, tetapi tugas wajib tidak pernah bisa dilewati diam-diam.
 */
class LifecycleProcessService
{
    public function __construct(
        private readonly EmployeeNumberGenerator $numbers,
        private readonly IntegrationOutbox $outbox,
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
        private readonly HrdNotificationService $notifications,
    ) {}

    /* ── Onboarding & pre-boarding ──────────────────────────────────────── */

    /** @param array<string, mixed> $options */
    public function startOnboarding(HrEmployee $employee, User $actor, array $options = []): HrOnboardingProcess
    {
        return DB::transaction(function () use ($employee, $actor, $options): HrOnboardingProcess {
            $existing = HrOnboardingProcess::query()
                ->where('employee_id', $employee->getKey())
                ->whereIn('status', [HrOnboardingProcess::STATUS_PENDING, HrOnboardingProcess::STATUS_PREBOARDING, HrOnboardingProcess::STATUS_IN_PROGRESS])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $template = $this->resolveOnboardingTemplate($employee, $options['template_id'] ?? null);
            $startDate = $options['start_date'] ?? $employee->hire_date?->toDateString() ?? now()->addDays(7)->toDateString();

            $process = HrOnboardingProcess::query()->create([
                'process_number' => $this->numbers->next('onboarding', ['organization_id' => $employee->organization_id]),
                'employee_id' => $employee->getKey(),
                'template_id' => $template?->getKey(),
                'transaction_id' => $options['transaction_id'] ?? null,
                'status' => HrOnboardingProcess::STATUS_PENDING,
                'is_preboarding' => Carbon::parse($startDate)->isFuture(),
                'start_date' => $startDate,
                'started_at' => now(),
                'owner_id' => $options['owner_id'] ?? $actor->getKey(),
                'notes' => $options['notes'] ?? null,
            ]);

            if ($template !== null) {
                $this->materializeOnboardingTasks($process, $template, $employee);
            }

            $process->forceFill(['status' => $process->is_preboarding
                ? HrOnboardingProcess::STATUS_PREBOARDING
                : HrOnboardingProcess::STATUS_IN_PROGRESS])->save();

            $this->refreshOnboardingProgress($process);

            $this->timeline->record($employee, 'onboarding.started', 'Onboarding dimulai'.($template === null ? '' : ' (template: '.$template->name.')'), [
                'category' => 'lifecycle',
                'effective_date' => $startDate,
                'source_type' => HrOnboardingProcess::class,
                'source_id' => $process->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
            ]);

            $this->audit->log($actor, 'onboarding.started', $process, null, $process->toArray(), null, null, (int) $employee->getKey());

            $this->outbox->push('notification', 'onboarding.started', ['employee_id' => (int) $employee->getKey(), 'process_id' => (int) $process->getKey()], HrEmployee::class, $employee->getKey());

            // Perangkat & akun disiapkan lebih awal supaya hari pertama tidak habis
            // untuk menunggu administrasi.
            $this->outbox->push('asset', 'employee.onboard.equipment', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());
            $this->outbox->push('user', 'account.provision', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());

            return $process->refresh();
        });
    }

    /**
     * Selesaikan satu tugas onboarding.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function completeOnboardingTask(HrOnboardingTask $task, User $actor, array $attributes = []): HrOnboardingTask
    {
        if ($task->isDone()) {
            return $task;
        }

        if ($task->requires_attachment && empty($attributes['document_id'])) {
            throw HrdValidationException::forField('document_id', 'Tugas ini mewajibkan lampiran dokumen.', 'hr.onboarding.attachment_required');
        }

        return DB::transaction(function () use ($task, $actor, $attributes): HrOnboardingTask {
            $task->forceFill([
                'status' => array_key_exists('status', $attributes) && $attributes['status'] === HrOnboardingTask::STATUS_WAIVED
                    ? HrOnboardingTask::STATUS_WAIVED
                    : HrOnboardingTask::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
                'document_id' => $attributes['document_id'] ?? $task->document_id,
                'notes' => $attributes['notes'] ?? $task->notes,
            ])->save();

            $process = $task->process;

            if ($process !== null) {
                $this->refreshOnboardingProgress($process);

                $this->timeline->record($process->employee_id, 'onboarding.task_completed', 'Tugas onboarding selesai: '.$task->title, [
                    'category' => 'lifecycle',
                    'source_type' => HrOnboardingTask::class,
                    'source_id' => $task->getKey(),
                    'visibility' => 'employee',
                    'actor' => $actor,
                ]);

                if ($this->onboardingBlockers($process)->isEmpty()) {
                    $this->completeOnboarding($process, $actor);
                }
            }

            $this->audit->log($actor, 'onboarding.task_completed', $task, null, $task->toArray(), null, null, (int) ($task->process?->employee_id ?? 0));

            return $task->refresh();
        });
    }

    /** Tandai onboarding selesai & siapkan perpindahan keadaan berikutnya. */
    public function completeOnboarding(HrOnboardingProcess $process, User $actor): HrOnboardingProcess
    {
        $process->forceFill([
            'status' => HrOnboardingProcess::STATUS_COMPLETED,
            'completed_at' => now(),
            'progress' => 100,
        ])->save();

        $this->timeline->record($process->employee_id, 'onboarding.completed', 'Onboarding selesai', [
            'category' => 'lifecycle',
            'source_type' => HrOnboardingProcess::class,
            'source_id' => $process->getKey(),
            'visibility' => 'employee',
            'actor' => $actor,
        ]);

        $this->notifications->notify(
            'onboarding.completed',
            'Onboarding selesai',
            ($process->employee?->displayName() ?? 'Pegawai').' telah menyelesaikan seluruh tugas onboarding wajib.',
            [],
            (array) config('hrd.integration.notify_roles', ['HR_ADMIN']),
            $process->employee,
            ['process_id' => (int) $process->getKey()],
        );

        $this->outbox->push('lms', 'employee.onboard.training', ['employee_id' => (int) $process->employee_id], HrEmployee::class, $process->employee_id);

        return $process->refresh();
    }

    /* ── Offboarding & clearance (§40) ──────────────────────────────────── */

    /** @param array<string, mixed> $options */
    public function startOffboarding(HrEmployee $employee, User $actor, array $options = []): HrOffboardingProcess
    {
        return DB::transaction(function () use ($employee, $actor, $options): HrOffboardingProcess {
            $existing = HrOffboardingProcess::query()
                ->where('employee_id', $employee->getKey())
                ->whereIn('status', ['draft', 'submitted', 'approved', 'notice_period', 'in_progress'])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $template = $this->resolveOffboardingTemplate($employee, $options['exit_reason_code'] ?? null);
            $lastWorkingDate = $options['last_working_date'] ?? $employee->termination_date?->toDateString() ?? now()->addMonth()->toDateString();

            $process = HrOffboardingProcess::query()->create([
                'process_number' => $this->numbers->next('offboarding', ['organization_id' => $employee->organization_id]),
                'employee_id' => $employee->getKey(),
                'template_id' => $template?->getKey(),
                'transaction_id' => $options['transaction_id'] ?? null,
                'exit_reason_code' => $options['exit_reason_code'] ?? null,
                'status' => HrOffboardingProcess::STATUS_NOTICE_PERIOD,
                'notice_date' => $options['notice_date'] ?? now()->toDateString(),
                'last_working_date' => $lastWorkingDate,
                'started_at' => now(),
                'is_rehireable' => (bool) ($options['is_rehireable'] ?? true),
                'owner_id' => $options['owner_id'] ?? $actor->getKey(),
                'notes' => $options['notes'] ?? null,
            ]);

            if ($template !== null) {
                $this->materializeOffboardingTasks($process, $template, $employee);
            }

            $this->refreshOffboardingProgress($process);

            $this->timeline->record($employee, 'offboarding.started', 'Proses keluar dimulai', [
                'category' => 'lifecycle',
                'effective_date' => $lastWorkingDate,
                'source_type' => HrOffboardingProcess::class,
                'source_id' => $process->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['exit_reason_code' => $process->exit_reason_code],
            ]);

            $this->audit->log($actor, 'offboarding.started', $process, null, $process->toArray(), null, null, (int) $employee->getKey());

            $this->notifications->notify(
                'offboarding.started',
                'Proses keluar pegawai dimulai',
                ($employee->displayName()).' akan berakhir pada '.$process->last_working_date?->format('d/m/Y').'.',
                [],
                (array) config('hrd.integration.notify_roles', ['HR_ADMIN']),
                $employee,
                ['process_id' => (int) $process->getKey()],
            );

            // Clearance lintas modul diminta lebih awal — bukan pada hari terakhir.
            $this->outbox->push('asset', 'employee.exit.asset_return', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());
            $this->outbox->push('finance', 'employee.exit.clearance', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());
            $this->outbox->push('inventory', 'employee.exit.inventory_check', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());

            return $process->refresh();
        });
    }

    /**
     * Selesaikan tugas offboarding; tugas clearance memerlukan pembebasan terpisah.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function completeOffboardingTask(HrOffboardingTask $task, User $actor, array $attributes = []): HrOffboardingTask
    {
        if ($task->isDone()) {
            return $task;
        }

        return DB::transaction(function () use ($task, $actor, $attributes): HrOffboardingTask {
            if ($task->requires_clearance && ! $task->isCleared()) {
                throw HrdValidationException::forField(
                    'clearance_status',
                    'Tugas ini memerlukan pembebasan resmi dari pemegang area (clearance), bukan sekadar ditandai selesai.',
                    'hr.offboarding.clearance_required',
                );
            }

            $task->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
                'document_id' => $attributes['document_id'] ?? $task->document_id,
                'notes' => $attributes['notes'] ?? $task->notes,
            ])->save();

            $process = $task->process;

            if ($process !== null) {
                $this->refreshOffboardingProgress($process);

                $this->timeline->record($process->employee_id, 'offboarding.task_completed', 'Tugas keluar selesai: '.$task->title, [
                    'category' => 'lifecycle',
                    'source_type' => HrOffboardingTask::class,
                    'source_id' => $task->getKey(),
                    'visibility' => 'hr',
                    'actor' => $actor,
                ]);
            }

            $this->audit->log($actor, 'offboarding.task_completed', $task, null, $task->toArray(), null, null, (int) ($task->process?->employee_id ?? 0));

            return $task->refresh();
        });
    }

    /**
     * Bebaskan (clearance) sebuah tugas area (§40).
     *
     * `waived` tetap dapat dipakai — dengan alasan yang wajib dicatat, karena
     * melepas tanggung jawab tanpa keterangan adalah persis hal yang diaudit.
     */
    public function clearTask(HrOffboardingTask $task, User $actor, string $decision, ?string $notes = null): HrOffboardingTask
    {
        if (! $task->requires_clearance) {
            throw HrdValidationException::forField('clearance_status', 'Tugas ini bukan tugas pembebasan (clearance).', 'hr.offboarding.not_clearance');
        }

        if (! in_array($decision, ['cleared', 'waived', 'rejected'], true)) {
            throw HrdValidationException::forField('clearance_status', "Keputusan \"{$decision}\" tidak dikenal.", 'hr.offboarding.invalid_clearance');
        }

        if ($decision === 'waived' && ($notes === null || trim($notes) === '')) {
            throw HrdValidationException::forField('clearance_notes', 'Pembebasan dengan pengabaian wajib menyertakan alasan.', 'hr.offboarding.waiver_reason_required');
        }

        return DB::transaction(function () use ($task, $actor, $decision, $notes): HrOffboardingTask {
            $before = $task->toArray();

            $task->forceFill([
                'clearance_status' => $decision,
                'cleared_by' => $actor->getKey(),
                'cleared_at' => now(),
                'clearance_notes' => $notes,
                'status' => $decision === 'rejected' ? 'in_progress' : $task->status,
            ])->save();

            $process = $task->process;

            if ($process !== null && $decision !== 'rejected') {
                $this->refreshOffboardingProgress($process);

                $this->timeline->record($process->employee_id, 'offboarding.cleared', 'Pembebasan area '.($task->clearance_area ?? '-').': '.$decision, [
                    'category' => 'lifecycle',
                    'source_type' => HrOffboardingTask::class,
                    'source_id' => $task->getKey(),
                    'visibility' => 'hr',
                    'actor' => $actor,
                    'meta' => ['notes' => $notes],
                ]);
            }

            $this->audit->log($actor, 'offboarding.clearance', $task, $before, $task->toArray(), $notes, null, (int) ($task->process?->employee_id ?? 0));

            return $task->refresh();
        });
    }

    /**
     * Selesaikan proses offboarding bila seluruh tugas penahan sudah bersih.
     *
     * Pegawai dipindahkan ke `inactive` DI SINI — bukan pada saat pengunduran diri
     * disetujui (§40).
     */
    public function completeOffboarding(HrOffboardingProcess $process, User $actor): HrOffboardingProcess
    {
        $blockers = $this->offboardingBlockers($process);

        if ($blockers->isNotEmpty()) {
            throw HrdValidationException::forField(
                'status',
                'Masih ada tugas penyelesaian yang menahan: '.$blockers->pluck('title')->take(5)->implode(', ').'.',
                'hr.offboarding.blockers',
            );
        }

        return DB::transaction(function () use ($process, $actor): HrOffboardingProcess {
            $process->forceFill([
                'status' => HrOffboardingProcess::STATUS_COMPLETED,
                'completed_at' => now(),
                'progress' => 100,
            ])->save();

            $employee = $process->employee;

            if ($employee !== null && $employee->lifecycle_state !== HrEmployee::LIFECYCLE_INACTIVE) {
                app(EmployeeService::class)->changeLifecycleState(
                    $employee,
                    HrEmployee::LIFECYCLE_INACTIVE,
                    $actor,
                    'INACTIVE',
                    $process->last_working_date?->toDateString(),
                    'Offboarding selesai: '.$process->process_number,
                    skipTransitionCheck: true,
                );
            }

            if ($employee !== null) {
                $this->timeline->record($employee, 'offboarding.completed', 'Proses keluar selesai', [
                    'category' => 'lifecycle',
                    'source_type' => HrOffboardingProcess::class,
                    'source_id' => $process->getKey(),
                    'visibility' => 'hr',
                    'actor' => $actor,
                ]);
            }

            $this->audit->log($actor, 'offboarding.completed', $process, null, $process->toArray(), null, null, (int) $process->employee_id);

            if ($employee?->user_id !== null) {
                $this->outbox->push('user', 'account.suspend', ['employee_id' => (int) $employee->getKey(), 'user_id' => $employee->user_id], HrEmployee::class, $employee->getKey());
            }

            return $process->refresh();
        });
    }

    /* ── Progres & penahan ──────────────────────────────────────────────── */

    public function refreshOnboardingProgress(HrOnboardingProcess $process): int
    {
        $total = $process->tasks()->where('is_mandatory', true)->count();
        $done = $process->tasks()->where('is_mandatory', true)->whereIn('status', ['completed', 'waived'])->count();

        $progress = $total === 0 ? 100 : (int) floor($done / $total * 100);

        $process->forceFill(['progress' => $progress])->save();

        return $progress;
    }

    public function refreshOffboardingProgress(HrOffboardingProcess $process): int
    {
        $total = $process->tasks()->where('is_mandatory', true)->count();
        $done = $process->tasks()->where('is_mandatory', true)->whereIn('status', ['completed', 'cancelled'])->count();

        $progress = $total === 0 ? 100 : (int) floor($done / $total * 100);

        $process->forceFill(['progress' => $progress])->save();

        return $progress;
    }

    /** Tugas onboarding yang masih menahan aktivasi (§38). */
    public function onboardingBlockers(HrOnboardingProcess $process)
    {
        return $process->tasks()
            ->where('blocks_activation', true)
            ->whereNotIn('status', ['completed', 'waived'])
            ->get();
    }

    /** Tugas offboarding yang masih menahan status tidak aktif (§40). */
    public function offboardingBlockers(HrOffboardingProcess $process)
    {
        return $process->tasks()
            ->where(fn ($q) => $q->where('blocks_exit', true)->orWhere('is_mandatory', true))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->get();
    }

    /* ── Template ───────────────────────────────────────────────────────── */

    /** Pemilihan template onboarding berurutan: makin spesifik makin diprioritaskan. */
    public function resolveOnboardingTemplate(HrEmployee $employee, int|string|null $templateId = null): ?HrOnboardingTemplate
    {
        if ($templateId !== null) {
            return HrOnboardingTemplate::query()->find($templateId);
        }

        return HrOnboardingTemplate::query()
            ->active()
            ->where(fn ($q) => $q->whereNull('worker_type_code')->orWhere('worker_type_code', $employee->worker_type))
            ->where(fn ($q) => $q->whereNull('employment_type_code')->orWhere('employment_type_code', $employee->employment_type))
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $employee->organization_id))
            ->where(fn ($q) => $q->whereNull('position_id')->orWhere('position_id', $employee->position_id))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }

    public function resolveOffboardingTemplate(HrEmployee $employee, ?string $exitReasonCode = null): ?HrOffboardingTemplate
    {
        return HrOffboardingTemplate::query()
            ->active()
            ->where(fn ($q) => $q->whereNull('worker_type_code')->orWhere('worker_type_code', $employee->worker_type))
            ->where(fn ($q) => $q->whereNull('exit_reason_code')->orWhere('exit_reason_code', $exitReasonCode))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }

    /* ── Materialisasi tugas ────────────────────────────────────────────── */

    private function materializeOnboardingTasks(HrOnboardingProcess $process, HrOnboardingTemplate $template, HrEmployee $employee): void
    {
        $start = Carbon::parse($process->start_date ?? now());

        foreach ($template->tasks()->orderBy('sort_order')->get() as $templateTask) {
            HrOnboardingTask::query()->create([
                'process_id' => $process->getKey(),
                'template_task_id' => $templateTask->getKey(),
                'code' => $templateTask->code,
                'title' => $templateTask->title,
                'category' => $templateTask->category,
                'owner_type' => $templateTask->owner_type,
                'owner_user_id' => $this->resolveOwnerUserId($templateTask->owner_type, $templateTask->owner_role, $employee, $process->owner_id),
                'owner_employee_id' => $templateTask->owner_type === 'employee' ? $employee->getKey() : null,
                'due_date' => $start->copy()->addDays((int) $templateTask->due_offset_days)->toDateString(),
                'is_mandatory' => (bool) $templateTask->is_mandatory,
                'requires_attachment' => (bool) $templateTask->requires_attachment,
                'blocks_activation' => (bool) $templateTask->blocks_activation,
                'status' => HrOnboardingTask::STATUS_PENDING,
                'sort_order' => (int) $templateTask->sort_order,
                'notes' => $templateTask->instructions,
            ]);
        }
    }

    private function materializeOffboardingTasks(HrOffboardingProcess $process, HrOffboardingTemplate $template, HrEmployee $employee): void
    {
        $last = Carbon::parse($process->last_working_date ?? now());

        foreach ($template->tasks()->orderBy('sort_order')->get() as $templateTask) {
            HrOffboardingTask::query()->create([
                'process_id' => $process->getKey(),
                'template_task_id' => $templateTask->getKey(),
                'code' => $templateTask->code,
                'title' => $templateTask->title,
                'category' => $templateTask->category,
                'owner_type' => $templateTask->owner_type,
                'owner_user_id' => $this->resolveOwnerUserId($templateTask->owner_type, $templateTask->owner_role, $employee, $process->owner_id),
                'owner_employee_id' => $templateTask->owner_type === 'employee' ? $employee->getKey() : null,
                'clearance_area' => $templateTask->clearance_area,
                'requires_clearance' => (bool) $templateTask->requires_clearance,
                'clearance_status' => $templateTask->requires_clearance ? HrOffboardingTask::CLEARANCE_PENDING : HrOffboardingTask::CLEARANCE_CLEARED,
                'due_date' => $last->copy()->addDays((int) $templateTask->due_offset_days)->toDateString(),
                'is_mandatory' => (bool) $templateTask->is_mandatory,
                'blocks_exit' => (bool) $templateTask->blocks_exit,
                'requires_attachment' => (bool) $templateTask->requires_attachment,
                'status' => 'pending',
                'sort_order' => (int) $templateTask->sort_order,
                'notes' => $templateTask->instructions,
            ]);
        }
    }

    /**
     * Pemilik tugas: atasan (dari relasi), peran tertentu, atau PIC HR.
     * Bila tidak ada yang cocok, tugas tetap ada dan pemiliknya PIC proses —
     * tugas tanpa pemilik adalah tugas yang tidak akan pernah selesai.
     */
    private function resolveOwnerUserId(string $ownerType, ?string $ownerRole, HrEmployee $employee, ?int $fallbackUserId): ?int
    {
        if ($ownerType === 'manager') {
            $managerId = $employee->currentManagerId();
            $managerUserId = $managerId === null ? null : HrEmployee::query()->whereKey($managerId)->value('user_id');

            return $managerUserId === null ? $fallbackUserId : (int) $managerUserId;
        }

        if ($ownerType === 'employee') {
            return $employee->user_id === null ? $fallbackUserId : (int) $employee->user_id;
        }

        if ($ownerRole !== null) {
            $userId = app(\App\Modules\Hrd\Domain\Contracts\UserAccountProviderInterface::class)->usersWithRole($ownerRole);

            return $userId === [] ? $fallbackUserId : (int) $userId[0];
        }

        return $fallbackUserId;
    }
}
