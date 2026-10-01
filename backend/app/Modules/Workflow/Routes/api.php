<?php

use App\Modules\Workflow\Http\Controllers\WorkflowAdminController;
use App\Modules\Workflow\Http\Controllers\WorkflowApprovalController;
use App\Modules\Workflow\Http\Controllers\WorkflowDefinitionController;
use App\Modules\Workflow\Http\Controllers\WorkflowInstanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workflow & Approval Engine — /api/v1/workflows/*
|--------------------------------------------------------------------------
|
| Setiap endpoint memiliki izinnya sendiri (§71). Tidak ada satu endpoint pun
| yang hanya bersandar pada "sudah login": membaca definisi, memutuskan
| persetujuan, mengubah SLA, melihat audit, dan mengelola direktori adalah
| kewenangan yang berbeda-beda.
|
| Konfigurasi (definisi/versi) sengaja dipisah dari keputusan bisnis: pemegang
| `workflow.definition.manage` tidak otomatis boleh menyetujui request (§10).
|
*/

Route::prefix('workflows')->name('workflows.')->middleware(['auth:sanctum', 'throttle:workflow-module'])->group(function (): void {

    // ------------------------------------------------------- Meta & kesehatan
    Route::get('meta', [WorkflowAdminController::class, 'meta'])->middleware('permission:workflow.view');
    Route::get('health', [WorkflowAdminController::class, 'health'])->middleware('permission:workflow.settings.view');

    // ------------------------------------------------------------- Definition
    Route::get('definitions', [WorkflowDefinitionController::class, 'index'])->middleware('permission:workflow.definition.view');
    Route::post('definitions', [WorkflowDefinitionController::class, 'store'])->middleware(['permission:workflow.definition.manage', 'throttle:workflow-write']);
    Route::get('definitions/{definition}', [WorkflowDefinitionController::class, 'show'])->middleware('permission:workflow.definition.view');
    Route::match(['put', 'patch'], 'definitions/{definition}', [WorkflowDefinitionController::class, 'update'])->middleware(['permission:workflow.definition.manage', 'throttle:workflow-write']);
    Route::post('definitions/{definition}/deactivate', [WorkflowDefinitionController::class, 'deactivate'])->middleware(['permission:workflow.definition.manage', 'throttle:workflow-write']);
    Route::get('definitions/{definition}/versions', [WorkflowDefinitionController::class, 'versions'])->middleware('permission:workflow.definition.view');
    Route::post('definitions/{definition}/versions', [WorkflowDefinitionController::class, 'storeVersion'])->middleware(['permission:workflow.definition.manage', 'throttle:workflow-write']);
    Route::post('definitions/{definition}/simulate', [WorkflowDefinitionController::class, 'simulate'])->middleware(['permission:workflow.definition.view', 'throttle:workflow-simulate']);

    // ---------------------------------------------------------------- Version
    Route::get('versions/{version}', [WorkflowDefinitionController::class, 'showVersion'])->middleware('permission:workflow.definition.view');
    Route::put('versions/{version}', [WorkflowDefinitionController::class, 'saveVersion'])->middleware(['permission:workflow.definition.manage', 'throttle:workflow-write']);
    Route::post('versions/{version}/validate', [WorkflowDefinitionController::class, 'validateVersion'])->middleware(['permission:workflow.definition.view', 'throttle:workflow-simulate']);
    Route::post('versions/{version}/publish', [WorkflowDefinitionController::class, 'publishVersion'])->middleware(['permission:workflow.version.publish', 'throttle:workflow-write']);
    Route::post('versions/{version}/archive', [WorkflowDefinitionController::class, 'archiveVersion'])->middleware(['permission:workflow.version.publish', 'throttle:workflow-write']);
    Route::post('versions/{version}/simulate', [WorkflowDefinitionController::class, 'simulateVersion'])->middleware(['permission:workflow.definition.view', 'throttle:workflow-simulate']);

    // --------------------------------------------------------------- Instance
    Route::get('instances', [WorkflowInstanceController::class, 'index'])->middleware('permission:workflow.request.view');
    Route::post('instances', [WorkflowInstanceController::class, 'store'])->middleware(['permission:workflow.request.create', 'throttle:workflow-write']);
    Route::get('instances/{instance}', [WorkflowInstanceController::class, 'show'])->middleware('permission:workflow.request.view');
    Route::post('instances/{instance}/submit', [WorkflowInstanceController::class, 'submit'])->middleware(['permission:workflow.request.submit', 'throttle:workflow-write']);
    Route::post('instances/{instance}/cancel', [WorkflowInstanceController::class, 'cancel'])->middleware(['permission:workflow.request.cancel', 'throttle:workflow-write']);
    Route::post('instances/{instance}/revise', [WorkflowInstanceController::class, 'revise'])->middleware(['permission:workflow.request.submit', 'throttle:workflow-write']);
    Route::post('instances/{instance}/resubmit', [WorkflowInstanceController::class, 'submit'])->middleware(['permission:workflow.request.submit', 'throttle:workflow-write']);
    Route::get('instances/{instance}/timeline', [WorkflowInstanceController::class, 'timeline'])->middleware('permission:workflow.request.view');
    Route::get('instances/{instance}/context', [WorkflowInstanceController::class, 'context'])->middleware('permission:workflow.request.view');
    Route::post('instances/{instance}/comments', [WorkflowInstanceController::class, 'comment'])->middleware(['permission:workflow.request.view', 'throttle:workflow-write']);
    Route::post('instances/{instance}/attachments', [WorkflowInstanceController::class, 'attach'])->middleware(['permission:workflow.request.view', 'throttle:workflow-write']);
    Route::get('instances/{instance}/audit', [WorkflowInstanceController::class, 'audit'])->middleware('permission:workflow.audit.view');
    Route::post('instances/{instance}/dry-run', [WorkflowInstanceController::class, 'dryRun'])->middleware(['permission:workflow.request.view', 'throttle:workflow-simulate']);
    Route::post('instances/{instance}/steps/{step}/assign', [WorkflowInstanceController::class, 'assignApprovers'])->middleware(['permission:workflow.approval.reassign', 'throttle:workflow-write']);
    Route::post('instances/{instance}/steps/{step}/skip', [WorkflowApprovalController::class, 'skipStep'])->middleware(['permission:workflow.approval.skip', 'throttle:workflow-write']);

    // --------------------------------------------------------------- Approval
    Route::get('approvals', [WorkflowApprovalController::class, 'all'])->middleware('permission:workflow.approval.view');
    Route::get('approvals/{approval}', [WorkflowApprovalController::class, 'show'])->middleware('permission:workflow.approval.view');
    Route::post('approvals/{approval}/approve', [WorkflowApprovalController::class, 'approve'])->middleware(['permission:workflow.approval.decide', 'throttle:workflow-decision']);
    Route::post('approvals/{approval}/reject', [WorkflowApprovalController::class, 'reject'])->middleware(['permission:workflow.approval.decide', 'throttle:workflow-decision']);
    Route::post('approvals/{approval}/return', [WorkflowApprovalController::class, 'returnForRevision'])->middleware(['permission:workflow.approval.decide', 'throttle:workflow-decision']);
    Route::post('approvals/{approval}/request-changes', [WorkflowApprovalController::class, 'requestChanges'])->middleware(['permission:workflow.approval.decide', 'throttle:workflow-decision']);
    Route::post('approvals/{approval}/reassign', [WorkflowApprovalController::class, 'reassign'])->middleware(['permission:workflow.approval.reassign', 'throttle:workflow-write']);

    // ------------------------------------------------------------------ Inbox
    Route::get('inbox/approvals', [WorkflowApprovalController::class, 'index'])->middleware('permission:workflow.approval.view');
    Route::get('inbox/requests', [WorkflowApprovalController::class, 'myRequests'])->middleware('permission:workflow.request.view');
    Route::get('inbox/counter', [WorkflowApprovalController::class, 'counter'])->middleware('permission:workflow.request.view');
    Route::get('inbox/filters', [WorkflowApprovalController::class, 'filterOptions'])->middleware('permission:workflow.request.view');

    // ------------------------------------------------------------- Delegation
    Route::get('delegations', [WorkflowAdminController::class, 'delegations'])->middleware('permission:workflow.delegation.view');
    Route::post('delegations', [WorkflowAdminController::class, 'storeDelegation'])->middleware(['permission:workflow.delegation.manage', 'throttle:workflow-write']);
    Route::post('delegations/{delegation}/revoke', [WorkflowAdminController::class, 'revokeDelegation'])->middleware(['permission:workflow.delegation.manage', 'throttle:workflow-write']);

    // ------------------------------------------------------------- SLA & eskalasi
    Route::get('sla', [WorkflowAdminController::class, 'sla'])->middleware('permission:workflow.sla.view');
    Route::post('sla/sweep', [WorkflowAdminController::class, 'sweepSla'])->middleware(['permission:workflow.sla.manage', 'throttle:workflow-write']);
    Route::get('escalations', [WorkflowAdminController::class, 'escalations'])->middleware('permission:workflow.sla.view');

    // --------------------------------------------------------------- Kalender
    Route::get('calendars', [WorkflowAdminController::class, 'calendars'])->middleware('permission:workflow.settings.view');
    Route::post('calendars', [WorkflowAdminController::class, 'storeCalendar'])->middleware(['permission:workflow.settings.manage', 'throttle:workflow-write']);
    Route::post('calendars/{calendar}/holidays', [WorkflowAdminController::class, 'storeHoliday'])->middleware(['permission:workflow.settings.manage', 'throttle:workflow-write']);
    Route::delete('holidays/{holiday}', [WorkflowAdminController::class, 'destroyHoliday'])->middleware(['permission:workflow.settings.manage', 'throttle:workflow-write']);

    // --------------------------------------------------------------- Direktori
    Route::get('directory', [WorkflowAdminController::class, 'directory'])->middleware('permission:workflow.settings.view');
    Route::post('directory/assignments', [WorkflowAdminController::class, 'storeAssignment'])->middleware(['permission:workflow.directory.manage', 'throttle:workflow-write']);
    Route::delete('directory/assignments/{assignment}', [WorkflowAdminController::class, 'destroyAssignment'])->middleware(['permission:workflow.directory.manage', 'throttle:workflow-write']);
    Route::post('directory/reports', [WorkflowAdminController::class, 'storeReport'])->middleware(['permission:workflow.directory.manage', 'throttle:workflow-write']);

    // --------------------------------------------------------------- Analitik
    Route::get('dashboard', [WorkflowAdminController::class, 'dashboard'])->middleware(['permission:workflow.analytics.view', 'throttle:workflow-analytics']);
    Route::get('analytics', [WorkflowAdminController::class, 'analytics'])->middleware(['permission:workflow.analytics.view', 'throttle:workflow-analytics']);

    // ------------------------------------------------------------------ Audit
    Route::get('audit', [WorkflowAdminController::class, 'auditLog'])->middleware(['permission:workflow.audit.view', 'throttle:workflow-analytics']);
    Route::post('audit/verify', [WorkflowAdminController::class, 'verifyAudit'])->middleware(['permission:workflow.audit.view', 'throttle:workflow-analytics']);
});
