<?php

namespace App\Modules\Workflow\Infrastructure\Actions;

use App\Modules\Workflow\Domain\Contracts\WorkflowActionInterface;
use App\Modules\Workflow\Domain\Contracts\WorkflowSubjectStatusInterface;
use App\Modules\Workflow\Domain\Support\WorkflowActionContext;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use RuntimeException;

/**
 * Aksi bawaan: ubah status entitas bisnis lewat provider konteksnya (§43).
 *
 * Engine tidak mengubah tabel modul. Ia memanggil
 * `WorkflowSubjectStatusInterface::applyStatus()` milik provider yang terdaftar
 * untuk `subject_type` request tersebut. Bila providernya tidak menyediakan
 * kemampuan ini, aksi gagal dengan pesan jelas — bukan diam.
 */
final class UpdateSubjectStatusAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly WorkflowRegistrar $registrar,
    ) {
    }

    public function alias(): string
    {
        return 'subject.update_status';
    }

    public function description(): string
    {
        return 'Ubah status entitas bisnis lewat provider konteks (mis. APPROVED/REJECTED).';
    }

    public function execute(WorkflowActionContext $context, array $params = []): array
    {
        $status = (string) ($params['status'] ?? $context->instance->status->value);
        $subjectType = (string) $context->instance->subject_type;

        $provider = $this->registrar->contextProvider($subjectType);

        if ($provider === null) {
            throw new RuntimeException("Tidak ada context provider untuk `{$subjectType}`.");
        }

        if (! $provider instanceof WorkflowSubjectStatusInterface) {
            throw new RuntimeException(sprintf(
                'Provider %s tidak mendukung perubahan status (implementasikan WorkflowSubjectStatusInterface).',
                $provider::class,
            ));
        }

        $provider->applyStatus(
            $context->instance->subject_id,
            $status,
            (array) ($params['meta'] ?? []) + [
                'instance_code' => $context->instance->code,
                'hook' => $context->hook,
                'decided_by' => $context->actor?->id,
            ],
        );

        return ['subject_type' => $subjectType, 'subject_id' => $context->instance->subject_id, 'status' => $status];
    }
}
