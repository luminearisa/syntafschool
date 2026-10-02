import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get, post } from './client';
import { useAuth } from '@/store/auth';
import { newIdempotencyKey } from '@/lib/idempotency';

export interface ApprovalRow {
  id: number;
  instance_id: number;
  instance_code?: string | null;
  title?: string | null;
  module?: string | null;
  definition_name?: string | null;
  requester?: string | null;
  department?: string | null;
  amount?: string | number | null;
  currency?: string | null;
  priority?: string | null;
  step?: string | null;
  status: string;
  is_delegate: boolean;
  original_approver?: string | null;
  assigned_at?: string | null;
  due_at?: string | null;
  sla_status?: string | null;
  remaining_minutes?: number | null;
}

export interface RequestRow {
  id: number;
  code: string;
  title: string;
  module?: string | null;
  definition_name?: string | null;
  status: string;
  status_label: string;
  priority?: string | null;
  amount?: string | number | null;
  submitted_at?: string | null;
  progress?: { total: number; completed: number; percent: number } | null;
  can_resubmit?: boolean;
  can_cancel?: boolean;
}

export interface Counter {
  pending: number;
  overdue: number;
  due_soon: number;
  my_requests_open: number;
  my_requests_returned: number;
}

export interface InstanceDetail {
  id: number;
  code: string;
  title: string;
  status: string;
  status_label?: string;
  module?: string | null;
  context_summary?: Record<string, unknown> | string | null;
  decisions: { id: number; decision: string; actor?: string | null; comment?: string | null; decided_at?: string | null }[];
  comments: { id: number; body: string; author?: string | null; created_at?: string | null }[];
  approvals: { id: number; approver?: string | null; status: string; due_at?: string | null }[];
  steps?: { name?: string; status?: string }[];
}

export type Bucket = 'pending' | 'overdue' | 'due_soon' | 'today';
type Page<T> = { data: T[] };

const useRole = () => useAuth((s) => s.active ?? 'none');

export const useApprovalCounter = () => {
  const role = useRole();
  return useQuery({ queryKey: [role, 'wf-counter'], queryFn: () => get<Counter>('/workflows/inbox/counter'), enabled: role === 'employee' });
};

export const useApprovals = (bucket: Bucket) => {
  const role = useRole();
  return useQuery({
    queryKey: [role, 'wf-approvals', bucket],
    queryFn: async () => (await api.get<Page<ApprovalRow>>('/workflows/inbox/approvals', { params: { bucket, per_page: 30 } })).data.data,
    enabled: role === 'employee',
  });
};

export const useMyRequests = () => {
  const role = useRole();
  return useQuery({
    queryKey: [role, 'wf-requests'],
    queryFn: async () => (await api.get<Page<RequestRow>>('/workflows/inbox/requests', { params: { per_page: 30 } })).data.data,
    enabled: role === 'employee',
  });
};

export const useInstance = (id: number | null) =>
  useQuery({ queryKey: ['wf-instance', id], queryFn: () => get<InstanceDetail>(`/workflows/instances/${id}`), enabled: id !== null });

const invalidate = (qc: ReturnType<typeof useQueryClient>) => {
  qc.invalidateQueries({ queryKey: ['employee', 'wf-approvals'] });
  qc.invalidateQueries({ queryKey: ['employee', 'wf-requests'] });
  qc.invalidateQueries({ queryKey: ['employee', 'wf-counter'] });
  qc.invalidateQueries({ queryKey: ['employee', 'employee-home'] });
  qc.invalidateQueries({ queryKey: ['wf-instance'] });
};

export type Decision = 'approve' | 'reject' | 'return' | 'request-changes';

/** Keputusan approval. `key` dibuat sekali per percobaan pengguna agar ulang jaringan idempoten. */
export function useDecide() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (v: { approvalId: number; decision: Decision; comment?: string; key: string }) => {
      const res = await api.post(`/workflows/approvals/${v.approvalId}/${v.decision}`, { comment: v.comment || null }, { headers: { 'Idempotency-Key': v.key } });
      return res.data;
    },
    onSuccess: () => invalidate(qc),
  });
}

export function useCancelRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; reason: string }) => post(`/workflows/instances/${v.id}/cancel`, { reason: v.reason }),
    onSuccess: () => invalidate(qc),
  });
}

export function useResubmit() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; reason: string }) => post(`/workflows/instances/${v.id}/revise`, { revision_reason: v.reason }),
    onSuccess: () => invalidate(qc),
  });
}

export function useComment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; body: string }) => post(`/workflows/instances/${v.id}/comments`, { body: v.body }),
    onSuccess: () => invalidate(qc),
  });
}

export { newIdempotencyKey };
