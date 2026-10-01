<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\DefinitionStatus;
use App\Modules\Workflow\Domain\Enums\TriggerType;
use App\Modules\Workflow\Domain\Enums\VersionStatus;
use App\Modules\Workflow\Domain\Exceptions\InvalidWorkflowOperation;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengelolaan definisi workflow (§13, §15).
 *
 * Kelas ini menjaga pemisahan tugas: **Workflow Admin** mengubah konfigurasi di
 * sini, tetapi tidak mendapat kewenangan menyetujui request bisnis hanya karena
 * ia bisa mengubah definisi (§10).
 */
class WorkflowDefinitionService
{
    public function __construct(
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): WorkflowDefinition
    {
        $code = strtoupper(Str::slug((string) ($data['code'] ?? $data['name'] ?? ''), '_'));

        if ($code === '') {
            throw InvalidWorkflowOperation::make('Kode definisi wajib diisi.');
        }

        if (WorkflowDefinition::query()->where('code', $code)->exists()) {
            throw InvalidWorkflowOperation::make("Definisi dengan kode {$code} sudah ada.", ['code' => $code]);
        }

        return DB::transaction(function () use ($data, $actor, $code) {
            $definition = WorkflowDefinition::query()->create([
                'code' => $code,
                'name' => $data['name'] ?? $code,
                'description' => $data['description'] ?? null,
                'module' => strtolower((string) ($data['module'] ?? 'general')),
                'event' => $data['event'] ?? null,
                'entity_type' => $data['entity_type'] ?? null,
                'category' => $data['category'] ?? null,
                'status' => DefinitionStatus::DRAFT->value,
                'trigger_type' => TriggerType::tryFrom((string) ($data['trigger_type'] ?? 'MANUAL'))?->value ?? 'MANUAL',
                'owner_user_id' => $data['owner_user_id'] ?? $actor->id,
                'owner_department' => $data['owner_department'] ?? null,
                'allow_self_approval' => (bool) ($data['allow_self_approval'] ?? false),
                'comment_required_on' => $data['comment_required_on'] ?? null,
                'required_context' => $data['required_context'] ?? null,
                'observers' => $data['observers'] ?? null,
                'settings' => $data['settings'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                AuditAction::DEFINITION_CREATED,
                null,
                $definition,
                after: $definition->only(['code', 'name', 'module', 'entity_type', 'trigger_type']),
                actor: $actor,
                extra: ['definition_id' => $definition->id, 'target_type' => WorkflowDefinition::class, 'target_id' => (string) $definition->id],
            );

            return $definition;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkflowDefinition $definition, array $data, User $actor): WorkflowDefinition
    {
        $before = $definition->only(['name', 'description', 'module', 'event', 'entity_type', 'owner_user_id', 'allow_self_approval', 'status']);

        $definition->forceFill([
            'name' => $data['name'] ?? $definition->name,
            'description' => $data['description'] ?? $definition->description,
            'module' => isset($data['module']) ? strtolower((string) $data['module']) : $definition->module,
            'event' => $data['event'] ?? $definition->event,
            'entity_type' => $data['entity_type'] ?? $definition->entity_type,
            'category' => $data['category'] ?? $definition->category,
            'owner_user_id' => $data['owner_user_id'] ?? $definition->owner_user_id,
            'owner_department' => $data['owner_department'] ?? $definition->owner_department,
            'allow_self_approval' => array_key_exists('allow_self_approval', $data)
                ? (bool) $data['allow_self_approval']
                : $definition->allow_self_approval,
            'comment_required_on' => $data['comment_required_on'] ?? $definition->comment_required_on,
            'required_context' => $data['required_context'] ?? $definition->required_context,
            'observers' => $data['observers'] ?? $definition->observers,
            'settings' => $data['settings'] ?? $definition->settings,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(
            AuditAction::DEFINITION_UPDATED,
            null,
            $definition,
            before: $before,
            after: $definition->only(array_keys($before)),
            actor: $actor,
            extra: ['definition_id' => $definition->id, 'target_type' => WorkflowDefinition::class, 'target_id' => (string) $definition->id],
        );

        return $definition;
    }

    /** Nonaktifkan definisi: request baru berhenti, riwayat tetap utuh. */
    public function deactivate(WorkflowDefinition $definition, User $actor, string $reason): WorkflowDefinition
    {
        if (trim($reason) === '') {
            throw InvalidWorkflowOperation::make('Menonaktifkan definisi mewajibkan alasan.');
        }

        $definition->forceFill([
            'status' => DefinitionStatus::INACTIVE->value,
            'updated_by' => $actor->id,
        ])->save();

        WorkflowVersion::query()
            ->where('definition_id', $definition->id)
            ->where('status', VersionStatus::ACTIVE->value)
            ->update(['status' => VersionStatus::INACTIVE->value]);

        $this->audit->record(
            AuditAction::DEFINITION_UPDATED,
            null,
            $definition,
            after: ['status' => DefinitionStatus::INACTIVE->value],
            reason: $reason,
            actor: $actor,
            extra: ['definition_id' => $definition->id],
        );

        return $definition;
    }

    /** Definisi yang dapat dipakai request baru. */
    public function startable(): array
    {
        return WorkflowDefinition::query()
            ->active()
            ->with('currentVersion')
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * Definisi + versi yang cocok untuk sebuah subjek/event (§45).
     */
    public function match(string $subjectType, ?string $event = null): ?WorkflowDefinition
    {
        $query = WorkflowDefinition::query()
            ->where('status', DefinitionStatus::ACTIVE->value)
            ->where('entity_type', $subjectType);

        if ($event !== null) {
            $query->where(function ($q) use ($event): void {
                $q->where('event', $event)->orWhereNull('event');
            })->orderByRaw('CASE WHEN event = ? THEN 0 ELSE 1 END', [$event]);
        }

        return $query->first();
    }
}
