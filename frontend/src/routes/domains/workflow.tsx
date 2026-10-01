import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import WorkflowAnalyticsPage from '@/features/workflow/pages/WorkflowAnalyticsPage';
import WorkflowAuditPage from '@/features/workflow/pages/WorkflowAuditPage';
import WorkflowBuilderPage from '@/features/workflow/pages/WorkflowBuilderPage';
import WorkflowDashboardPage from '@/features/workflow/pages/WorkflowDashboardPage';
import WorkflowDefinitionsPage from '@/features/workflow/pages/WorkflowDefinitionsPage';
import WorkflowDelegationsPage from '@/features/workflow/pages/WorkflowDelegationsPage';
import WorkflowInboxPage from '@/features/workflow/pages/WorkflowInboxPage';
import WorkflowInstancePage from '@/features/workflow/pages/WorkflowInstancePage';
import WorkflowMyRequestsPage from '@/features/workflow/pages/WorkflowMyRequestsPage';
import WorkflowSettingsPage from '@/features/workflow/pages/WorkflowSettingsPage';

/**
 * Modul Workflow & Approval Engine.
 *
 * Otorisasi layar ditegakkan backend (`workflow.*`); guard di sini hanya
 * menyembunyikan halaman yang pasti ditolak. Cakupan baris (request milik siapa
 * yang terlihat) ditegakkan `WorkflowAccessService` di backend — termasuk bila
 * ID request ditebak.
 */
const guard = (permission: string | string[], element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'workflow', element: guard('workflow.view', <WorkflowDashboardPage />) },
  { path: 'workflow/approvals', element: guard('workflow.approval.view', <WorkflowInboxPage />) },
  { path: 'workflow/requests', element: guard('workflow.request.view', <WorkflowMyRequestsPage />) },
  { path: 'workflow/instances/:instanceId', element: guard('workflow.request.view', <WorkflowInstancePage />) },
  { path: 'workflow/definitions', element: guard('workflow.definition.view', <WorkflowDefinitionsPage />) },
  { path: 'workflow/definitions/:definitionId/builder', element: guard('workflow.definition.manage', <WorkflowBuilderPage />) },
  { path: 'workflow/versions/:versionId/builder', element: guard('workflow.definition.manage', <WorkflowBuilderPage />) },
  { path: 'workflow/delegations', element: guard('workflow.delegation.view', <WorkflowDelegationsPage />) },
  { path: 'workflow/analytics', element: guard('workflow.analytics.view', <WorkflowAnalyticsPage />) },
  { path: 'workflow/audit', element: guard('workflow.audit.view', <WorkflowAuditPage />) },
  { path: 'workflow/settings', element: guard('workflow.settings.view', <WorkflowSettingsPage />) },
];

export const workflowMenu: MenuChild[] = [
  { label: 'Dasbor Workflow', to: '/workflow', permission: 'workflow.view' },
  { label: 'Persetujuan Saya', to: '/workflow/approvals', permission: 'workflow.approval.view' },
  { label: 'Permintaan Saya', to: '/workflow/requests', permission: 'workflow.request.view' },
  { label: 'Definisi & Builder', to: '/workflow/definitions', permission: 'workflow.definition.view' },
  { label: 'Delegasi', to: '/workflow/delegations', permission: 'workflow.delegation.view' },
  { label: 'Analitik', to: '/workflow/analytics', permission: 'workflow.analytics.view' },
  { label: 'Audit & Jejak', to: '/workflow/audit', permission: 'workflow.audit.view' },
  { label: 'Pengaturan', to: '/workflow/settings', permission: 'workflow.settings.view' },
];
