import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  AcquisitionPayload,
  AcquisitionReport,
  CirculationLookupResult,
  CirculationStats,
  CollectionReport,
  CirculationReport,
  ExportType,
  FineReport,
  FineSummary,
  ImportPreview,
  ImportRow,
  ImportType,
  InventoryReport,
  InventoryReportBundle,
  InventoryResult,
  InventorySummary,
  LibraryAcquisition,
  LibraryFine,
  LibraryInventorySession,
  LibraryLoan,
  LibraryNotification,
  LibraryReservation,
  LoanCheckoutPayload,
  MemberQuickSummary,
  MemberReport,
} from '@/types/libraryCirculation';
import type {
  BibliographyIdentifier,
  BibliographyPayload,
  BibliographyStatus,
  DuplicateCheckResult,
  ItemStatus,
  LibraryBibliography,
  LibraryItem,
  LibraryItemBulkPayload,
  LibraryItemPayload,
  LibraryMember,
  LibraryMemberCard,
  LibraryMemberPayload,
  LibraryMemberSummary,
  MemberStatus,
  OpacAvailability,
  OpacRecord,
} from '@/types/libraryCatalog';
import type {
  LibraryMasterBulkStatusPayload,
  LibraryMasterMeta,
  LibraryMasterMetaResponse,
  LibraryMasterPayload,
  LibraryMasterRecord,
} from '@/types/libraryMaster';
import type {
  Library,
  LibraryActivity,
  LibraryClosure,
  LibraryClosurePayload,
  LibraryDashboard,
  LibraryListParams,
  LibraryLocation,
  LibraryLocationPayload,
  LibraryNumberingConfig,
  LibraryNumberingConfigInput,
  LibraryOperatingHour,
  LibraryOperatingHourRow,
  LibraryPayload,
  LibrarySetupSummary,
  LibrarySettingsGrouped,
  LibraryTransitionPayload,
} from '@/types/library';

function buildParams(params: LibraryListParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.sort_by) out.sort_by = params.sort_by;
  if (params.sort_dir) out.sort_dir = params.sort_dir;
  if (params.filter && Object.keys(params.filter).length > 0) out.filter = params.filter;
  return out;
}

/**
 * Buang field opsional yang masih berupa string kosong.
 *
 * Form memakai `''` sebagai "belum diisi"; kalau dikirim apa adanya, aturan
 * `integer`/`date_format` bisa menolaknya walau field itu opsional.
 */
function stripEmpty<T extends object>(payload: T): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(payload)) {
    if (value === '') continue;
    out[key] = value;
  }
  return out;
}

const base = '/library';

/* ============================ PERPUSTAKAAN ============================ */

export const librariesApi = {
  async list(params: LibraryListParams = {}) {
    const res = await api.get<PaginatedResponse<Library>>(`${base}/libraries`, {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<Library>>(`${base}/libraries/${id}`);
    return res.data.data;
  },

  async create(payload: LibraryPayload) {
    const res = await api.post<ApiResponse<Library>>(
      `${base}/libraries`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async update(id: number, payload: LibraryPayload) {
    const res = await api.put<ApiResponse<Library>>(
      `${base}/libraries/${id}`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`${base}/libraries/${id}`);
  },

  async transition(id: number, payload: LibraryTransitionPayload) {
    const res = await api.post<ApiResponse<Library>>(
      `${base}/libraries/${id}/transition`,
      stripEmpty(payload),
    );
    return res.data;
  },

  async dashboard(id: number) {
    const res = await api.get<ApiResponse<LibraryDashboard>>(
      `${base}/libraries/${id}/dashboard`,
    );
    return res.data.data;
  },

  async setupStatus(id: number) {
    const res = await api.get<ApiResponse<LibrarySetupSummary>>(
      `${base}/libraries/${id}/setup-status`,
    );
    return res.data.data;
  },
};

/* ============================ KONFIGURASI ============================ */

export const librarySettingsApi = {
  async show(libraryId: number) {
    const res = await api.get<ApiResponse<{ settings: LibrarySettingsGrouped }>>(
      `${base}/libraries/${libraryId}/settings`,
    );
    return res.data.data.settings;
  },

  async update(libraryId: number, settings: Record<string, unknown>) {
    const res = await api.put<
      ApiResponse<{ settings: LibrarySettingsGrouped; changed: Record<string, unknown> }>
    >(`${base}/libraries/${libraryId}/settings`, { settings });
    return res.data;
  },
};

/* ============================ LOKASI ============================ */

export const libraryLocationsApi = {
  async list(libraryId: number, params: LibraryListParams & { tree?: boolean } = {}) {
    const query = buildParams(params);
    if (params.tree) query.tree = 1;

    const res = await api.get<PaginatedResponse<LibraryLocation> | ApiResponse<LibraryLocation[]>>(
      `${base}/libraries/${libraryId}/locations`,
      { params: query },
    );
    return res.data;
  },

  async create(libraryId: number, payload: LibraryLocationPayload) {
    const res = await api.post<ApiResponse<LibraryLocation>>(
      `${base}/libraries/${libraryId}/locations`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async update(libraryId: number, id: number, payload: LibraryLocationPayload) {
    const res = await api.put<ApiResponse<LibraryLocation>>(
      `${base}/libraries/${libraryId}/locations/${id}`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async remove(libraryId: number, id: number) {
    await api.delete(`${base}/libraries/${libraryId}/locations/${id}`);
  },

  async move(libraryId: number, id: number, parentId: number | '') {
    const res = await api.post<ApiResponse<LibraryLocation>>(
      `${base}/libraries/${libraryId}/locations/${id}/move`,
      { parent_id: parentId === '' ? null : parentId },
    );
    return res.data.data;
  },
};

/* ============================ JAM OPERASIONAL ============================ */

export const libraryOperatingHoursApi = {
  async list(libraryId: number) {
    const res = await api.get<ApiResponse<LibraryOperatingHour[]>>(
      `${base}/libraries/${libraryId}/operating-hours`,
    );
    return res.data.data;
  },

  async update(libraryId: number, hours: LibraryOperatingHourRow[]) {
    const res = await api.put<ApiResponse<LibraryOperatingHour[]>>(
      `${base}/libraries/${libraryId}/operating-hours`,
      { hours },
    );
    return res.data;
  },
};

/* ============================ PENUTUPAN ============================ */

export const libraryClosuresApi = {
  async list(libraryId: number, params: LibraryListParams & { from?: string; to?: string } = {}) {
    const query = buildParams(params);
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;

    const res = await api.get<PaginatedResponse<LibraryClosure>>(
      `${base}/libraries/${libraryId}/closures`,
      { params: query },
    );
    return res.data;
  },

  async create(libraryId: number, payload: LibraryClosurePayload) {
    const res = await api.post<ApiResponse<LibraryClosure>>(
      `${base}/libraries/${libraryId}/closures`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async update(libraryId: number, id: number, payload: LibraryClosurePayload) {
    const res = await api.put<ApiResponse<LibraryClosure>>(
      `${base}/libraries/${libraryId}/closures/${id}`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async remove(libraryId: number, id: number) {
    await api.delete(`${base}/libraries/${libraryId}/closures/${id}`);
  },
};

/* ============================ PENOMORAN ============================ */

export const libraryNumberingApi = {
  async list(libraryId: number) {
    const res = await api.get<ApiResponse<LibraryNumberingConfig[]>>(
      `${base}/libraries/${libraryId}/numbering`,
    );
    return res.data.data;
  },

  async update(libraryId: number, configs: LibraryNumberingConfigInput[]) {
    const res = await api.put<ApiResponse<LibraryNumberingConfig[]>>(
      `${base}/libraries/${libraryId}/numbering`,
      { configs },
    );
    return res.data;
  },
};

/* ============================ JEJAK AUDIT ============================ */

export const libraryAuditLogsApi = {
  async list(
    params: LibraryListParams & {
      event?: string;
      subject_type?: string;
      subject_id?: number | '';
      from?: string;
      to?: string;
    } = {},
  ) {
    const query = buildParams(params);
    if (params.event) query.event = params.event;
    if (params.subject_type) query.subject_type = params.subject_type;
    if (params.subject_id) query.subject_id = params.subject_id;
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;

    const res = await api.get<PaginatedResponse<LibraryActivity>>(`${base}/audit-logs`, {
      params: query,
    });
    return res.data;
  },
};

/* ============================ MASTER DATA (Step 2) ============================ */

/**
 * Master data Library.
 *
 * Seluruh master memakai satu controller generik di backend; `key` adalah
 * path master (mis. `collection-types`, `subjects`).
 */
export const libraryMastersApi = {
  /** Definisi seluruh master — menyetir tabel & form di antarmuka. */
  async meta() {
    const res = await api.get<ApiResponse<LibraryMasterMetaResponse>>(`${base}/master-meta`);
    return res.data.data.masters;
  },

  async list(key: string, params: LibraryListParams = {}) {
    const res = await api.get<PaginatedResponse<LibraryMasterRecord>>(`${base}/${key}`, {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(key: string, id: number) {
    const res = await api.get<ApiResponse<LibraryMasterRecord>>(`${base}/${key}/${id}`);
    return res.data.data;
  },

  async create(key: string, payload: LibraryMasterPayload) {
    const res = await api.post<ApiResponse<LibraryMasterRecord>>(
      `${base}/${key}`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async update(key: string, id: number, payload: LibraryMasterPayload) {
    const res = await api.put<ApiResponse<LibraryMasterRecord>>(
      `${base}/${key}/${id}`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async remove(key: string, id: number) {
    await api.delete(`${base}/${key}/${id}`);
  },

  /** Aktifkan / nonaktifkan banyak data sekaligus. */
  async bulkStatus(key: string, payload: LibraryMasterBulkStatusPayload) {
    const res = await api.post<ApiResponse<{ updated: number }>>(
      `${base}/${key}/bulk-status`,
      payload,
    );
    return res.data;
  },
};

export type { LibraryMasterMeta };

/* ============================ KATALOG (Fase 3) ============================ */

export const libraryCatalogApi = {
  async list(params: LibraryListParams & { year_from?: number; year_to?: number } = {}) {
    const query = buildParams(params);
    if (params.year_from) query.year_from = params.year_from;
    if (params.year_to) query.year_to = params.year_to;

    const res = await api.get<PaginatedResponse<LibraryBibliography>>(`${base}/catalog`, { params: query });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<LibraryBibliography>>(`${base}/catalog/${id}`);
    return res.data.data;
  },

  async create(payload: BibliographyPayload) {
    const res = await api.post<ApiResponse<LibraryBibliography>>(`${base}/catalog`, stripEmpty(payload));
    return res.data.data;
  },

  async update(id: number, payload: BibliographyPayload) {
    const res = await api.put<ApiResponse<LibraryBibliography>>(`${base}/catalog/${id}`, stripEmpty(payload));
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`${base}/catalog/${id}`);
  },

  /** Deteksi kemungkinan duplikat sebelum menyimpan (§18). */
  async duplicateCheck(payload: {
    title: string;
    publisher_id?: number | '';
    publication_year?: number | '';
    identifiers?: BibliographyIdentifier[];
    ignore_id?: number;
  }) {
    const res = await api.post<ApiResponse<DuplicateCheckResult>>(
      `${base}/catalog/duplicate-check`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async availability(id: number) {
    const res = await api.get<ApiResponse<OpacAvailability>>(`${base}/catalog/${id}/availability`);
    return res.data.data;
  },

  async bulkStatus(ids: number[], status: BibliographyStatus, reason?: string) {
    const res = await api.post<ApiResponse<{ updated: number }>>(`${base}/catalog/bulk-status`, {
      ids,
      status,
      ...(reason ? { reason } : {}),
    });
    return res.data;
  },
};

export const libraryItemsApi = {
  async list(params: LibraryListParams = {}) {
    const res = await api.get<PaginatedResponse<LibraryItem>>(`${base}/items`, { params: buildParams(params) });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<LibraryItem>>(`${base}/items/${id}`);
    return res.data.data;
  },

  async update(id: number, payload: LibraryItemPayload) {
    const res = await api.put<ApiResponse<LibraryItem>>(`${base}/items/${id}`, stripEmpty(payload));
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`${base}/items/${id}`);
  },

  async setStatus(id: number, status: ItemStatus, reason?: string) {
    const res = await api.post<ApiResponse<LibraryItem>>(`${base}/items/${id}/status`, {
      status,
      ...(reason ? { reason } : {}),
    });
    return res.data;
  },

  async bulkAssign(payload: {
    ids: number[];
    location_id?: number | '';
    condition_id?: number | '';
    status_id?: number | '';
    status?: ItemStatus | '';
  }) {
    const res = await api.post<ApiResponse<{ updated: number }>>(
      `${base}/items/bulk-assign`,
      stripEmpty(payload),
    );
    return res.data;
  },

  async bulkGenerateBarcode(ids: number[]) {
    const res = await api.post<ApiResponse<{ generated: number }>>(`${base}/items/bulk-barcode`, { ids });
    return res.data;
  },

  /** Eksemplar milik satu bibliographic record. */
  async ofBibliography(bibliographyId: number) {
    const res = await api.get<ApiResponse<LibraryItem[]>>(`${base}/catalog/${bibliographyId}/items`);
    return res.data.data;
  },

  async createFor(bibliographyId: number, payload: LibraryItemPayload) {
    const res = await api.post<ApiResponse<LibraryItem>>(
      `${base}/catalog/${bibliographyId}/items`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  /** Tambah banyak eksemplar sekaligus (§28). */
  async bulkCreateFor(bibliographyId: number, payload: LibraryItemBulkPayload) {
    const res = await api.post<ApiResponse<LibraryItem[]>>(
      `${base}/catalog/${bibliographyId}/items/bulk`,
      stripEmpty(payload),
    );
    return res.data;
  },
};

export const libraryMembersApi = {
  async list(params: LibraryListParams & { linked_type?: string } = {}) {
    const query = buildParams(params);
    if (params.linked_type) query.linked_type = params.linked_type;

    const res = await api.get<PaginatedResponse<LibraryMember>>(`${base}/members`, { params: query });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<LibraryMember>>(`${base}/members/${id}`);
    return res.data.data;
  },

  async create(payload: LibraryMemberPayload) {
    const res = await api.post<ApiResponse<LibraryMember>>(`${base}/members`, stripEmpty(payload));
    return res.data.data;
  },

  async update(id: number, payload: LibraryMemberPayload) {
    const res = await api.put<ApiResponse<LibraryMember>>(`${base}/members/${id}`, stripEmpty(payload));
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`${base}/members/${id}`);
  },

  async setStatus(id: number, status: MemberStatus, reason?: string) {
    const res = await api.post<ApiResponse<LibraryMember>>(`${base}/members/${id}/status`, {
      status,
      ...(reason ? { reason } : {}),
    });
    return res.data;
  },

  async renew(id: number, validUntil: string, validFrom?: string) {
    const res = await api.post<ApiResponse<LibraryMember>>(`${base}/members/${id}/renew`, {
      valid_until: validUntil,
      ...(validFrom ? { valid_from: validFrom } : {}),
    });
    return res.data;
  },

  async summary(libraryId?: number | '') {
    const res = await api.get<ApiResponse<LibraryMemberSummary>>(`${base}/members/summary`, {
      params: libraryId ? { library_id: libraryId } : {},
    });
    return res.data.data;
  },

  /** Tandai keanggotaan yang lewat masa berlaku sebagai kedaluwarsa. */
  async evaluateStatuses(libraryId?: number | '') {
    const res = await api.post<ApiResponse<{ expired: number }>>(`${base}/members/evaluate-statuses`, {
      ...(libraryId ? { library_id: libraryId } : {}),
    });
    return res.data;
  },
};

export const libraryMemberCardsApi = {
  async ofMember(memberId: number) {
    const res = await api.get<ApiResponse<LibraryMemberCard | null>>(`${base}/members/${memberId}/card`);
    return res.data.data;
  },

  async generate(memberId: number, payload: { valid_until?: string; barcode?: string } = {}) {
    const res = await api.post<ApiResponse<LibraryMemberCard>>(
      `${base}/members/${memberId}/card/generate`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async batch(memberIds: number[], validUntil?: string) {
    const res = await api.post<ApiResponse<{ generated: number; skipped: number[] }>>(
      `${base}/members/cards/batch`,
      { member_ids: memberIds, ...(validUntil ? { valid_until: validUntil } : {}) },
    );
    return res.data;
  },

  async markPrinted(cardId: number) {
    const res = await api.post<ApiResponse<LibraryMemberCard>>(`${base}/member-cards/${cardId}/printed`);
    return res.data;
  },

  async revoke(cardId: number, reason: string) {
    const res = await api.post<ApiResponse<LibraryMemberCard>>(`${base}/member-cards/${cardId}/revoke`, {
      reason,
    });
    return res.data;
  },

  /** "Kartu Saya" — kartu milik pengguna yang sedang login (§40). */
  async mine() {
    const res = await api.get<ApiResponse<LibraryMemberCard | null>>(`${base}/members/me/card`);
    return res.data.data;
  },
};

/* ============================ SIRKULASI (Fase 4) ============================ */

export const libraryCirculationApi = {
  async stats(params: { library_id?: number | ''; months?: number; limit?: number } = {}) {
    const res = await api.get<ApiResponse<CirculationStats>>(`${base}/circulation/stats`, {
      params: buildParams(params as LibraryListParams),
    });
    return res.data.data;
  },

  async lookup(code: string) {
    const res = await api.get<ApiResponse<CirculationLookupResult>>(`${base}/circulation/lookup`, {
      params: { code },
    });
    return res.data.data;
  },

  async memberSummary(memberId: number) {
    const res = await api.get<ApiResponse<MemberQuickSummary>>(
      `${base}/circulation/members/${memberId}/summary`,
    );
    return res.data.data;
  },

  async checkout(payload: LoanCheckoutPayload) {
    const res = await api.post<ApiResponse<LibraryLoan>>(`${base}/circulation/checkout`, stripEmpty(payload));
    return res.data;
  },

  async markOverdue(libraryId?: number | '') {
    const res = await api.post<ApiResponse<{ marked: number }>>(`${base}/circulation/overdue/mark`, {
      ...(libraryId ? { library_id: libraryId } : {}),
    });
    return res.data;
  },

  async list(params: LibraryListParams & { open?: boolean; overdue?: boolean; from?: string; to?: string } = {}) {
    const query = buildParams(params);
    if (params.open) query.open = 1;
    if (params.overdue) query.overdue = 1;
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;

    const res = await api.get<PaginatedResponse<LibraryLoan>>(`${base}/loans`, { params: query });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<LibraryLoan>>(`${base}/loans/${id}`);
    return res.data.data;
  },

  async returnLoan(
    id: number,
    payload: { condition_id?: number | ''; status?: string; notes?: string } = {},
  ) {
    const res = await api.post<ApiResponse<LibraryLoan>>(`${base}/loans/${id}/return`, stripEmpty(payload));
    return res.data;
  },

  async renew(id: number, notes?: string) {
    const res = await api.post<ApiResponse<LibraryLoan>>(`${base}/loans/${id}/renew`, notes ? { notes } : {});
    return res.data;
  },
};

export const libraryFinesApi = {
  async list(params: LibraryListParams & { outstanding?: boolean; from?: string; to?: string } = {}) {
    const query = buildParams(params);
    if (params.outstanding) query.outstanding = 1;
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;

    const res = await api.get<PaginatedResponse<LibraryFine>>(`${base}/fines`, { params: query });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<LibraryFine>>(`${base}/fines/${id}`);
    return res.data.data;
  },

  async create(payload: { member_id: number | ''; type?: string; amount: number | ''; notes?: string }) {
    const res = await api.post<ApiResponse<LibraryFine>>(`${base}/fines`, stripEmpty(payload));
    return res.data;
  },

  async pay(
    id: number,
    payload: { amount: number | ''; paid_at?: string; payment_method_id?: number | ''; reference?: string; notes?: string },
  ) {
    const res = await api.post<ApiResponse<LibraryFine>>(`${base}/fines/${id}/pay`, stripEmpty(payload));
    return res.data;
  },

  async waive(id: number, reason: string, amount?: number | '') {
    const res = await api.post<ApiResponse<LibraryFine>>(`${base}/fines/${id}/waive`, stripEmpty({ reason, amount }));
    return res.data;
  },

  async summary(libraryId?: number | '') {
    const res = await api.get<ApiResponse<FineSummary>>(`${base}/fines/summary`, {
      params: libraryId ? { library_id: libraryId } : {},
    });
    return res.data.data;
  },
};

export const libraryReservationsApi = {
  async list(params: LibraryListParams & { open?: boolean } = {}) {
    const query = buildParams(params);
    if (params.open) query.open = 1;

    const res = await api.get<PaginatedResponse<LibraryReservation>>(`${base}/reservations`, { params: query });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<LibraryReservation>>(`${base}/reservations/${id}`);
    return res.data.data;
  },

  async create(payload: { member_id?: number | ''; bibliography_id: number | ''; notes?: string }) {
    const res = await api.post<ApiResponse<LibraryReservation>>(`${base}/reservations`, stripEmpty(payload));
    return res.data;
  },

  async pickup(id: number, notes?: string) {
    const res = await api.post<ApiResponse<{ reservation: LibraryReservation; loan: LibraryLoan }>>(
      `${base}/reservations/${id}/pickup`,
      notes ? { notes } : {},
    );
    return res.data;
  },

  async cancel(id: number, reason: string) {
    const res = await api.post<ApiResponse<LibraryReservation>>(`${base}/reservations/${id}/cancel`, { reason });
    return res.data;
  },

  async expire(libraryId?: number | '') {
    const res = await api.post<ApiResponse<{ expired: number }>>(`${base}/reservations/expire`, {
      ...(libraryId ? { library_id: libraryId } : {}),
    });
    return res.data;
  },

  async summary(libraryId?: number | '') {
    const res = await api.get<ApiResponse<{ waiting: number; available: number; open: number; expired: number }>>(
      `${base}/reservations/summary`,
      { params: libraryId ? { library_id: libraryId } : {} },
    );
    return res.data.data;
  },
};

/* ============================ PENGADAAN (Fase 4) ============================ */

export const libraryAcquisitionsApi = {
  async list(params: LibraryListParams & { open?: boolean; from?: string; to?: string } = {}) {
    const query = buildParams(params);
    if (params.open) query.open = 1;
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;

    const res = await api.get<PaginatedResponse<LibraryAcquisition>>(`${base}/acquisitions`, { params: query });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<LibraryAcquisition>>(`${base}/acquisitions/${id}`);
    return res.data.data;
  },

  async create(payload: AcquisitionPayload) {
    const res = await api.post<ApiResponse<LibraryAcquisition>>(`${base}/acquisitions`, stripEmpty(payload));
    return res.data;
  },

  async update(id: number, payload: AcquisitionPayload) {
    const res = await api.put<ApiResponse<LibraryAcquisition>>(
      `${base}/acquisitions/${id}`,
      stripEmpty(payload),
    );
    return res.data;
  },

  async remove(id: number) {
    await api.delete(`${base}/acquisitions/${id}`);
  },

  async transition(id: number, status: string, reason?: string) {
    const res = await api.post<ApiResponse<LibraryAcquisition>>(
      `${base}/acquisitions/${id}/transition`,
      stripEmpty({ status, reason }),
    );
    return res.data;
  },

  async receive(id: number, receivedAt?: string) {
    const res = await api.post<ApiResponse<{ request: LibraryAcquisition; created_items: number }>>(
      `${base}/acquisitions/${id}/receive`,
      receivedAt ? { received_at: receivedAt } : {},
    );
    return res.data;
  },

  async summary(params: { library_id?: number | ''; from?: string; to?: string } = {}) {
    const res = await api.get<ApiResponse<AcquisitionReport>>(`${base}/acquisitions/summary`, {
      params: stripEmpty(params),
    });
    return res.data.data;
  },
};

/* ============================ INVENTARIS (Fase 4) ============================ */

export const libraryInventoryApi = {
  async list(params: LibraryListParams = {}) {
    const res = await api.get<PaginatedResponse<LibraryInventorySession>>(`${base}/inventory-sessions`, {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<InventorySummary>>(`${base}/inventory-sessions/${id}`);
    return res.data.data;
  },

  async create(payload: { library_id: number | ''; name: string; location_id?: number | ''; started_at?: string; notes?: string }) {
    const res = await api.post<ApiResponse<LibraryInventorySession>>(
      `${base}/inventory-sessions`,
      stripEmpty(payload),
    );
    return res.data;
  },

  async scan(id: number, payload: { code: string; condition_id?: number | ''; notes?: string }) {
    const res = await api.post<
      ApiResponse<{ result: InventoryResult; scanned_code: string | null; item_id: number | null; is_unexpected: boolean }>
    >(`${base}/inventory-sessions/${id}/scan`, stripEmpty(payload));
    return res.data;
  },

  async finalize(id: number, payload: { mark_missing_as_lost?: boolean; notes?: string } = {}) {
    const res = await api.post<
      ApiResponse<{ expected: number; found: number; missing: number; unexpected: number; damaged: number }>
    >(`${base}/inventory-sessions/${id}/finalize`, stripEmpty(payload));
    return res.data;
  },

  async report(params: { library_id?: number | ''; from?: string; to?: string } = {}) {
    const res = await api.get<ApiResponse<InventoryReport>>(`${base}/inventory-report`, {
      params: stripEmpty(params),
    });
    return res.data.data;
  },
};

/* ============================ LAPORAN, EKSPOR, IMPOR (Phase 5) ============================ */

function downloadBlob(data: Blob, filename: string): void {
  const url = window.URL.createObjectURL(data);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.URL.revokeObjectURL(url);
}

export const libraryReportsApi = {
  async collection(params: Record<string, unknown> = {}) {
    const res = await api.get<ApiResponse<CollectionReport>>(`${base}/reports/collection`, { params });
    return res.data.data;
  },

  async circulation(params: Record<string, unknown> = {}) {
    const res = await api.get<ApiResponse<CirculationReport>>(`${base}/reports/circulation`, { params });
    return res.data.data;
  },

  async member(params: Record<string, unknown> = {}) {
    const res = await api.get<ApiResponse<MemberReport>>(`${base}/reports/member`, { params });
    return res.data.data;
  },

  async fine(params: Record<string, unknown> = {}) {
    const res = await api.get<ApiResponse<FineReport>>(`${base}/reports/fine`, { params });
    return res.data.data;
  },

  async acquisition(params: Record<string, unknown> = {}) {
    const res = await api.get<ApiResponse<AcquisitionReport>>(`${base}/reports/acquisition`, { params });
    return res.data.data;
  },

  async inventory(params: Record<string, unknown> = {}) {
    const res = await api.get<ApiResponse<InventoryReportBundle>>(`${base}/reports/inventory`, { params });
    return res.data.data;
  },

  async reservation(libraryId?: number | '') {
    const res = await api.get<
      ApiResponse<{
        summary: { waiting: number; available: number; open: number; expired: number };
        queue: Record<string, unknown>[];
      }>
    >(`${base}/reports/reservation`, { params: libraryId ? { library_id: libraryId } : {} });
    return res.data.data;
  },

  /** Unduh CSV (§4) — CSV kompatibel dibuka Excel. */
  async exportCsv(type: ExportType, params: Record<string, unknown> = {}) {
    const res = await api.get<Blob>(`${base}/export`, {
      params: { type, ...params },
      responseType: 'blob',
    });

    downloadBlob(res.data, `library-${type}-${new Date().toISOString().slice(0, 10)}.csv`);
  },
};

/** Dokumen PDF: kartu anggota, label eksemplar, dan laporan (§3, §4, §6). */
export const libraryDocumentsApi = {
  async memberCardPdf(memberId: number, filename?: string) {
    const res = await api.get<Blob>(`${base}/members/${memberId}/card/pdf`, { responseType: 'blob' });
    downloadBlob(res.data, filename ?? `kartu-anggota-${memberId}.pdf`);
  },

  /** Label eksemplar: pilih beberapa eksemplar, atau seluruh eksemplar satu judul. */
  async labelsPdf(
    payload: { item_ids?: number[]; bibliography_id?: number | '' },
    filename?: string,
  ) {
    const res = await api.post<Blob>(`${base}/labels/pdf`, stripEmpty(payload), {
      responseType: 'blob',
    });
    downloadBlob(res.data, filename ?? 'label-eksemplar.pdf');
  },

  async reportPdf(type: string, params: Record<string, unknown> = {}) {
    const res = await api.get<Blob>(`${base}/reports/${type}/pdf`, {
      params,
      responseType: 'blob',
    });
    downloadBlob(res.data, `laporan-${type}.pdf`);
  },
};

export const libraryImportsApi = {
  async downloadTemplate(type: ImportType) {
    const res = await api.get<Blob>(`${base}/imports/${type}/template`, { responseType: 'blob' });
    downloadBlob(res.data, `template-import-${type}.csv`);
  },

  /** Pratinjau: total/valid/invalid/duplikat + error per baris (§5). */
  async preview(type: ImportType, libraryId: number | '', file: File) {
    const form = new FormData();
    form.append('library_id', String(libraryId));
    form.append('file', file);

    const res = await api.post<ApiResponse<ImportPreview>>(`${base}/imports/${type}/preview`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    return res.data.data;
  },

  /** Simpan hanya baris valid. */
  async commit(type: ImportType, libraryId: number | '', rows: ImportRow[]) {
    const res = await api.post<ApiResponse<{ imported: number; skipped: number }>>(
      `${base}/imports/${type}/commit`,
      { library_id: libraryId, rows },
    );
    return res.data;
  },
};

/* ============================ NOTIFIKASI (Phase 5) ============================ */

export const libraryNotificationsApi = {
  async list(params: LibraryListParams = {}) {
    const res = await api.get<PaginatedResponse<LibraryNotification>>(`${base}/notifications`, {
      params: buildParams(params),
    });
    return res.data;
  },

  async unreadCount() {
    const res = await api.get<ApiResponse<{ count: number }>>(`${base}/notifications/unread-count`);
    return res.data.data;
  },

  async markAsRead(id: string) {
    const res = await api.post<ApiResponse<null>>(`${base}/notifications/${id}/read`);
    return res.data;
  },

  async markAllAsRead() {
    const res = await api.post<ApiResponse<{ updated: number }>>(`${base}/notifications/read-all`);
    return res.data;
  },
};

export type {
  LibraryLoan,
  LibraryFine,
  LibraryReservation,
  LibraryAcquisition,
  LibraryInventorySession,
  LibraryNotification,
};

/* ============================ OPAC (publik) ============================ */

export const libraryOpacApi = {
  async search(
    params: LibraryListParams & {
      subject_id?: number | '';
      location_id?: number | '';
      creator_id?: number | '';
      audience_type_id?: number | '';
      genre_id?: number | '';
      availability?: 'available' | 'unavailable' | '';
      year_from?: number | '';
      year_to?: number | '';
    } = {},
  ) {
    const query = buildParams(params);
    for (const key of ['subject_id', 'location_id', 'creator_id', 'audience_type_id', 'genre_id', 'availability', 'year_from', 'year_to'] as const) {
      if (params[key]) query[key] = params[key];
    }

    const res = await api.get<PaginatedResponse<OpacRecord>>(`${base}/opac`, { params: query });
    return res.data;
  },

  async show(slug: string) {
    const res = await api.get<ApiResponse<OpacRecord>>(`${base}/opac/${slug}`);
    return res.data.data;
  },

  async availability(slug: string) {
    const res = await api.get<ApiResponse<OpacAvailability>>(`${base}/opac/${slug}/availability`);
    return res.data.data;
  },
};

export type { LibraryBibliography, LibraryItem, LibraryMember, LibraryMemberCard, OpacRecord };

export { buildParams as buildLibraryParams, stripEmpty as stripLibraryEmpty };