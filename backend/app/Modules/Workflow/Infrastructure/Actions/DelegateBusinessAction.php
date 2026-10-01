<?php

namespace App\Modules\Workflow\Infrastructure\Actions;

use App\Modules\Workflow\Domain\Contracts\WorkflowActionInterface;
use App\Modules\Workflow\Domain\Support\WorkflowActionContext;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use InvalidArgumentException;

/**
 * Aksi bawaan: teruskan ke aksi lain yang terdaftar (§43).
 *
 * Dipakai ketika definisi ingin menyusun rangkaian aksi tanpa engine tahu isi
 * masing-masing, mis.:
 *
 * ```json
 * { "handler": "business.create",
 *   "params": { "handler": "finance.create_purchase_order",
 *               "params": { "from": "context.subject_id" } } }
 * ```
 *
 * Handler tujuan tetap milik modul (`finance`) dan tetap idempoten — engine
 * hanya menjadi tempat menuliskan urutan, bukan tempat menaruh logika bisnis.
 */
final class DelegateBusinessAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly WorkflowRegistrar $registrar,
    ) {
    }

    public function alias(): string
    {
        return 'business.create';
    }

    public function description(): string
    {
        return 'Jalankan aksi bisnis lain yang terdaftar (handler di dalam params).';
    }

    public function execute(WorkflowActionContext $context, array $params = []): array
    {
        $alias = (string) ($params['handler'] ?? '');

        if ($alias === '') {
            throw new InvalidArgumentException('Aksi business.create membutuhkan `handler` tujuan.');
        }

        $target = $this->registrar->action($alias);

        if ($target === null || $target instanceof self) {
            throw new InvalidArgumentException("Aksi bisnis `{$alias}` belum terdaftar pada WorkflowRegistrar.");
        }

        $inner = (array) ($params['params'] ?? []);

        return ['delegated_to' => $alias] + $target->execute($context, $inner);
    }
}
