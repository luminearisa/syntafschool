import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  WorkflowApprovalRow,
  WorkflowAuditRow,
  WorkflowCalendarRow,
  WorkflowDashboardPayload,
  WorkflowDefinitionRow,
  WorkflowDelegationRow,
  WorkflowDirectoryPayload,
  WorkflowInstanceDetail,
  WorkflowInstanceRow,
  WorkflowMeta,
  WorkflowSimulationResult,
  WorkflowSlaRow,
  WorkflowVersionDetail,
} from '../types';

/**
 * Klien API Modul Workflow.
 *
 * Semua endpoint memakai Bearer token dari `lib/axios`; otorisasi halus tetap
 * ditegakkan backend (izin `workflow.*` + cakupan baris), sehingga UI tidak
 * pernah menjadi satu-satunya penjaga.
 */
export const workflowApi = {
  // ------------------------------------------------------------- konfigurasi
  meta: () => api.get<ApiResponse<WorkflowMeta>>('/workflows/meta').then((r) => r.data.data),

  definitions: (params: Record<string, unknown> = {}) =>
    api.get<PaginatedResponse<WorkflowDefinitionRow>>('/workflows/definitions', { params }).then((r) => r.data),

  definition: (id: number) =>
    api.get<ApiResponse<WorkflowDefinitionRow>>(`/workflows/definitions/${id}`).then((r) => r.data.data),

  createDefinition: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<WorkflowDefinitionRow>>('/workflows/definitions', payload).then((r) => r.data.data),

  updateDefinition: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<WorkflowDefinitionRow>>(`/workflows/definitions/${id}`, payload).then((r) => r.data.data),

  deactivateDefinition: (id: number, reason: string) =>
    api.post<ApiResponse<WorkflowDefinitionRow>>(`/workflows/definitions/${id}/deactivate`, { reason }).then((r) => r.data.data),

  createVersion: (definitionId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<WorkflowVersionDetail>>(`/workflows/definitions/${definitionId}/versions`, payload).then((r) => r.data.data),

  version: (id: number) =>
    api.get<ApiResponse<WorkflowVersionDetail>>(`/workflows/versions/${id}`).then((r) => r.data.data),

  saveVersion: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<WorkflowVersionDetail>>(`/workflows/versions/${id}`, payload).then((r) => r.data.data),

  validateVersion: (id: number) =>
    api
      .post<ApiResponse<{ valid: boolean; errors: string[]; warnings: string[] }>>(`/workflows/versions/${id}/validate`)
      .then((r) => r.data.data),

  publishVersion: (id: number, payload: { changelog?: string; activate?: boolean } = {}) =>
    api.post<ApiResponse<WorkflowVersionDetail>>(`/workflows/versions/${id}/publish`, payload).then((r) => r.data.data),

  archiveVersion: (id: number, reason: string) =>
    api.post<ApiResponse<WorkflowVersionDetail>>(`/workflows/versions/${id}/archive`, { reason }).then((r) => r.data.data),

  simulate: (definitionId: number, context: Record<string, unknown>, versionId?: number) =>
    api
      .post<ApiResponse<WorkflowSimulationResult>>(`/workflows/definitions/${definitionId}/simulate`, {
        context,
        version_id: versionId,
      })
      .then((r) => r.data.data),

  // ------------------------------------------------------------------ inbox
  counter: () =>
    api
      .get<ApiResponse<{ pending: number; overdue: number; due_soon: number; my_requests_open: number; my_requests_returned: number }>>(
        '/workflows/inbox/counter',
      )
      .then((r) => r.data.data),

  myApprovals: (params: Record<string, unknown> = {}) =>
    api.get<PaginatedResponse<WorkflowApprovalRow>>('/workflows/inbox/approvals', { params }).then((r) => r.data),

  myRequests: (params: Record<string, unknown> = {}) =>
    api.get<PaginatedResponse<WorkflowInstanceRow>>('/workflows/inbox/requests', { params }).then((r) => r.data),

  filterOptions: () =>
    api
      .get<ApiResponse<{ modules: string[]; statuses: string[]; priorities: string[]; buckets: string[] }>>('/workflows/inbox/filters')
      .then((r) => r.data.data),

  // --------------------------------------------------------------- instance
  instances: (params: Record<string, unknown> = {}) =>
    api.get<PaginatedResponse<WorkflowInstanceRow>>('/workflows/instances', { params }).then((r) => r.data),

  instance: (id: number) =>
    api.get<ApiResponse<WorkflowInstanceDetail>>(`/workflows/instances/${id}`).then((r) => r.data.data),

  createInstance: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<WorkflowInstanceDetail>>('/workflows/instances', payload).then((r) => r.data.data),

  submitInstance: (id: number, payload: Record<string, unknown> = {}) =>
    api.post<ApiResponse<WorkflowInstanceDetail>>(`/workflows/instances/${id}/submit`, payload).then((r) => r.data.data),

  cancelInstance: (id: number, reason: string) =>
    api.post<ApiResponse<WorkflowInstanceDetail>>(`/workflows/instances/${id}/cancel`, { reason }).then((r) => r.data.data),

  reviseInstance: (id: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<WorkflowInstanceDetail>>(`/workflows/instances/${id}/revise`, payload).then((r) => r.data.data),

  timeline: (id: number) =>
    api
      .get<ApiResponse<{ instance: WorkflowInstanceRow; timeline: unknown[]; steps: unknown[]; graph: unknown }>>(`/workflows/instances/${id}/timeline`)
      .then((r) => r.data.data),

  contextSnapshots: (id: number) =>
    api
      .get<ApiResponse<{ instance: string; snapshots: Array<Record<string, unknown>>; current: Record<string, unknown>; age_days: number | null }>>(
        `/workflows/instances/${id}/context`,
      )
      .then((r) => r.data.data),

  comment: (id: number, body: string) =>
    api.post<ApiResponse<Record<string, unknown>>>(`/workflows/instances/${id}/comments`, { body }).then((r) => r.data.data),

  dryRun: (id: number) =>
    api
      .post<ApiResponse<{ healthy: boolean; blockers: string[]; active_steps: Array<Record<string, unknown>>; action_errors: string[] }>>(
        `/workflows/instances/${id}/dry-run`,
      )
      .then((r) => r.data.data),

  assignApprovers: (instanceId: number, stepId: number, userIds: number[], reason?: string) =>
    api
      .post<ApiResponse<Record<string, unknown>>>(`/workflows/instances/${instanceId}/steps/${stepId}/assign`, {
        user_ids: userIds,
        reason,
      })
      .then((r) => r.data.data),

  skipStep: (instanceId: number, stepId: number, reason: string) =>
    api
      .post<ApiResponse<Record<string, unknown>>>(`/workflows/instances/${instanceId}/steps/${stepId}/skip`, { reason })
      .then((r) => r.data.data),

  instanceAudit: (id: number) =>
    api
      .get<ApiResponse<{ instance: string; logs: WorkflowAuditRow[]; chain: { valid: boolean; checked: number; broken_at: number | null } }>>(
        `/workflows/instances/${id}/audit`,
      )
      .then((r) => r.data.data),

  // --------------------------------------------------------------- approval
  decide: (approvalId: number, decision: 'approve' | 'reject' | 'return' | 'request-changes', payload: { comment?: string; override_reason?: string; idempotencyKey?: string }) =>
    api
      .post<ApiResponse<Record<string, unknown>>>(`/workflows/approvals/${approvalId}/${decision}`, payload, {
        headers: payload.idempotencyKey ? { 'Idempotency-Key': payload.idempotencyKey } : undefined,
      })
      .then((r) => r.data),

  reassign: (approvalId: number, toUserId: number, reason: string) =>
    api
      .post<ApiResponse<Record<string, unknown>>>(`/workflows/approvals/${approvalId}/reassign`, { to_user_id: toUserId, reason })
      .then((r) => r.data.data),

  approval: (id: number) => api.get<ApiResponse<WorkflowApprovalRow>>(`/workflows/approvals/${id}`).then((r) => r.data.data),

  approvals: (params: Record<string, unknown> = {}) =>
    api.get<PaginatedResponse<WorkflowApprovalRow>>('/workflows/approvals', { params }).then((r) => r.data),

  // ------------------------------------------------------------- delegasi
  delegations: (params: Record<string, unknown> = {}) =>
    api.get<PaginatedResponse<WorkflowDelegationRow>>('/workflows/delegations', { params }).then((r) => r.data),

  createDelegation: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<WorkflowDelegationRow>>('/workflows/delegations', payload).then((r) => r.data.data),

  revokeDelegation: (id: number, reason?: string) =>
    api.post<ApiResponse<WorkflowDelegationRow>>(`/workflows/delegations/${id}/revoke`, { reason }).then((r) => r.data.data),

  // ------------------------------------------------------------ SLA & audit
  sla: (params: Record<string, unknown> = {}) =>
    api.get<PaginatedResponse<WorkflowSlaRow>>('/workflows/sla', { params }).then((r) => r.data),

  sweepSla: (limit = 500) =>
    api
      .post<ApiResponse<{ reminded: number; due_soon: number; overdue: number; escalated: number }>>('/workflows/sla/sweep', { limit })
      .then((r) => r.data.data),

  escalations: (params: Record<string, unknown> = {}) =>
    api
      .get<ApiResponse<{ summary: { total: number; by_level: Array<{ level: number; total: number }> }; rows: PaginatedResponse<Record<string, unknown>> }>>(
        '/workflows/escalations',
        { params },
      )
      .then((r) => r.data.data),

  analytics: (params: Record<string, unknown> = {}) =>
    api.get<ApiResponse<Record<string, unknown>>>('/workflows/analytics', { params }).then((r) => r.data.data),

  dashboard: (params: Record<string, unknown> = {}) =>
    api.get<ApiResponse<WorkflowDashboardPayload>>('/workflows/dashboard', { params }).then((r) => r.data.data),

  auditLog: (params: Record<string, unknown> = {}) =>
    api
      .get<ApiResponse<{ summary: Array<{ action: string; total: number }>; logs: PaginatedResponse<WorkflowAuditRow> }>>('/workflows/audit', { params })
      .then((r) => r.data.data),

  verifyAudit: (instanceId?: number) =>
    api
      .post<ApiResponse<{ valid: boolean; checked: number; broken_at: number | null }>>('/workflows/audit/verify', {
        instance_id: instanceId,
      })
      .then((r) => r.data.data),

  // ------------------------------------------------------- kalender & direktori
  calendars: () =>
    api
      .get<ApiResponse<{ calendars: WorkflowCalendarRow[]; config: Record<string, unknown> }>>('/workflows/calendars')
      .then((r) => r.data.data),

  saveCalendar: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<{ id: number; code: string }>>('/workflows/calendars', payload).then((r) => r.data.data),

  saveHoliday: (calendarId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<{ id: number; date: string }>>(`/workflows/calendars/${calendarId}/holidays`, payload).then((r) => r.data.data),

  deleteHoliday: (holidayId: number) => api.delete(`/workflows/holidays/${holidayId}`).then((r) => r.data),

  directory: () => api.get<ApiResponse<WorkflowDirectoryPayload>>('/workflows/directory').then((r) => r.data.data),

  saveAssignment: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<{ id: number }>>('/workflows/directory/assignments', payload).then((r) => r.data.data),

  deactivateAssignment: (id: number) => api.delete(`/workflows/directory/assignments/${id}`).then((r) => r.data),

  saveReport: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<{ id: number }>>('/workflows/directory/reports', payload).then((r) => r.data.data),

  health: () => api.get<ApiResponse<Record<string, unknown>>>('/workflows/health').then((r) => r.data.data),
};
