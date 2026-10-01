import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  Asset,
  AssetCategory,
  AssetCustodian,
  AssetFinancial,
  AssetImportPreview,
  AssetLabel,
  AssetLocation,
  AssetLocationTreeNode,
  AssetTimelineEntry,
  AssetType,
  DashboardOverview,
  ListParams,
} from '../types';

const toForm = <T extends object>(payload: T) => payload;

/* ── Aset ────────────────────────────────────────────────────────────────── */

export const assetsApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<Asset>>('/assets', { params }).then((r) => r.data),

  show: (id: number) => api.get<ApiResponse<Asset>>(`/assets/${id}`).then((r) => r.data.data),

  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<Asset>>('/assets', toForm(payload)).then((r) => r.data.data),

  update: (id: number, payload: Record<string, unknown>) =>
    api.patch<ApiResponse<Asset>>(`/assets/${id}`, toForm(payload)).then((r) => r.data.data),

  changeStatus: (id: number, payload: { status: string; reason?: string }) =>
    api.post<ApiResponse<Asset>>(`/assets/${id}/status`, toForm(payload)).then((r) => r.data.data),

  changeCondition: (id: number, payload: { condition: string; reason?: string }) =>
    api.post<ApiResponse<Asset>>(`/assets/${id}/condition`, toForm(payload)).then((r) => r.data.data),

  place: (id: number, payload: { location_id?: number | ''; custodian_id?: number | ''; custodian_type?: string }) =>
    api.post<ApiResponse<Asset>>(`/assets/${id}/place`, toForm(payload)).then((r) => r.data.data),

  archive: (id: number, payload: { reason?: string } = {}) =>
    api.post<ApiResponse<Asset>>(`/assets/${id}/archive`, toForm(payload)).then((r) => r.data.data),

  timeline: (id: number) =>
    api.get<ApiResponse<AssetTimelineEntry[]>>(`/assets/${id}/timeline`).then((r) => r.data.data),

  history: (id: number) =>
    api
      .get<ApiResponse<{ custodian_history: unknown[]; movements: unknown[]; valuations: unknown[] }>>(`/assets/${id}/history`)
      .then((r) => r.data.data),

  financial: (id: number) =>
    api.get<ApiResponse<AssetFinancial>>(`/assets/${id}/financial`).then((r) => r.data.data),

  documents: (id: number) =>
    api.get<ApiResponse<{ id: number; name: string; url: string; type?: string }[]>>(`/assets/${id}/documents`).then((r) => r.data.data),

  /** Perubahan resmi nilai aset (bukan sekadar menyunting kolom). */
  revalue: (id: number, payload: { valuation_type: string; amount: string | number; reason: string; effective_date?: string; document_reference?: string }) =>
    api.post<ApiResponse<Asset>>(`/assets/${id}/revalue`, toForm(payload)).then((r) => r.data.data),

  bulkStatus: (payload: { asset_ids: number[]; status: string; reason?: string }) =>
    api.post<ApiResponse<{ updated: number }>>('/assets/bulk/status', toForm(payload)).then((r) => r.data.data),

  bulkAssign: (payload: { asset_ids: number[]; custodian_id?: number | ''; location_id?: number | '' }) =>
    api.post<ApiResponse<{ updated: number }>>('/assets/bulk/assign', toForm(payload)).then((r) => r.data.data),

  bulkInspect: (payload: { asset_ids: number[]; inspection_type?: string }) =>
    api.post<ApiResponse<{ scheduled: number }>>('/assets/bulk/inspect', toForm(payload)).then((r) => r.data.data),

  bulkPrintLabels: (payload: { asset_ids: number[] }) =>
    api.post<ApiResponse<{ labels: AssetLabel[] }>>('/assets/bulk/print-labels', toForm(payload)).then((r) => r.data.data),

  /** Pencarian cepat lewat kode/QR — dipakai pemindai dan pencarian global. */
  lookup: (code: string) => api.get<ApiResponse<Asset>>(`/assets/lookup/${encodeURIComponent(code)}`).then((r) => r.data.data),
};

/* ── Label & QR ──────────────────────────────────────────────────────────── */

export const labelsApi = {
  get: (assetId: number) => api.get<ApiResponse<AssetLabel>>(`/assets/${assetId}/label`).then((r) => r.data.data),
  markPrinted: (assetId: number, payload: { printer?: string; copies?: number } = {}) =>
    api.post<ApiResponse<AssetLabel>>(`/assets/${assetId}/label/print`, toForm(payload)).then((r) => r.data.data),
  attach: (assetId: number, payload: { location_note?: string; attached_at?: string } = {}) =>
    api.post<ApiResponse<AssetLabel>>(`/assets/${assetId}/label/attach`, toForm(payload)).then((r) => r.data.data),
  replace: (assetId: number, payload: { reason: string }) =>
    api.post<ApiResponse<AssetLabel>>(`/assets/${assetId}/label/replace`, toForm(payload)).then((r) => r.data.data),
};

/* ── Lokasi ──────────────────────────────────────────────────────────────── */

export const locationsApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<AssetLocation>>('/locations', { params }).then((r) => r.data),
  tree: () => api.get<ApiResponse<AssetLocationTreeNode[]>>('/locations/tree').then((r) => r.data.data),
  show: (id: number) => api.get<ApiResponse<AssetLocation>>(`/locations/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<AssetLocation>>('/locations', toForm(payload)).then((r) => r.data.data),
  update: (id: number, payload: Record<string, unknown>) =>
    api.patch<ApiResponse<AssetLocation>>(`/locations/${id}`, toForm(payload)).then((r) => r.data.data),
  archive: (id: number, payload: { reason?: string } = {}) =>
    api.post<ApiResponse<AssetLocation>>(`/locations/${id}/archive`, toForm(payload)).then((r) => r.data.data),
};

/* ── Katalog & rujukan ───────────────────────────────────────────────────── */

export const metaApi = {
  categories: (params: ListParams = {}) =>
    api.get<ApiResponse<AssetCategory[]>>('/meta/categories', { params }).then((r) => r.data.data),
  createCategory: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<AssetCategory>>('/meta/categories', toForm(payload)).then((r) => r.data.data),
  createType: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<AssetType>>('/meta/types', toForm(payload)).then((r) => r.data.data),
  custodians: (params: ListParams = {}) =>
    api.get<ApiResponse<AssetCustodian[]>>('/meta/custodians', { params }).then((r) => r.data.data),
  createCustodian: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<AssetCustodian>>('/meta/custodians', toForm(payload)).then((r) => r.data.data),
  vendors: () => api.get<ApiResponse<{ id: number; name: string }[]>>('/meta/vendors').then((r) => r.data.data),
  /** Daftar nilai enum dari backend, sehingga layar tidak pernah mengarang pilihan. */
  enums: () => api.get<ApiResponse<Record<string, { value: string; label: string }[]>>>('/meta/enums').then((r) => r.data.data),
  search: (q: string) =>
    api
      .get<ApiResponse<{ assets: Asset[]; facilities: unknown[]; locations: unknown[] }>>('/meta/search', { params: { q } })
      .then((r) => r.data.data),
};

/* ── Impor ───────────────────────────────────────────────────────────────── */

export const importApi = {
  upload: (type: string, file: File) => {
    const form = new FormData();
    form.append('type', type);
    form.append('file', file);
    return api
      .post<ApiResponse<AssetImportPreview>>('/assets/import', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data.data);
  },
  confirm: (importId: number, payload: { skip_errors?: boolean } = {}) =>
    api.post<ApiResponse<AssetImportPreview>>(`/assets/import/${importId}/confirm`, toForm(payload)).then((r) => r.data.data),
};

/* ── Laporan & dasbor ────────────────────────────────────────────────────── */

export interface ReportRow {
  label: string;
  total: number;
  value?: string;
  [key: string]: unknown;
}

export const reportsApi = {
  get: (kind: string, params: ListParams = {}) =>
    api.get<ApiResponse<{ kind: string; rows: ReportRow[] }>>(`/reports/${kind}`, { params }).then((r) => r.data.data),
  exportUrl: (kind: string, params: ListParams = {}) => {
    const query = new URLSearchParams(
      Object.entries(params)
        .filter(([, v]) => v !== undefined && v !== '')
        .map(([k, v]) => [k, String(v)]),
    ).toString();
    return `/reports/${kind}/export${query ? `?${query}` : ''}`;
  },
  download: (kind: string, params: ListParams = {}) =>
    api.get(`/reports/${kind}/export`, { params, responseType: 'blob' }).then((r) => r.data as Blob),
};

export const dashboardApi = {
  overview: (params: ListParams = {}) =>
    api.get<ApiResponse<DashboardOverview>>('/dashboard/overview', { params }).then((r) => r.data.data),
  assets: (params: ListParams = {}) => api.get<ApiResponse<DashboardOverview['assets']>>('/dashboard/assets', { params }).then((r) => r.data.data),
  maintenance: (params: ListParams = {}) => api.get<ApiResponse<DashboardOverview['maintenance']>>('/dashboard/maintenance', { params }).then((r) => r.data.data),
  facilities: (params: ListParams = {}) => api.get<ApiResponse<DashboardOverview['facilities']>>('/dashboard/facilities', { params }).then((r) => r.data.data),
  stockOpnames: (params: ListParams = {}) => api.get<ApiResponse<DashboardOverview['opname']>>('/dashboard/stock-opnames', { params }).then((r) => r.data.data),
  reconciliation: (params: ListParams = {}) =>
    api.get<ApiResponse<{ rows: { label: string; subledger: string; general_ledger: string; difference: string }[] }>>('/dashboard/reconciliation', { params }).then((r) => r.data.data),
};

export const depreciationApi = {
  preview: (params: { period: string; asset_id?: number }) =>
    api.get<ApiResponse<{ period: string; rows: Record<string, unknown>[]; total: string }>>('/depreciations/preview', { params }).then((r) => r.data.data),
  run: (params: { period: string; asset_id?: number }) =>
    api.post<ApiResponse<{ period: string; processed: number; total: string }>>('/depreciations/run', toForm(params)).then((r) => r.data.data),
};
