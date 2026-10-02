import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get, post } from './client';
import { useAuth } from '@/store/auth';
import { newIdempotencyKey } from '@/lib/idempotency';

/**
 * API sisi wali murid/siswa. Semua rute di bawah dipagari server: kepemilikan
 * anak ditegakkan tiap modul (assertChild / provider orang tua), konteks token
 * oleh EnforceContext — klien tidak menentukan hak akses.
 */
type Row = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
const useRole = () => useAuth((s) => s.active ?? 'none');
const enabledFor = (role: string, id?: number | null) => (role === 'guardian' || role === 'student') && !!id;

async function list<T = Row>(path: string, params?: Record<string, unknown>): Promise<T[]> {
  const res = await api.get<{ data: T[] }>(path, { params });
  return res.data.data;
}

// ============================================================ KEUANGAN
export const useFinInvoices = (id?: number, outstanding = false) => {
  const role = useRole();
  return useQuery({ queryKey: ['fin', id, 'invoices', outstanding], queryFn: () => list(`/finance/portal/children/${id}/invoices`, { outstanding: outstanding ? 1 : undefined, per_page: 50 }), enabled: enabledFor(role, id) });
};
export const useFinPayments = (id?: number) => {
  const role = useRole();
  return useQuery({ queryKey: ['fin', id, 'payments'], queryFn: () => list(`/finance/portal/children/${id}/payments`, { per_page: 50 }), enabled: enabledFor(role, id) });
};
export const useFinReceipts = (id?: number) => {
  const role = useRole();
  return useQuery({ queryKey: ['fin', id, 'receipts'], queryFn: () => list(`/finance/portal/children/${id}/receipts`, { per_page: 50 }), enabled: enabledFor(role, id) });
};
export const useFinPlans = (id?: number) => {
  const role = useRole();
  return useQuery({ queryKey: ['fin', id, 'plans'], queryFn: () => list(`/finance/portal/children/${id}/payment-plans`), enabled: enabledFor(role, id) });
};
export const useFinReReg = (id?: number) => {
  const role = useRole();
  return useQuery({ queryKey: ['fin', id, 'rereg'], queryFn: () => list(`/finance/portal/children/${id}/re-registrations`), enabled: enabledFor(role, id) });
};
export const useFinSummary = (id?: number) => {
  const role = useRole();
  return useQuery({ queryKey: ['fin', id, 'summary'], queryFn: () => get<Row>(`/finance/portal/children/${id}/summary`), enabled: enabledFor(role, id) });
};

export function usePayInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { studentId: number; invoiceId: number; amount?: number; key: string }) =>
      api.post<{ data: { payment_number: string; amount: number; redirect_url: string | null; expires_at: string | null } }>(
        `/finance/portal/children/${v.studentId}/invoices/${v.invoiceId}/pay`, { amount: v.amount }, { headers: { 'Idempotency-Key': v.key } },
      ).then((r) => r.data.data),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['fin', v.studentId] }),
  });
}

// ============================================================== ASRAMA
export const useBoardingSummary = (id?: number) => {
  const role = useRole();
  return useQuery({ queryKey: ['boarding', id, 'summary'], queryFn: () => get<Row>(`/boarding/portal/children/${id}/summary`), enabled: enabledFor(role, id) });
};
export const useBoardingList = (id: number | undefined, kind: 'violations' | 'achievements' | 'health' | 'attendances' | 'permissions' | 'homecomings' | 'visitors') => {
  const role = useRole();
  return useQuery({ queryKey: ['boarding', id, kind], queryFn: () => list(`/boarding/portal/children/${id}/${kind}`, { per_page: 30 }), enabled: enabledFor(role, id) });
};

export function useBoardingRequest(kind: 'permissions' | 'homecomings') {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { studentId: number; body: Record<string, unknown> }) => post(`/boarding/portal/children/${v.studentId}/${kind}`, v.body),
    onSuccess: (_d, v) => { qc.invalidateQueries({ queryKey: ['boarding', v.studentId] }); },
  });
}
export function useBoardingCancel(kind: 'permissions' | 'homecomings') {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { studentId: number; id: number }) => post(`/boarding/portal/children/${v.studentId}/${kind}/${v.id}/cancel`, { note: 'Dibatalkan oleh orang tua' }),
    onSuccess: (_d, v) => { qc.invalidateQueries({ queryKey: ['boarding', v.studentId] }); },
  });
}

// =============================================================== TAHFIZ
export const useTahfizParent = (participantId?: number | null) => {
  const role = useRole();
  return useQuery({
    queryKey: ['tahfiz', participantId ?? 'self'],
    queryFn: () => get<Row>('/tahfiz/dashboards/wali', participantId ? { view: 'child', participant_id: participantId } : { view: 'self' }),
    enabled: role === 'guardian',
  });
};

// ============================================================ AKTIVITAS
export const useActivityParent = () => {
  const role = useRole();
  return useQuery({ queryKey: ['activity', 'parent'], queryFn: () => get<{ children: Row[] }>('/activities/portal/parent'), enabled: role === 'guardian' });
};
export const useActivityConsents = () => {
  const role = useRole();
  return useQuery({ queryKey: ['activity', 'consents'], queryFn: () => get<Row[]>('/activities/portal/consents'), enabled: role === 'guardian' });
};
export function useDecideActivityConsent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; decision: 'APPROVED' | 'DECLINED' | 'REVOKED'; notes?: string }) => post(`/activities/portal/consents/${v.id}/decide`, { decision: v.decision, notes: v.notes }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['activity'] }),
  });
}

// ============================================================= KESEHATAN
export const useClinicChildren = () => {
  const role = useRole();
  return useQuery({ queryKey: ['clinic', 'children'], queryFn: () => get<{ registered: Row[]; unregistered: Row[]; privacy?: Row }>('/clinic/portal/children'), enabled: role === 'guardian' || role === 'student' });
};
export const useClinicChild = (patientId?: number) =>
  useQuery({ queryKey: ['clinic', patientId, 'summary'], queryFn: () => get<Row>(`/clinic/portal/patients/${patientId}`), enabled: !!patientId });
export const useClinicVisits = (patientId?: number) =>
  useQuery({ queryKey: ['clinic', patientId, 'visits'], queryFn: () => get<Row[]>(`/clinic/portal/patients/${patientId}/visits`), enabled: !!patientId });
export const useClinicCertificates = (patientId?: number) =>
  useQuery({ queryKey: ['clinic', patientId, 'certs'], queryFn: () => get<Row[]>(`/clinic/portal/patients/${patientId}/certificates`), enabled: !!patientId });

// =============================================================== KONSELING
export const useBkSummary = () => {
  const role = useRole();
  return useQuery({ queryKey: ['bk', 'summary'], queryFn: () => get<Row>('/bk/portal/summary'), enabled: role === 'guardian' || role === 'student' });
};
export const useBkRequests = () => {
  const role = useRole();
  return useQuery({ queryKey: ['bk', 'requests'], queryFn: () => list('/bk/portal/requests', { per_page: 30 }), enabled: role === 'guardian' || role === 'student' });
};
export const useBkAppointments = () => {
  const role = useRole();
  return useQuery({ queryKey: ['bk', 'appointments'], queryFn: () => list('/bk/portal/appointments', { per_page: 30, upcoming: 1 }), enabled: role === 'guardian' || role === 'student' });
};
export const useBkConsents = () => {
  const role = useRole();
  return useQuery({ queryKey: ['bk', 'consents'], queryFn: () => list('/bk/portal/consents', { per_page: 30 }), enabled: role === 'guardian' || role === 'student' });
};
export function useBkRequest() {
  const qc = useQueryClient();
  const role = useAuth((s) => s.active);
  return useMutation({
    mutationFn: (v: { studentId?: number; topic: string; message?: string; urgent?: boolean; slot?: string }) =>
      post(role === 'guardian' ? '/bk/portal/requests/guardian' : '/bk/portal/requests/student', { student_id: v.studentId, topic: v.topic, message: v.message, urgent: v.urgent, preferred_time_slot: v.slot }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['bk'] }),
  });
}
export function useBkCancelRequest() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: number) => post(`/bk/portal/requests/${id}/cancel`), onSuccess: () => qc.invalidateQueries({ queryKey: ['bk'] }) });
}
export function useBkDecide() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; decision: 'APPROVED' | 'DECLINED'; note?: string }) => post(`/bk/portal/consents/${v.id}/decide`, { decision: v.decision, note: v.note }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['bk'] }),
  });
}

// ================================================================= DOMPET
export const useWallet = (id?: number) => {
  const role = useRole();
  return useQuery({ queryKey: ['wallet', id], queryFn: () => get<Row>(`/mobile/children/${id}/wallet`), enabled: enabledFor(role, id) });
};
export function useTopup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { studentId: number; amount: number }) => post<{ number: string; amount: number; redirect_url: string | null; expires_at: string | null }>(`/mobile/children/${v.studentId}/wallet/topups`, { amount: v.amount }),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['wallet', v.studentId] }),
  });
}

export { newIdempotencyKey };
