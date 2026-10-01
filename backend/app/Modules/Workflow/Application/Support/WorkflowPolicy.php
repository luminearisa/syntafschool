<?php

namespace App\Modules\Workflow\Application\Support;

use App\Modules\Workflow\Domain\Enums\EmptyApproverPolicy;
use App\Modules\Workflow\Domain\Enums\ReturnStrategy;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowVersion;

/**
 * Kebijakan efektif sebuah workflow.
 *
 * Urutan pengambilan (yang paling spesifik menang):
 *   1. `config` pada versi workflow,
 *   2. `settings` pada definisi,
 *   3. `config/workflow.php` (default pabrik).
 *
 * Semua "angka ajaib" — batas SLA, kebijakan self-approval, strategi resubmit —
 * melewati kelas ini sehingga tidak ada satu pun yang tertanam di kode engine.
 */
final class WorkflowPolicy
{
    /**
     * @param  list<string>  $commentRequiredOn
     * @param  list<string>  $notificationChannels
     * @param  list<string>  $requiredContext
     * @param  list<array<string, mixed>>  $observers
     * @param  array<string, mixed>  $escalation
     */
    public function __construct(
        public readonly bool $allowSelfApproval,
        public readonly array $commentRequiredOn,
        public readonly ReturnStrategy $returnStrategy,
        public readonly EmptyApproverPolicy $emptyApproverPolicy,
        public readonly int $maxExecutionHops,
        public readonly string $actionOnFailure,
        public readonly array $notificationChannels,
        public readonly array $requiredContext,
        public readonly array $observers,
        public readonly array $escalation,
        public readonly ?string $calendarCode,
        public readonly array $raw = [],
    ) {
    }

    public static function resolve(?WorkflowDefinition $definition = null, ?WorkflowVersion $version = null): self
    {
        $config = (array) config('workflow', []);
        $defaults = (array) ($config['policies'] ?? []);
        $definitionSettings = (array) ($definition?->settings ?? []);
        $versionConfig = (array) ($version?->config ?? []);

        // Kolom `allow_self_approval` pada definisi adalah kebijakan eksplisit
        // (§36) dan setara dengan pengaturan yang sama di `settings`.
        if ($definition !== null && $definition->allow_self_approval) {
            $definitionSettings['allow_self_approval'] = true;
        }

        $pick = function (string $key, mixed $fallback) use ($versionConfig, $definitionSettings, $defaults): mixed {
            if (array_key_exists($key, $versionConfig)) {
                return $versionConfig[$key];
            }

            if (array_key_exists($key, $definitionSettings)) {
                return $definitionSettings[$key];
            }

            return $defaults[$key] ?? $fallback;
        };

        $escalationDefaults = (array) ($config['sla']['escalation'] ?? []);
        $escalation = array_merge($escalationDefaults, (array) $pick('escalation', []));

        return new self(
            allowSelfApproval: (bool) $pick('allow_self_approval', false),
            commentRequiredOn: array_values(array_map(
                'strtoupper',
                (array) $pick('comment_required_on', ['REJECT', 'RETURN', 'REQUEST_CHANGES']),
            )),
            returnStrategy: ReturnStrategy::tryFrom((string) $pick('return_strategy', ReturnStrategy::RESUME_FROM_PREVIOUS_STEP->value))
                ?? ReturnStrategy::RESUME_FROM_PREVIOUS_STEP,
            emptyApproverPolicy: EmptyApproverPolicy::tryFrom((string) $pick('empty_approver', EmptyApproverPolicy::FAIL->value))
                ?? EmptyApproverPolicy::FAIL,
            maxExecutionHops: max(10, (int) $pick('max_execution_hops', 200)),
            actionOnFailure: strtoupper((string) $pick('action_on_failure', 'LOG')),
            notificationChannels: array_values(array_map(
                'strtoupper',
                (array) ($versionConfig['notification_channels']
                    ?? $definitionSettings['notification_channels']
                    ?? ($config['notifications']['channels'] ?? ['IN_APP'])),
            )),
            requiredContext: array_values((array) ($versionConfig['required_context'] ?? $definition->required_context ?? [])),
            observers: array_values((array) ($versionConfig['observers'] ?? $definition?->observers ?? [])),
            escalation: $escalation,
            calendarCode: $versionConfig['calendar'] ?? $definitionSettings['calendar'] ?? ($config['calendar'] ?? null),
            raw: array_merge($defaults, $definitionSettings, $versionConfig),
        );
    }

    public function requiresComment(string $decision): bool
    {
        return in_array(strtoupper($decision), $this->commentRequiredOn, true);
    }

    public function failsFastOnActionFailure(): bool
    {
        return $this->actionOnFailure === 'FAIL';
    }
}
