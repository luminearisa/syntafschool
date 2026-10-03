<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Workflow & Approval Engine (§57). Override/kompensasi bernilai besar dapat
 * dimintakan persetujuan lewat mesin workflow bila terpasang.
 */
interface WorkflowProviderInterface
{
    /**
     * @param  array{type:string, subject_type:string, subject_id:int, payload:array, requested_by:?int}  $request
     * @return array{started:bool, workflow_request_id?:int|null, reason?:string}
     */
    public function requestApproval(array $request): array;

    public function available(): bool;
}
