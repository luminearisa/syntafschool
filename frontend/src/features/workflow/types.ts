/**
 * Tipe Modul Workflow & Approval Engine.
 *
 * Cermin dari respons `/api/v1/workflows/*`. Sengaja ditulis lengkap (bukan
 * `any`) karena layar visualizer, timeline, dan inbox semuanya bergantung pada
 * bentuk data yang sama — kesalahan ketik tipe akan langsung terlihat saat
 * `tsc`, bukan saat produksi.
 */

export type InstanceStatus =
  | 'DRAFT'
  | 'SUBMITTED'
  | 'IN_PROGRESS'
  | 'WAITING_APPROVAL'
  | 'WAITING_REVIEW'
  | 'WAITING_FOR_APPROVER'
  | 'RETURNED'
  | 'REJECTED'
  | 'APPROVED'
  | 'CANCELLED'
  | 'EXPIRED'
  | 'FAILED';

export type NodeType =
  | 'START'
  | 'APPROVAL'
  | 'REVIEW'
  | 'CONDITION'
  | 'PARALLEL'
  | 'NOTIFICATION'
  | 'AUTOMATIC'
  | 'END';

export type ApprovalMode = 'SEQUENTIAL' | 'PARALLEL' | 'HYBRID';
export type QuorumPolicy = 'ALL' | 'ANY' | 'MINIMUM_N' | 'PERCENTAGE';
export type DecisionValue = 'APPROVE' | 'REJECT' | 'RETURN' | 'REQUEST_CHANGES' | 'SKIP';
export type SlaStatus = 'ON_TIME' | 'DUE_SOON' | 'OVERDUE' | 'ESCALATED' | 'COMPLETED' | 'CANCELLED';

export interface WorkflowMeta {
  node_types: Array<{ value: NodeType; label: string }>;
  approval_modes: Array<{ value: ApprovalMode; label: string }>;
  quorum_policies: Array<{ value: QuorumPolicy; label: string }>;
  condition_operators: Array<{ value: string; label: string }>;
  escalation_actions: Array<{ value: string; label: string }>;
  resolvers: Array<{ alias: string; description: string; required_spec: Record<string, string> }>;
  actions: Array<{ alias: string; description: string }>;
  policies: { empty_approver: string[]; return_strategy: string[] };
  subject_types: string[];
  calendar: { default: string; respect_working_hours: boolean };
}

/** Spesifikasi approver pada konfigurasi node. */
export interface ApproverSpec {
  resolver: string;
  [key: string]: unknown;
}

export interface ConditionLeaf {
  field?: string;
  operator?: string;
  value?: unknown;
  all?: ConditionLeaf[];
  any?: ConditionLeaf[];
  not?: ConditionLeaf;
  logic?: string;
  clauses?: ConditionLeaf[];
}

export interface WorkflowNodeConfig {
  key: string;
  type: NodeType;
  name: string;
  description?: string | null;
  sequence: number;
  approval_mode?: ApprovalMode | null;
  quorum_policy?: QuorumPolicy | null;
  quorum_value?: number | null;
  sla_minutes?: number | null;
  required?: boolean;
  allow_skip?: boolean;
  allow_reassign?: boolean;
  self_approval_rule?: 'INHERIT' | 'FORBID' | 'ALLOW';
  approvers?: ApproverSpec[];
  tiers?: ApproverSpec[][];
  condition_code?: string | null;
  condition?: ConditionLeaf | null;
  escalation?: Record<string, unknown> | null;
  actions?: Record<string, Array<{ handler: string; params?: Record<string, unknown> }>> | null;
  notification?: Record<string, unknown> | null;
  config?: Record<string, unknown> | null;
}

export interface WorkflowEdgeConfig {
  from_key: string;
  to_key: string;
  label?: string | null;
  condition_code?: string | null;
  condition?: ConditionLeaf | null;
  priority?: number;
  is_default?: boolean;
}

export interface WorkflowVersionDetail {
  id: number;
  definition_id: number;
  definition?: string;
  definition_name?: string;
  module?: string;
  version: number;
  label?: string | null;
  status: 'DRAFT' | 'TESTING' | 'ACTIVE' | 'INACTIVE' | 'ARCHIVED';
  changelog?: string | null;
  config?: Record<string, unknown> | null;
  graph_hash?: string | null;
  editable: boolean;
  published_at?: string | null;
  activated_at?: string | null;
  nodes: WorkflowNodeConfig[];
  edges: WorkflowEdgeConfig[];
  conditions: Array<{ code: string; name: string; logic: string; expression?: ConditionLeaf | null; description?: string | null }>;
  actions: Array<{ key: string; name?: string | null; type: string; handler?: string | null; params?: Record<string, unknown> | null; on_failure: string; is_active: boolean }>;
  instances_count: number;
}

export interface WorkflowDefinitionRow {
  id: number;
  code: string;
  name: string;
  description?: string | null;
  module: string;
  event?: string | null;
  entity_type?: string | null;
  category?: string | null;
  status: 'DRAFT' | 'TESTING' | 'ACTIVE' | 'INACTIVE' | 'ARCHIVED';
  status_label: string;
  trigger_type: string;
  owner?: string | null;
  owner_user_id?: number | null;
  owner_department?: string | null;
  allow_self_approval: boolean;
  required_context: string[];
  comment_required_on: string[];
  observers: unknown[];
  settings?: Record<string, unknown> | null;
  current_version?: { id: number; version: number; status: string } | null;
  instances_count: number;
  instances_open: number;
  created_at?: string | null;
  versions?: Array<{
    id: number;
    version: number;
    status: string;
    label?: string | null;
    changelog?: string | null;
    graph_hash?: string | null;
    published_at?: string | null;
    activated_at?: string | null;
    archived_at?: string | null;
    config?: Record<string, unknown> | null;
    instances_count: number;
  }>;
}

export interface WorkflowApprovalRow {
  id: number;
  instance_id: number;
  instance_code: string;
  title: string;
  module: string;
  subject_type?: string;
  subject_id?: string;
  subject_reference?: string | null;
  definition?: string;
  definition_name?: string;
  requester?: string | null;
  requester_id?: number;
  department?: string | null;
  amount?: string | number | null;
  currency?: string;
  priority?: string;
  instance_status?: InstanceStatus;
  step_instance_id: number;
  step?: string;
  node_key?: string;
  step_status?: string;
  status: string;
  source: string;
  resolver?: string | null;
  is_delegate: boolean;
  original_approver_id?: number | null;
  original_approver?: string | null;
  delegation_id?: number | null;
  assigned_at?: string | null;
  due_at?: string | null;
  sla_status?: SlaStatus | null;
  remaining_minutes?: number | null;
  responded_at?: string | null;
  can_decide?: boolean;
}

export interface WorkflowStepRow {
  id: number;
  node_key: string;
  node_type: NodeType;
  name: string;
  sequence: number;
  round: number;
  status: string;
  status_label: string;
  result?: string | null;
  approval_mode?: ApprovalMode | null;
  quorum: { policy?: QuorumPolicy | null; value?: number | null; total: number; approved: number; remaining: number };
  activated_at?: string | null;
  due_at?: string | null;
  completed_at?: string | null;
  sla_status?: SlaStatus | null;
  sla_minutes?: number | null;
  approvers: Array<{
    approval_id: number;
    user_id: number;
    name?: string | null;
    status: string;
    source: string;
    is_delegate: boolean;
    original_approver?: string | null;
    due_at?: string | null;
    responded_at?: string | null;
  }>;
  warnings: string[];
}

export interface WorkflowGraphNode {
  key: string;
  name: string;
  type: NodeType;
  sequence: number;
  status: 'APPROVED' | 'REJECTED' | 'RETURNED' | 'SKIPPED' | 'WAITING' | 'PENDING';
  approval_mode?: ApprovalMode | null;
  quorum?: QuorumPolicy | null;
  sla_minutes?: number | null;
  sla_status?: SlaStatus | null;
  due_at?: string | null;
  round?: number | null;
}

export interface WorkflowTimelineEntry {
  at: string | null;
  kind: 'event' | 'decision' | 'submission';
  type: string;
  actor?: string | null;
  actor_id?: number | null;
  detail?: Record<string, unknown> | null;
}

export interface WorkflowInstanceRow {
  id: number;
  code: string;
  title: string;
  module: string;
  definition?: string;
  definition_name?: string;
  version?: number;
  subject_type: string;
  subject_id: string;
  subject_reference?: string | null;
  requester_id: number;
  requester?: string | null;
  department?: string | null;
  status: InstanceStatus;
  status_label: string;
  priority: string;
  amount?: string | number | null;
  currency?: string;
  submission_number: number;
  /** Ringkasan langkah manusia. Opsional: sebagian endpoint meringkas tanpa progres. */
  progress?: { total: number; completed: number; rejected: number; returned: number; cancelled: number; active: number; percent: number };
  current_nodes: string[];
  submitted_at?: string | null;
  due_at?: string | null;
  completed_at?: string | null;
  cancelled_at?: string | null;
  failure_reason?: string | null;
  created_at?: string | null;
  can_resubmit?: boolean;
  can_cancel?: boolean;
  cancel_reason?: string | null;
  metadata?: Record<string, unknown> | null;
}

export interface WorkflowInstanceDetail extends WorkflowInstanceRow {
  context_summary?: Record<string, unknown> | null;
  steps: WorkflowStepRow[];
  graph: { nodes: WorkflowGraphNode[]; edges: WorkflowEdgeConfig[]; state: Record<string, unknown> };
  approvals: Array<{
    id: number;
    step_instance_id: number;
    approver_id: number;
    approver?: string | null;
    status: string;
    source: string;
    is_delegate: boolean;
    original_approver?: string | null;
    delegation_id?: number | null;
    due_at?: string | null;
    responded_at?: string | null;
  }>;
  decisions: Array<{
    id: number;
    step_instance_id: number;
    decision: string;
    actor_id: number;
    actor?: string | null;
    original_approver?: string | null;
    comment?: string | null;
    is_override: boolean;
    decided_at?: string | null;
  }>;
  comments: Array<{ id: number; body: string; author?: string | null; author_id: number; is_internal: boolean; created_at?: string | null }>;
  attachments: Array<{ id: number; label?: string | null; original_name?: string | null; source: string; external_ref?: string | null; file_path?: string | null; created_at?: string | null }>;
  timeline: WorkflowTimelineEntry[];
  approval_progress: { total: number; completed: number; active: number; percent: number };
}

export interface WorkflowSimulationResult {
  definition?: string | null;
  version: number;
  version_status: string;
  context: Record<string, unknown>;
  context_hash: string;
  path: Array<{
    node_key: string;
    name: string;
    type: NodeType;
    sequence: number;
    sla_minutes?: number | null;
    sla_human?: string | null;
    approvers: Array<{ user_id: number; name?: string | null; source: string; resolver: string; is_delegate: boolean; original_user_id?: number | null }>;
    approval_mode?: string;
    quorum?: { policy: string; value?: number | null; required: number };
    condition_matched?: string | null;
    condition_target?: string | null;
    dry_run_ok?: boolean;
    skipped?: boolean;
    skipped_reason?: string;
    warnings: string[];
  }>;
  warnings: string[];
  errors: string[];
  valid: boolean;
  estimated_steps: number;
  estimated_work_minutes: number;
  requester_id: number;
}

export interface WorkflowDelegationRow {
  id: number;
  delegator_id: number;
  delegator?: string | null;
  delegate_id: number;
  delegate?: string | null;
  scope?: Record<string, unknown> | null;
  reason?: string | null;
  start_at?: string | null;
  end_at?: string | null;
  status: 'PENDING' | 'ACTIVE' | 'REVOKED' | 'EXPIRED';
  status_label: string;
  activated_at?: string | null;
  revoked_at?: string | null;
  revoke_reason?: string | null;
  is_active_now: boolean;
}

export interface WorkflowDashboard {
  period_days: number;
  definitions_active: number;
  instances_open: number;
  instances_created: number;
  instances_completed: number;
  approved: number;
  rejected: number;
  returned: number;
  failed: number;
  expired: number;
  pending_approvals: number;
  overdue_approvals: number;
  due_soon_approvals: number;
  escalations: number;
  average_approval_hours: number | null;
  rejection_rate: number | null;
  return_rate: number | null;
  escalation_rate: number | null;
  sla: {
    total: number;
    on_time: number;
    overdue_open: number;
    due_soon_open: number;
    compliance_percent: number | null;
    average_minutes: number | null;
  };
}

export interface WorkflowDashboardPayload {
  dashboard: WorkflowDashboard;
  volume_by_day: Array<{ date: string; created: number; completed: number }>;
  volume_by_module: { by_module: Array<{ module: string; total: number }>; by_department: Array<{ department: string; total: number }> };
  decision_breakdown: Array<{ decision: string; label: string; total: number; percent: number }>;
  escalation_breakdown: Array<{ action: string; label: string; total: number }>;
  bottlenecks: Array<Record<string, unknown>>;
  workload: Array<{ approver_id: number; pending: number; overdue: number }>;
}

export interface WorkflowCalendarRow {
  id: number;
  code: string;
  name: string;
  timezone: string;
  work_days: number[];
  work_start: string;
  work_end: string;
  is_default: boolean;
  is_active: boolean;
  holidays: Array<{ id: number; date: string; name: string; is_working_day: boolean }>;
}

export interface WorkflowDirectoryPayload {
  scope_types: Array<{ value: string; label: string }>;
  assignments: Array<{
    id: number;
    scope_type: string;
    scope_key: string;
    user_id: number;
    user?: string | null;
    is_primary: boolean;
    priority: number;
    is_active: boolean;
    valid_from?: string | null;
    valid_to?: string | null;
  }>;
  reports: Array<{
    id: number;
    user_id: number;
    user?: string | null;
    manager_user_id: number;
    manager?: string | null;
    backup_user_id?: number | null;
    backup_manager?: string | null;
    effective_from?: string | null;
    effective_to?: string | null;
  }>;
  resolved_example: { requester_department?: string | null; requester_manager?: number | null };
}

export interface WorkflowSlaRow {
  id: number;
  instance?: string | null;
  title?: string | null;
  module?: string | null;
  step?: string | null;
  node_key?: string | null;
  step_status?: string | null;
  sla_minutes: number;
  sla_human?: string | null;
  started_at?: string | null;
  due_at?: string | null;
  status: SlaStatus;
  escalation_count: number;
  remaining_minutes?: number | null;
}

export interface WorkflowAuditRow {
  id: number;
  at?: string | null;
  action: string;
  instance?: string | null;
  title?: string | null;
  actor?: string | null;
  actor_id?: number | null;
  reason?: string | null;
  before?: Record<string, unknown> | null;
  after?: Record<string, unknown> | null;
  ip_address?: string | null;
  hash: string;
  previous_hash?: string | null;
}
