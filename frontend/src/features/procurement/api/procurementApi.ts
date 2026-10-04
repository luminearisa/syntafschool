import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  BudgetAvailability,
  ComparisonMatrix,
  EnumOptions,
  EvaluationCriteria,
  GoodsReceipt,
  IntegrationHealth,
  InvoiceException,
  ListParams,
  MetaRecord,
  PaymentStatus,
  ProcurementCategory,
  ProcurementDashboard,
  ProcurementEvent,
  ProcurementItem,
  ProcurementSetting,
  PurchaseOrder,
  PurchaseRequest,
  Quotation,
  ReportResult,
  Rfq,
  ServiceReceipt,
  TimelineEntry,
  Vendor,
  VendorInvoice,
  VendorPerformanceScorecard,
  VendorSelection,
} from '../types';

const BASE = '/procurement';

/* ── Konteks & metadata ──────────────────────────────────────────────────── */

export const metaApi = {
  metadata: () =>
    api.get<ApiResponse<EnumOptions>>(`${BASE}/metadata`).then((r) => r.data.data),
  dashboard: () =>
    api.get<ApiResponse<ProcurementDashboard>>(`${BASE}/dashboard`).then((r) => r.data.data),
  health: () =>
    api.get<ApiResponse<IntegrationHealth>>(`${BASE}/health`).then((r) => r.data.data),
  /** Opsi SearchSelect: vendor | item | category. */
  options: (type: 'vendor' | 'item' | 'category', search?: string) =>
    api
      .get<ApiResponse<MetaRecord[]>>(`${BASE}/options`, { params: { type, search } })
      .then((r) => r.data.data),
};

/* ── Vendor ──────────────────────────────────────────────────────────────── */

export const vendorsApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<Vendor>>(`${BASE}/vendors`, { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<Vendor>>(`${BASE}/vendors/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<Vendor>>(`${BASE}/vendors`, payload).then((r) => r.data.data),
  update: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<Vendor>>(`${BASE}/vendors/${id}`, payload).then((r) => r.data.data),
  submit: (id: number) => api.post<ApiResponse<Vendor>>(`${BASE}/vendors/${id}/submit`).then((r) => r.data.data),
  verify: (id: number) => api.post<ApiResponse<Vendor>>(`${BASE}/vendors/${id}/verify`).then((r) => r.data.data),
  approve: (id: number) => api.post<ApiResponse<Vendor>>(`${BASE}/vendors/${id}/approve`).then((r) => r.data.data),
  reject: (id: number, reason: string) =>
    api.post<ApiResponse<Vendor>>(`${BASE}/vendors/${id}/reject`, { reason }).then((r) => r.data.data),
  suspend: (id: number, reason: string) =>
    api.post<ApiResponse<Vendor>>(`${BASE}/vendors/${id}/suspend`, { reason }).then((r) => r.data.data),
  deactivate: (id: number) => api.post<ApiResponse<Vendor>>(`${BASE}/vendors/${id}/deactivate`).then((r) => r.data.data),
  classify: (id: number, payload: { classification: string; tier?: string; risk_level?: string }) =>
    api.post<ApiResponse<Vendor>>(`${BASE}/vendors/${id}/classify`, payload).then((r) => r.data.data),
  addDocument: (id: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<Vendor>>(`${BASE}/vendors/${id}/documents`, payload).then((r) => r.data.data),
  verifyDocument: (documentId: number) =>
    api.post<ApiResponse<unknown>>(`${BASE}/vendor-documents/${documentId}/verify`).then((r) => r.data.data),
  rejectDocument: (documentId: number, reason: string) =>
    api.post<ApiResponse<unknown>>(`${BASE}/vendor-documents/${documentId}/reject`, { reason }).then((r) => r.data.data),
  performance: (id: number, period?: string) =>
    api
      .get<ApiResponse<VendorPerformanceScorecard>>(`${BASE}/vendors/${id}/performance`, { params: { period } })
      .then((r) => r.data.data),
  expiringDocuments: (days = 30) =>
    api.get<ApiResponse<unknown[]>>(`${BASE}/vendors/expiring-documents`, { params: { days } }).then((r) => r.data.data),
};

/* ── Katalog ─────────────────────────────────────────────────────────────── */

export const catalogApi = {
  categories: (params: ListParams = {}) =>
    api.get<ApiResponse<ProcurementCategory[]>>(`${BASE}/categories`, { params }).then((r) => r.data.data),
  createCategory: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<ProcurementCategory>>(`${BASE}/categories`, payload).then((r) => r.data.data),
  updateCategory: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<ProcurementCategory>>(`${BASE}/categories/${id}`, payload).then((r) => r.data.data),
  items: (params: ListParams = {}) =>
    api.get<PaginatedResponse<ProcurementItem>>(`${BASE}/items`, { params }).then((r) => r.data),
  createItem: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<ProcurementItem>>(`${BASE}/items`, payload).then((r) => r.data.data),
  updateItem: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<ProcurementItem>>(`${BASE}/items/${id}`, payload).then((r) => r.data.data),
};

/* ── Purchase Request ────────────────────────────────────────────────────── */

export const purchaseRequestsApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<PurchaseRequest>>(`${BASE}/purchase-requests`, { params }).then((r) => r.data),
  show: (id: number) =>
    api.get<ApiResponse<PurchaseRequest>>(`${BASE}/purchase-requests/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<PurchaseRequest>>(`${BASE}/purchase-requests`, payload).then((r) => r.data.data),
  update: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<PurchaseRequest>>(`${BASE}/purchase-requests/${id}`, payload).then((r) => r.data.data),
  validate: (id: number) =>
    api
      .post<ApiResponse<{ issues: { code: string; severity: string; message: string }[]; suggestions: unknown[] }>>(
        `${BASE}/purchase-requests/${id}/validate`,
      )
      .then((r) => r.data.data),
  submit: (id: number) =>
    api.post<ApiResponse<PurchaseRequest>>(`${BASE}/purchase-requests/${id}/submit`).then((r) => r.data.data),
  approve: (id: number, notes?: string) =>
    api.post<ApiResponse<PurchaseRequest>>(`${BASE}/purchase-requests/${id}/approve`, { notes }).then((r) => r.data.data),
  reject: (id: number, reason: string) =>
    api.post<ApiResponse<PurchaseRequest>>(`${BASE}/purchase-requests/${id}/reject`, { reason }).then((r) => r.data.data),
  returnRequest: (id: number, reason: string) =>
    api.post<ApiResponse<PurchaseRequest>>(`${BASE}/purchase-requests/${id}/return`, { reason }).then((r) => r.data.data),
  timeline: (id: number) =>
    api.get<ApiResponse<TimelineEntry[]>>(`${BASE}/purchase-requests/${id}/timeline`).then((r) => r.data.data),
  consolidate: (payload: { purchase_request_ids: number[]; title: string; method?: string }) =>
    api.post<ApiResponse<ProcurementEvent>>(`${BASE}/purchase-requests/consolidate`, payload).then((r) => r.data.data),
};

/* ── RFQ ─────────────────────────────────────────────────────────────────── */

export const rfqsApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<Rfq>>(`${BASE}/rfqs`, { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<Rfq>>(`${BASE}/rfqs/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<Rfq>>(`${BASE}/rfqs`, payload).then((r) => r.data.data),
  createFromEvent: (eventId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<Rfq>>(`${BASE}/events/${eventId}/rfq`, payload).then((r) => r.data.data),
  invite: (id: number, vendorIds: number[]) =>
    api.post<ApiResponse<Rfq>>(`${BASE}/rfqs/${id}/invite`, { vendor_ids: vendorIds }).then((r) => r.data.data),
  issue: (id: number) => api.post<ApiResponse<Rfq>>(`${BASE}/rfqs/${id}/issue`).then((r) => r.data.data),
  close: (id: number) => api.post<ApiResponse<Rfq>>(`${BASE}/rfqs/${id}/close`).then((r) => r.data.data),
};

/* ── Penawaran & evaluasi ────────────────────────────────────────────────── */

export const quotationsApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<Quotation>>(`${BASE}/quotations`, { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<Quotation>>(`${BASE}/quotations/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<Quotation>>(`${BASE}/quotations`, payload).then((r) => r.data.data),
  update: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<Quotation>>(`${BASE}/quotations/${id}`, payload).then((r) => r.data.data),
  comparison: (rfqId: number) =>
    api.get<ApiResponse<ComparisonMatrix>>(`${BASE}/rfqs/${rfqId}/comparison`).then((r) => r.data.data),
  criteria: (rfqId: number) =>
    api.get<ApiResponse<EvaluationCriteria[]>>(`${BASE}/rfqs/${rfqId}/criteria`).then((r) => r.data.data),
  defineCriteria: (rfqId: number, payload: { criteria: { code: string; name: string; weight: number }[] }) =>
    api.post<ApiResponse<EvaluationCriteria[]>>(`${BASE}/rfqs/${rfqId}/criteria`, payload).then((r) => r.data.data),
  evaluate: (quotationId: number, payload: { recommendation?: string; scores: { code: string; score: number }[] }) =>
    api.post<ApiResponse<unknown>>(`${BASE}/quotations/${quotationId}/evaluate`, payload).then((r) => r.data.data),
  ranking: (rfqId: number) =>
    api.get<ApiResponse<unknown[]>>(`${BASE}/rfqs/${rfqId}/ranking`).then((r) => r.data.data),
};

/* ── Pemilihan vendor ────────────────────────────────────────────────────── */

export const selectionsApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<VendorSelection>>(`${BASE}/vendor-selections`, { params }).then((r) => r.data),
  show: (id: number) =>
    api.get<ApiResponse<VendorSelection>>(`${BASE}/vendor-selections/${id}`).then((r) => r.data.data),
  select: (rfqId: number, payload: { quotation_id: number; rationale: string; recommendation?: string }) =>
    api.post<ApiResponse<VendorSelection>>(`${BASE}/rfqs/${rfqId}/select`, payload).then((r) => r.data.data),
  approve: (id: number, notes?: string) =>
    api.post<ApiResponse<VendorSelection>>(`${BASE}/vendor-selections/${id}/approve`, { notes }).then((r) => r.data.data),
};

/* ── Purchase Order ──────────────────────────────────────────────────────── */

export const purchaseOrdersApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<PurchaseOrder>>(`${BASE}/purchase-orders`, { params }).then((r) => r.data),
  show: (id: number) =>
    api.get<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders`, payload).then((r) => r.data.data),
  createFromSelection: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/from-selection`, payload).then((r) => r.data.data),
  submit: (id: number) =>
    api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}/submit`).then((r) => r.data.data),
  approve: (id: number, notes?: string) =>
    api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}/approve`, { notes }).then((r) => r.data.data),
  reject: (id: number, reason: string) =>
    api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}/reject`, { reason }).then((r) => r.data.data),
  issue: (id: number) => api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}/issue`).then((r) => r.data.data),
  amend: (id: number, payload: { reason: string; is_material?: boolean; changes: Record<string, unknown> }) =>
    api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}/amend`, payload).then((r) => r.data.data),
  versions: (id: number) =>
    api.get<ApiResponse<PurchaseOrder['versions']>>(`${BASE}/purchase-orders/${id}/versions`).then((r) => r.data.data),
  confirm: (id: number, payload: { status: string; notes?: string }) =>
    api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}/confirm`, payload).then((r) => r.data.data),
  close: (id: number) => api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}/close`).then((r) => r.data.data),
  cancel: (id: number, reason: string) =>
    api.post<ApiResponse<PurchaseOrder>>(`${BASE}/purchase-orders/${id}/cancel`, { reason }).then((r) => r.data.data),
};

/* ── Penerimaan ──────────────────────────────────────────────────────────── */

export const receivingApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<GoodsReceipt>>(`${BASE}/goods-receipts`, { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<GoodsReceipt>>(`${BASE}/goods-receipts/${id}`).then((r) => r.data.data),
  store: (poId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<GoodsReceipt>>(`${BASE}/purchase-orders/${poId}/receipts`, payload).then((r) => r.data.data),
  inspect: (receiptId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<GoodsReceipt>>(`${BASE}/goods-receipts/${receiptId}/inspect`, payload).then((r) => r.data.data),
  serviceReceipts: (params: ListParams = {}) =>
    api.get<PaginatedResponse<ServiceReceipt>>(`${BASE}/service-receipts`, { params }).then((r) => r.data),
  storeServiceReceipt: (poId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<ServiceReceipt>>(`${BASE}/purchase-orders/${poId}/service-receipts`, payload).then((r) => r.data.data),
  acceptServiceReceipt: (id: number, payload: Record<string, unknown> = {}) =>
    api.post<ApiResponse<ServiceReceipt>>(`${BASE}/service-receipts/${id}/accept`, payload).then((r) => r.data.data),
  rejectServiceReceipt: (id: number, reason: string) =>
    api.post<ApiResponse<ServiceReceipt>>(`${BASE}/service-receipts/${id}/reject`, { reason }).then((r) => r.data.data),
};

/* ── Invoice, matching, handoff ──────────────────────────────────────────── */

export const invoicesApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<VendorInvoice>>(`${BASE}/invoices`, { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<VendorInvoice>>(`${BASE}/invoices/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<VendorInvoice>>(`${BASE}/invoices`, payload).then((r) => r.data.data),
  update: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<VendorInvoice>>(`${BASE}/invoices/${id}`, payload).then((r) => r.data.data),
  match: (id: number) =>
    api.post<ApiResponse<VendorInvoice>>(`${BASE}/invoices/${id}/match`).then((r) => r.data.data),
  override: (id: number, payload: { reason: string }) =>
    api.post<ApiResponse<VendorInvoice>>(`${BASE}/invoices/${id}/override`, payload).then((r) => r.data.data),
  approve: (id: number) => api.post<ApiResponse<VendorInvoice>>(`${BASE}/invoices/${id}/approve`).then((r) => r.data.data),
  handoff: (id: number) =>
    api.post<ApiResponse<unknown>>(`${BASE}/invoices/${id}/handoff`).then((r) => r.data.data),
  retryHandoff: (id: number) =>
    api.post<ApiResponse<unknown>>(`${BASE}/invoices/${id}/retry-handoff`).then((r) => r.data.data),
  syncStatus: (id: number) =>
    api.post<ApiResponse<unknown>>(`${BASE}/invoices/${id}/sync-status`).then((r) => r.data.data),
  paymentStatus: (id: number) =>
    api.get<ApiResponse<PaymentStatus>>(`${BASE}/invoices/${id}/payment-status`).then((r) => r.data.data),
  exceptions: (params: ListParams = {}) =>
    api.get<PaginatedResponse<InvoiceException>>(`${BASE}/invoice-exceptions`, { params }).then((r) => r.data),
  investigateException: (id: number, payload: { notes: string }) =>
    api.post<ApiResponse<InvoiceException>>(`${BASE}/invoice-exceptions/${id}/investigate`, payload).then((r) => r.data.data),
  resolveException: (id: number, payload: { resolution: string; status?: string }) =>
    api.post<ApiResponse<InvoiceException>>(`${BASE}/invoice-exceptions/${id}/resolve`, payload).then((r) => r.data.data),
};

/* ── Laporan, anggaran, pengaturan ───────────────────────────────────────── */

export const reportsApi = {
  get: (type: string, params: ListParams = {}) =>
    api.get<ApiResponse<ReportResult>>(`${BASE}/reports/${type}`, { params }).then((r) => r.data.data),
  spend: (params: ListParams = {}) =>
    api.get<ApiResponse<ReportResult>>(`${BASE}/reports/spend/analytics`, { params }).then((r) => r.data.data),
  cycleTimes: (params: ListParams = {}) =>
    api.get<ApiResponse<ReportResult>>(`${BASE}/reports/cycle-times`, { params }).then((r) => r.data.data),
  budgetAvailability: (params: ListParams = {}) =>
    api.get<ApiResponse<BudgetAvailability>>(`${BASE}/budget/availability`, { params }).then((r) => r.data.data),
  settings: () =>
    api.get<ApiResponse<ProcurementSetting[]>>(`${BASE}/settings`).then((r) => r.data.data),
  updateSetting: (payload: { key: string; value: unknown; group?: string; description?: string }) =>
    api.post<ApiResponse<ProcurementSetting>>(`${BASE}/settings`, payload).then((r) => r.data.data),
};
