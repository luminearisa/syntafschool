import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  AssetAuditEntry,
  AssetReplacement,
  DisposalRequest,
  ListParams,
  StockOpname,
  StockOpnameItem,
  StockOpnameSummary,
  StockOpnameVariance,
} from '../types';

const toForm = <T extends object>(payload: T) => payload;

/* ── Stok opname (penghitungan fisik) ────────────────────────────────────── */

export const opnamesApi = {
  list: (params: ListParams = {}) => api.get<PaginatedResponse<StockOpname>>('/stock-opnames', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<StockOpname>>(`/stock-opnames/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) => api.post<ApiResponse<StockOpname>>('/stock-opnames', toForm(payload)).then((r) => r.data.data),
  start: (id: number) => api.post<ApiResponse<StockOpname>>(`/stock-opnames/${id}/start`, {}).then((r) => r.data.data),
  /**
   * Hasil pemindaian: `{ item, result, is_variance }`.
   *
   * `result` dibalas terpisah dari `item` karena hasilnya menentukan nada
   * pemberitahuan (ditemukan / salah lokasi / tidak dikenal) sebelum barisnya
   * sempat dibaca.
   */
  scan: (id: number, payload: { code: string; location_id?: number | ''; custodian_id?: number | ''; condition?: string; notes?: string }) =>
    api
      .post<ApiResponse<{ item: StockOpnameItem; result: string; is_variance: boolean }>>(`/stock-opnames/${id}/scan`, toForm(payload))
      .then((r) => r.data.data),
  summary: (id: number) => api.get<ApiResponse<StockOpnameSummary>>(`/stock-opnames/${id}/summary`).then((r) => r.data.data),
  verify: (id: number) => api.post<ApiResponse<StockOpname>>(`/stock-opnames/${id}/verify`, {}).then((r) => r.data.data),
  decideVariance: (opnameId: number, varianceId: number, payload: { decision: string; notes?: string }) =>
    api.post<ApiResponse<StockOpnameVariance>>(`/stock-opnames/${opnameId}/variances/${varianceId}/decide`, toForm(payload)).then((r) => r.data.data),
  /** Penyesuaian hanya diterapkan di sini — setelah keputusan manusia. */
  complete: (id: number) => api.post<ApiResponse<StockOpname>>(`/stock-opnames/${id}/complete`, {}).then((r) => r.data.data),
};

/* ── Penghapusan ─────────────────────────────────────────────────────────── */

export const disposalsApi = {
  list: (params: ListParams = {}) => api.get<PaginatedResponse<DisposalRequest>>('/disposals', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<DisposalRequest>>(`/disposals/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) => api.post<ApiResponse<DisposalRequest>>('/disposals', toForm(payload)).then((r) => r.data.data),
  inspect: (id: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<DisposalRequest>>(`/disposals/${id}/inspect`, toForm(payload)).then((r) => r.data.data),
  value: (id: number, payload: { estimated_value?: string | number; expected_proceeds?: string | number; document_reference?: string }) =>
    api.post<ApiResponse<DisposalRequest>>(`/disposals/${id}/value`, toForm(payload)).then((r) => r.data.data),
  approve: (id: number, payload: { notes?: string } = {}) =>
    api.post<ApiResponse<DisposalRequest>>(`/disposals/${id}/approve`, toForm(payload)).then((r) => r.data.data),
  reject: (id: number, payload: { reason: string }) =>
    api.post<ApiResponse<DisposalRequest>>(`/disposals/${id}/reject`, toForm(payload)).then((r) => r.data.data),
  execute: (id: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<DisposalRequest>>(`/disposals/${id}/execute`, toForm(payload)).then((r) => r.data.data),
  retire: (id: number, payload: { notes?: string } = {}) =>
    api.post<ApiResponse<DisposalRequest>>(`/disposals/${id}/retire`, toForm(payload)).then((r) => r.data.data),
};

/* ── Penggantian ─────────────────────────────────────────────────────────── */

export const replacementsApi = {
  list: (params: ListParams = {}) => api.get<PaginatedResponse<AssetReplacement>>('/replacements', { params }).then((r) => r.data),
  review: (id: number, payload: { decision: string; notes?: string }) =>
    api.post<ApiResponse<AssetReplacement>>(`/replacements/${id}/review`, toForm(payload)).then((r) => r.data.data),
  /** Hanya MENERUSKAN permintaan ke Pengadaan; pesanan pembelian bukan urusan modul ini. */
  forwardToProcurement: (id: number, payload: { title: string; quantity?: number; estimated_cost?: string | number }) =>
    api.post<ApiResponse<AssetReplacement>>(`/replacements/${id}/procurement`, toForm(payload)).then((r) => r.data.data),
  quick: (assetId: number) => api.get<ApiResponse<AssetReplacement>>(`/assets/${assetId}/replacement`).then((r) => r.data.data),
};

/* ── Tata kelola ─────────────────────────────────────────────────────────── */

export const auditLogApi = {
  list: (params: ListParams = {}) => api.get<PaginatedResponse<AssetAuditEntry>>('/audit-logs', { params }).then((r) => r.data),
};

export const settingsApi = {
  get: () => api.get<ApiResponse<Record<string, unknown>>>('/settings/general').then((r) => r.data.data),
  update: (payload: Record<string, unknown>) => api.put<ApiResponse<Record<string, unknown>>>('/settings/general', toForm(payload)).then((r) => r.data.data),
};
