import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  AssetInspection,
  AssetLoan,
  AssetTransfer,
  DamageReport,
  ListParams,
  MaintenanceRequest,
  MaintenanceSchedule,
  MaintenanceWorkOrder,
} from '../types';

const toForm = <T extends object>(payload: T) => payload;

/* ── Peminjaman ──────────────────────────────────────────────────────────── */

export const loansApi = {
  list: (params: ListParams = {}) => api.get<PaginatedResponse<AssetLoan>>('/loans', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<AssetLoan>>(`/loans/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) => api.post<ApiResponse<AssetLoan>>('/loans', toForm(payload)).then((r) => r.data.data),
  approve: (id: number, payload: { notes?: string } = {}) =>
    api.post<ApiResponse<AssetLoan>>(`/loans/${id}/approve`, toForm(payload)).then((r) => r.data.data),
  reserve: (id: number) => api.post<ApiResponse<AssetLoan>>(`/loans/${id}/reserve`, {}).then((r) => r.data.data),
  handover: (id: number, payload: { condition_out?: string; notes?: string } = {}) =>
    api.post<ApiResponse<AssetLoan>>(`/loans/${id}/handover`, toForm(payload)).then((r) => r.data.data),
  activate: (id: number) => api.post<ApiResponse<AssetLoan>>(`/loans/${id}/activate`, {}).then((r) => r.data.data),
  returnLoan: (id: number, payload: { condition: string; damage_found?: boolean; notes?: string; accessories?: string }) =>
    api.post<ApiResponse<AssetLoan>>(`/loans/${id}/return`, toForm(payload)).then((r) => r.data.data),
  cancel: (id: number, payload: { reason?: string } = {}) =>
    api.post<ApiResponse<AssetLoan>>(`/loans/${id}/cancel`, toForm(payload)).then((r) => r.data.data),
  /** Pemakaian cepat dari halaman aset: pinjam satu barang tanpa pindah layar. */
  quickLoan: (assetId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<AssetLoan>>(`/assets/${assetId}/loan`, toForm(payload)).then((r) => r.data.data),
  quickReturn: (assetId: number, payload: { condition: string; damage_found?: boolean; notes?: string }) =>
    api.post<ApiResponse<AssetLoan>>(`/assets/${assetId}/return`, toForm(payload)).then((r) => r.data.data),
};

/* ── Pemindahan ──────────────────────────────────────────────────────────── */

export const transfersApi = {
  list: (params: ListParams = {}) => api.get<PaginatedResponse<AssetTransfer>>('/transfers', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<AssetTransfer>>(`/transfers/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) => api.post<ApiResponse<AssetTransfer>>('/transfers', toForm(payload)).then((r) => r.data.data),
  approve: (id: number) => api.post<ApiResponse<AssetTransfer>>(`/transfers/${id}/approve`, {}).then((r) => r.data.data),
  handover: (id: number, payload: { notes?: string; document_reference?: string } = {}) =>
    api.post<ApiResponse<AssetTransfer>>(`/transfers/${id}/handover`, toForm(payload)).then((r) => r.data.data),
  receive: (id: number, payload: { recipient_condition?: string; recipient_notes?: string } = {}) =>
    api.post<ApiResponse<AssetTransfer>>(`/transfers/${id}/receive`, toForm(payload)).then((r) => r.data.data),
  cancel: (id: number, payload: { reason?: string } = {}) =>
    api.post<ApiResponse<AssetTransfer>>(`/transfers/${id}/cancel`, toForm(payload)).then((r) => r.data.data),
  quickTransfer: (assetId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<AssetTransfer>>(`/assets/${assetId}/transfer`, toForm(payload)).then((r) => r.data.data),
};

/* ── Pemeliharaan ────────────────────────────────────────────────────────── */

export const maintenanceApi = {
  requests: (params: ListParams = {}) =>
    api.get<PaginatedResponse<MaintenanceRequest>>('/maintenance/requests', { params }).then((r) => r.data),
  request: (id: number) => api.get<ApiResponse<MaintenanceRequest>>(`/maintenance/requests/${id}`).then((r) => r.data.data),
  createRequest: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<MaintenanceRequest>>('/maintenance/requests', toForm(payload)).then((r) => r.data.data),
  triage: (id: number, payload: { priority?: string; category?: string; notes?: string }) =>
    api.post<ApiResponse<MaintenanceRequest>>(`/maintenance/requests/${id}/triage`, toForm(payload)).then((r) => r.data.data),
  assign: (id: number, payload: { technician_id: number; priority?: string; type?: string; title?: string; description?: string }) =>
    api.post<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/requests/${id}/assign`, toForm(payload)).then((r) => r.data.data),

  workOrders: (params: ListParams = {}) =>
    api.get<PaginatedResponse<MaintenanceWorkOrder>>('/maintenance/work-orders', { params }).then((r) => r.data),
  workOrder: (id: number) => api.get<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}`).then((r) => r.data.data),
  start: (id: number, payload: { diagnosis?: string } = {}) =>
    api.post<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/start`, toForm(payload)).then((r) => r.data.data),
  /** Suku cadang HANYA lewat jalur ini: stok kurang berarti pekerjaan ditahan. */
  parts: (id: number, items: { code: string; quantity: number }[]) =>
    api.post<ApiResponse<{ issued: unknown[]; shortage: boolean; message?: string }>>(`/maintenance/work-orders/${id}/parts`, { items }).then((r) => r.data.data),
  externalService: (id: number, payload: { cost: string | number; vendor_id?: number | ''; description?: string }) =>
    api.post<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/external-service`, toForm(payload)).then((r) => r.data.data),
  addTask: (id: number, payload: { title: string; description?: string }) =>
    api.post<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/tasks`, toForm(payload)).then((r) => r.data.data),
  completeTask: (id: number, taskId: number, payload: { notes?: string } = {}) =>
    api.post<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/tasks/${taskId}/complete`, toForm(payload)).then((r) => r.data.data),
  complete: (id: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/complete`, toForm(payload)).then((r) => r.data.data),
  verify: (id: number, payload: { notes?: string } = {}) =>
    api.post<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/verify`, toForm(payload)).then((r) => r.data.data),
  cancel: (id: number, payload: { reason: string }) =>
    api.post<ApiResponse<MaintenanceWorkOrder>>(`/maintenance/work-orders/${id}/cancel`, toForm(payload)).then((r) => r.data.data),

  schedules: (params: ListParams = {}) =>
    api.get<PaginatedResponse<MaintenanceSchedule>>('/maintenance/schedules', { params }).then((r) => r.data),
  createSchedule: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<MaintenanceSchedule>>('/maintenance/schedules', toForm(payload)).then((r) => r.data.data),
  /** Menjalankan generator preventif — idempoten, aman ditekan berkali-kali. */
  runSchedules: () =>
    api.post<ApiResponse<{ generated: string[]; skipped: number }>>('/maintenance/schedules/run', {}).then((r) => r.data.data),
};

/* ── Inspeksi ────────────────────────────────────────────────────────────── */

export const inspectionsApi = {
  list: (params: ListParams = {}) => api.get<PaginatedResponse<AssetInspection>>('/inspections', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<AssetInspection>>(`/inspections/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) => api.post<ApiResponse<AssetInspection>>('/inspections', toForm(payload)).then((r) => r.data.data),
  perform: (id: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<AssetInspection>>(`/inspections/${id}/perform`, toForm(payload)).then((r) => r.data.data),
  templates: (params: ListParams = {}) =>
    api.get<ApiResponse<{ id: number; name: string; inspection_type: string; items: { code: string; label: string }[] }[]>>('/inspections/templates', { params }).then((r) => r.data.data),
};

/* ── Laporan kerusakan ───────────────────────────────────────────────────── */

export const damageApi = {
  list: (params: ListParams = {}) => api.get<PaginatedResponse<DamageReport>>('/damage-reports', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<DamageReport>>(`/damage-reports/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) => api.post<ApiResponse<DamageReport>>('/damage-reports', toForm(payload)).then((r) => r.data.data),
  verify: (id: number, payload: { accepted: boolean; notes?: string }) =>
    api.post<ApiResponse<DamageReport>>(`/damage-reports/${id}/verify`, toForm(payload)).then((r) => r.data.data),
  createMaintenance: (id: number, payload: { title: string; description?: string; priority?: string }) =>
    api.post<ApiResponse<MaintenanceRequest>>(`/damage-reports/${id}/maintenance`, toForm(payload)).then((r) => r.data.data),
};
