<?php

namespace App\Modules\Workflow\Infrastructure\Registry;

use App\Modules\Workflow\Domain\Contracts\ApproverResolverInterface;
use App\Modules\Workflow\Domain\Contracts\ConditionEvaluatorInterface;
use App\Modules\Workflow\Domain\Contracts\WorkflowActionInterface;
use App\Modules\Workflow\Domain\Contracts\WorkflowContextProviderInterface;
use App\Modules\Workflow\Domain\Contracts\WorkflowTriggerInterface;
use RuntimeException;

/**
 * Registry titik ekstensi engine (§22, §43, §74).
 *
 * Semua "kabel" antara engine dan modul ERP melewati kelas ini:
 *
 * ```
 * Resolver approver  →  Registrar  →  definisi workflow ("resolver": "manager")
 * Aksi otomatis      →  Registrar  →  node/definisi ("handler": "finance.create_po")
 * Context provider   →  Registrar  →  subject_type ("finance.purchase_request")
 * Trigger            →  Registrar  →  peristiwa ("finance.pr.submitted")
 * ```
 *
 * Modul cukup memanggil `register*()` dari service provider-nya. Engine tidak
 * perlu diubah, dan tidak ada `switch` berisi nama modul di dalamnya.
 */
class WorkflowRegistrar
{
    /** @var array<string, ApproverResolverInterface> */
    protected array $resolvers = [];

    /** @var array<string, WorkflowActionInterface> */
    protected array $actions = [];

    /** @var array<string, WorkflowContextProviderInterface> */
    protected array $contextProviders = [];

    /** @var array<string, WorkflowTriggerInterface> */
    protected array $triggers = [];

    protected ?ConditionEvaluatorInterface $conditionEvaluator = null;

    protected bool $booted = false;

    public function registerResolver(ApproverResolverInterface $resolver): void
    {
        $this->resolvers[$resolver->alias()] = $resolver;
    }

    public function registerAction(WorkflowActionInterface $action): void
    {
        $this->actions[$action->alias()] = $action;
    }

    public function registerContextProvider(WorkflowContextProviderInterface $provider): void
    {
        $this->contextProviders[$provider->subjectType()] = $provider;
    }

    public function registerTrigger(WorkflowTriggerInterface $trigger): void
    {
        $this->triggers[$trigger->key()] = $trigger;
    }

    public function setConditionEvaluator(ConditionEvaluatorInterface $evaluator): void
    {
        $this->conditionEvaluator = $evaluator;
    }

    public function conditionEvaluator(): ConditionEvaluatorInterface
    {
        if ($this->conditionEvaluator === null) {
            throw new RuntimeException('Condition evaluator belum diikat pada WorkflowRegistrar.');
        }

        return $this->conditionEvaluator;
    }

    /** @return array<string, ApproverResolverInterface> */
    public function resolvers(): array
    {
        return $this->resolvers;
    }

    public function resolver(string $alias): ?ApproverResolverInterface
    {
        return $this->resolvers[$alias] ?? null;
    }

    public function hasResolver(string $alias): bool
    {
        return isset($this->resolvers[$alias]);
    }

    /** @return array<string, WorkflowActionInterface> */
    public function actions(): array
    {
        return $this->actions;
    }

    public function action(string $alias): ?WorkflowActionInterface
    {
        return $this->actions[$alias] ?? null;
    }

    /** @return array<string, WorkflowContextProviderInterface> */
    public function contextProviders(): array
    {
        return $this->contextProviders;
    }

    public function contextProvider(string $subjectType): ?WorkflowContextProviderInterface
    {
        return $this->contextProviders[$subjectType] ?? null;
    }

    /** @return array<string, WorkflowTriggerInterface> */
    public function triggers(): array
    {
        return $this->triggers;
    }

    public function trigger(string $key): ?WorkflowTriggerInterface
    {
        return $this->triggers[$key] ?? null;
    }

    /** Dipakai pengujian & instalasi ulang registry. */
    public function flush(): void
    {
        $this->resolvers = [];
        $this->actions = [];
        $this->contextProviders = [];
        $this->triggers = [];
        $this->conditionEvaluator = null;
        $this->booted = false;
    }

    public function markBooted(): void
    {
        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }
}
