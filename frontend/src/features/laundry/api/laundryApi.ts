import { api } from '@/lib/axios';
import type {
  ApiEnvelope, AuditLogRow, DailyOperations, EntitlementRow, EntitlementState, EntitlementTransaction,
  FreeVsPaid, IdentifyResult, KanbanBoard, LaundryBag, LaundryBatch, LaundryDashboard, LaundryIncident,
  LaundryMachine, LaundryPolicy, LaundryPolicyVersion, LaundryPricingRule, LaundryService,
  OperationalReport, OrderDetail, OrderSummary, OrderTimeline, PagedResponse, PaymentPreview,
  PortalContext, QuotePreview, QuotaReportRow, StudentStatement, WalletReportRow,
} from '../types';

const unwrap = <T>(promise: Promise<{ data: ApiEnvelope<T> }>) => promise.then((response) => response.data.data);

const paged = <T>(promise: Promise<{ data: PagedResponse<T> }>) =>
  promise.then((response) => ({ rows: response.data.data, meta: response.data.meta }));

export const laundryApi = {
  /* ── Dashboard & laporan ─────────────────────────────────────────── */
  dashboard: (params: { date?: string } = {}) =>
    unwrap<LaundryDashboard>(api.get('/laundry/dashboard', { params })),

  dailyOperations: (params: { date?: string } = {}) =>
    unwrap<DailyOperations>(api.get('/laundry/reports/daily', { params })),

  freeVsPaid: (params: { from?: string; to?: string } = {}) =>
    unwrap<FreeVsPaid>(api.get('/laundry/reports/free-vs-paid', { params })),

  quotaReport: (params: { period?: string; dormitory_id?: number } = {}) =>
    unwrap<QuotaReportRow[]>(api.get('/laundry/reports/quota', { params })),

  walletReport: (params: { from?: string; to?: string } = {}) =>
    unwrap<WalletReportRow[]>(api.get('/laundry/reports/wallet', { params })),

  operationalReport: (params: { from?: string; to?: string } = {}) =>
    unwrap<OperationalReport>(api.get('/laundry/reports/operational', { params })),

  auditLogs: (params: { subject_type?: string; subject_id?: number; action?: string; per_page?: number } = {}) =>
    paged<AuditLogRow>(api.get('/laundry/audit-logs', { params })),

  /* ── Identifikasi RFID/QR ────────────────────────────────────────── */
  identify: (uid: string) => unwrap<IdentifyResult>(api.post('/laundry/identify', { uid })),

  /* ── Katalog ─────────────────────────────────────────────────────── */
  services: (params: { active_only?: boolean } = {}) =>
    unwrap<LaundryService[]>(api.get('/laundry/services', { params })),

  createService: (payload: Record<string, unknown>) =>
    unwrap<LaundryService>(api.post('/laundry/services', payload)),

  updateService: (id: number, payload: Record<string, unknown>) =>
    unwrap<LaundryService>(api.put(`/laundry/services/${id}`, payload)),

  pricingRules: (params: { laundry_service_id?: number; active_only?: boolean } = {}) =>
    unwrap<LaundryPricingRule[]>(api.get('/laundry/pricing-rules', { params })),

  createPricingRule: (payload: Record<string, unknown>) =>
    unwrap<LaundryPricingRule>(api.post('/laundry/pricing-rules', payload)),

  deactivatePricingRule: (id: number, payload: { reason?: string } = {}) =>
    unwrap<LaundryPricingRule>(api.post(`/laundry/pricing-rules/${id}/deactivate`, payload)),

  machines: () => unwrap<LaundryMachine[]>(api.get('/laundry/machines')),

  createMachine: (payload: Record<string, unknown>) =>
    unwrap<LaundryMachine>(api.post('/laundry/machines', payload)),

  updateMachine: (id: number, payload: Record<string, unknown>) =>
    unwrap<LaundryMachine>(api.put(`/laundry/machines/${id}`, payload)),

  /* ── Policy jatah ────────────────────────────────────────────────── */
  policies: (params: { active_only?: boolean } = {}) =>
    unwrap<LaundryPolicy[]>(api.get('/laundry/policies', { params })),

  createPolicy: (payload: { code: string; name: string; description?: string }) =>
    unwrap<LaundryPolicy>(api.post('/laundry/policies', payload)),

  resolvePolicy: (studentId: number, params: { date?: string } = {}) =>
    unwrap<{ student_id: number; context: Record<string, unknown>; policy_version: LaundryPolicyVersion | null }>(
      api.get(`/laundry/policies/resolve/${studentId}`, { params }),
    ),

  createPolicyVersion: (policyId: number, payload: Record<string, unknown>) =>
    unwrap<LaundryPolicyVersion>(api.post(`/laundry/policies/${policyId}/versions`, payload)),

  publishPolicyVersion: (versionId: number) =>
    unwrap<LaundryPolicyVersion>(api.post(`/laundry/policy-versions/${versionId}/publish`)),

  archivePolicyVersion: (versionId: number, payload: { reason?: string } = {}) =>
    unwrap<LaundryPolicyVersion>(api.post(`/laundry/policy-versions/${versionId}/archive`, payload)),

  /* ── Entitlement & ledger ────────────────────────────────────────── */
  entitlements: (params: { period_key?: string; status?: string; student_id?: number; per_page?: number } = {}) =>
    paged<EntitlementRow>(api.get('/laundry/entitlements', { params })),

  entitlement: (studentId: number, params: { date?: string } = {}) =>
    unwrap<{ current: EntitlementState; history: EntitlementState[] }>(
      api.get(`/laundry/entitlements/${studentId}`, { params }),
    ),

  entitlementTransactions: (studentId: number, params: { period_key?: string } = {}) =>
    unwrap<EntitlementTransaction[]>(api.get(`/laundry/entitlements/${studentId}/transactions`, { params })),

  adjustEntitlement: (studentId: number, payload: { amount: number; reason: string; date?: string }) =>
    unwrap<EntitlementState>(api.post(`/laundry/entitlements/${studentId}/adjust`, payload)),

  studentStatement: (studentId: number, params: { period?: string } = {}) =>
    unwrap<StudentStatement>(api.get(`/laundry/entitlements/${studentId}/statement`, { params })),

  /* ── Orders ──────────────────────────────────────────────────────── */
  orders: (params: { status?: string; payment_status?: string; student_id?: number; laundry_service_id?: number; from?: string; to?: string; search?: string; per_page?: number } = {}) =>
    paged<OrderSummary>(api.get('/laundry/orders', { params })),

  createOrder: (payload: Record<string, unknown>) =>
    unwrap<OrderDetail>(api.post('/laundry/orders', payload)),

  order: (id: number) => unwrap<OrderDetail>(api.get(`/laundry/orders/${id}`)),

  orderTimeline: (id: number) => unwrap<OrderTimeline>(api.get(`/laundry/orders/${id}/timeline`)),

  orderPreview: (payload: { student_id: number; laundry_service_id: number; net_weight?: number; items?: Array<{ item_type: string; quantity?: number }>; extras?: string[]; date?: string }) =>
    unwrap<QuotePreview>(api.post('/laundry/orders/preview', payload)),

  receiveOrder: (id: number, payload: Record<string, unknown>) =>
    unwrap<OrderDetail>(api.post(`/laundry/orders/${id}/receive`, payload)),

  weighOrder: (id: number, payload: { gross_weight?: number; bag_weight?: number; item_count?: number }) =>
    unwrap<OrderDetail>(api.post(`/laundry/orders/${id}/weigh`, payload)),

  calculateOrder: (id: number, payload: { extras?: string[] } = {}) =>
    unwrap<OrderDetail>(api.post(`/laundry/orders/${id}/calculate`, payload)),

  paymentPreview: (id: number) => unwrap<PaymentPreview>(api.get(`/laundry/orders/${id}/payment-preview`)),

  payOrder: (id: number, payload: { idempotency_key: string; method?: string; rfid_uid?: string }) =>
    unwrap<{ payment: Record<string, unknown>; order: OrderDetail; replayed: boolean }>(
      api.post(`/laundry/orders/${id}/pay`, payload),
    ),

  refundOrder: (id: number, payload: { reason: string; amount?: number }) =>
    unwrap<Record<string, unknown>>(api.post(`/laundry/orders/${id}/refund`, payload)),

  queueOrder: (id: number) => unwrap<OrderSummary>(api.post(`/laundry/orders/${id}/queue`)),

  transitionOrder: (id: number, payload: { status: string; reason?: string }) =>
    unwrap<OrderSummary>(api.post(`/laundry/orders/${id}/transition`, payload)),

  completeOrder: (id: number) => unwrap<OrderSummary>(api.post(`/laundry/orders/${id}/complete`)),

  cancelOrder: (id: number, payload: { reason: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/laundry/orders/${id}/cancel`, payload)),

  overrideOrder: (id: number, payload: { type: 'WAIVE_FEE' | 'FORCE_PROCESS'; reason: string }) =>
    unwrap<OrderDetail>(api.post(`/laundry/orders/${id}/override`, payload)),

  orderLabel: (id: number) =>
    unwrap<{ label_number: string; qr_payload: string; order_number: string }>(api.get(`/laundry/orders/${id}/label`)),

  /* ── Pemrosesan ──────────────────────────────────────────────────── */
  kanban: (params: { date?: string } = {}) =>
    unwrap<KanbanBoard>(api.get('/laundry/queue', { params })),

  batches: (params: { status?: string; stage?: string; per_page?: number } = {}) =>
    paged<LaundryBatch>(api.get('/laundry/batches', { params })),

  createBatch: (payload: Record<string, unknown>) =>
    unwrap<LaundryBatch>(api.post('/laundry/batches', payload)),

  batch: (id: number) => unwrap<LaundryBatch>(api.get(`/laundry/batches/${id}`)),

  addBatchOrders: (id: number, orderIds: number[]) =>
    unwrap<LaundryBatch>(api.post(`/laundry/batches/${id}/orders`, { order_ids: orderIds })),

  startBatch: (id: number) => unwrap<LaundryBatch>(api.post(`/laundry/batches/${id}/start`)),

  completeBatch: (id: number) => unwrap<LaundryBatch>(api.post(`/laundry/batches/${id}/complete`)),

  recordStep: (orderId: number, payload: Record<string, unknown>) =>
    unwrap<Record<string, unknown>>(api.post(`/laundry/orders/${orderId}/steps`, payload)),

  qualityCheck: (orderId: number, payload: { result: string; checklist?: Record<string, boolean>; notes?: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/laundry/orders/${orderId}/qc`, payload)),

  packageOrder: (orderId: number, payload: { laundry_bag_id?: number; notes?: string } = {}) =>
    unwrap<OrderDetail>(api.post(`/laundry/orders/${orderId}/package`, payload)),

  /* ── Insiden ─────────────────────────────────────────────────────── */
  incidents: (params: { status?: string; incident_type?: string; from?: string; to?: string; per_page?: number } = {}) =>
    paged<LaundryIncident>(api.get('/laundry/incidents', { params })),

  reportIncident: (orderId: number, payload: Record<string, unknown>) =>
    unwrap<LaundryIncident>(api.post(`/laundry/orders/${orderId}/incidents`, payload)),

  updateIncidentStatus: (id: number, payload: { status: string; resolution?: string }) =>
    unwrap<LaundryIncident>(api.post(`/laundry/incidents/${id}/status`, payload)),

  proposeCompensation: (id: number, payload: { compensation_type: string; compensation_amount?: number }) =>
    unwrap<LaundryIncident>(api.post(`/laundry/incidents/${id}/compensation`, payload)),

  approveCompensation: (id: number, payload: { note?: string } = {}) =>
    unwrap<LaundryIncident>(api.post(`/laundry/incidents/${id}/compensation/approve`, payload)),

  /* ── Tas ─────────────────────────────────────────────────────────── */
  bags: (params: { status?: string; student_id?: number; per_page?: number } = {}) =>
    paged<LaundryBag>(api.get('/laundry/bags', { params })),

  createBag: (payload: Record<string, unknown>) =>
    unwrap<LaundryBag>(api.post('/laundry/bags', payload)),

  updateBag: (id: number, payload: Record<string, unknown>) =>
    unwrap<LaundryBag>(api.put(`/laundry/bags/${id}`, payload)),

  assignBag: (id: number, payload: { student_id: number; notes?: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/laundry/bags/${id}/assign`, payload)),

  releaseBag: (id: number, payload: { status?: string } = {}) =>
    unwrap<LaundryBag>(api.post(`/laundry/bags/${id}/release`, payload)),

  /* ── Serah terima ────────────────────────────────────────────────── */
  markReady: (id: number) => unwrap<OrderDetail>(api.post(`/laundry/orders/${id}/ready`)),

  pickupOrder: (id: number, payload: { verification_method: string; verification_value?: string; receiver_name: string; notes?: string }) =>
    unwrap<{ order: OrderDetail; pickup: Record<string, unknown> }>(api.post(`/laundry/orders/${id}/pickup`, payload)),

  deliverOrder: (id: number, payload: { verification_method: string; verification_value?: string; receiver_name: string; dormitory_id?: number; courier_id?: number; notes?: string }) =>
    unwrap<{ order: OrderDetail; delivery: Record<string, unknown> }>(api.post(`/laundry/orders/${id}/deliver`, payload)),

  /* ── Portal santri (/me/laundry) ─────────────────────────────────── */
  meQuota: () => unwrap<EntitlementState>(api.get('/me/laundry/quota')),

  meOrders: (params: { status?: string } = {}) =>
    unwrap<OrderSummary[]>(api.get('/me/laundry/orders', { params })),

  meOrder: (id: number) => unwrap<OrderDetail>(api.get(`/me/laundry/orders/${id}`)),

  meStatement: (params: { period?: string } = {}) =>
    unwrap<StudentStatement>(api.get('/me/laundry/statement', { params })),

  /* ── Portal wali (/parent/children) ──────────────────────────────── */
  parentChildren: () => unwrap<PortalContext[]>(api.get('/parent/laundry/children')),

  childOverview: (studentId: number) =>
    unwrap<PortalContext>(api.get(`/parent/children/${studentId}/laundry`)),

  childQuota: (studentId: number) =>
    unwrap<{ current: EntitlementState; history: EntitlementState[] }>(
      api.get(`/parent/children/${studentId}/laundry/quota`),
    ),

  childOrders: (studentId: number, params: { status?: string } = {}) =>
    unwrap<OrderSummary[]>(api.get(`/parent/children/${studentId}/laundry/orders`, { params })),

  childOrder: (studentId: number, orderId: number) =>
    unwrap<OrderDetail & { wallet_payment?: Record<string, unknown> | null }>(
      api.get(`/parent/children/${studentId}/laundry/orders/${orderId}`),
    ),

  childStatement: (studentId: number, params: { period?: string } = {}) =>
    unwrap<StudentStatement>(api.get(`/parent/children/${studentId}/laundry/statement`, { params })),
};
