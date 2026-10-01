import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  AccountOption,
  Accrual,
  BusinessUnit,
  DepreciationPreview,
  FixedAsset,
  Fund,
  FundStatement,
  ModuleOption,
  OpeningBalance,
  OpeningSummary,
  ProfitLossByUnit,
  ReopenRequest,
  TrialBalance,
  VoidRequest,
} from '../types/standard';

/**
 * Klien API akuntansi standar.
 *
 * Setiap fungsi hanya menerjemahkan bentuk permintaan; aturan akuntansinya
 * ditegakkan di server (Money/JournalValidator/DanaPolicy), bukan di sini —
 * supaya web dan API tidak pernah punya dua tafsir atas aturan yang sama.
 */
export const financeStandardApi = {
  // ------------------------------------------------------------ unit bisnis
  async units() {
    const response = await api.get<ApiResponse<{
      units: BusinessUnit[];
      defaults: Record<string, string>;
      modules: ModuleOption[];
    }>>('/finance/business-units');

    return response.data.data;
  },

  async createUnit(payload: Partial<BusinessUnit>) {
    const response = await api.post<ApiResponse<BusinessUnit>>('/finance/business-units', payload);
    return response.data.data;
  },

  async updateUnit(code: string, payload: Partial<BusinessUnit>) {
    const response = await api.patch<ApiResponse<BusinessUnit>>(`/finance/business-units/${code}`, payload);
    return response.data.data;
  },

  async toggleUnit(code: string, isActive: boolean) {
    const response = await api.post<ApiResponse<BusinessUnit>>(`/finance/business-units/${code}/toggle`, { is_active: isActive });
    return response.data.data;
  },

  async setUnitDefault(sumberModul: string, businessUnitCode: string | null) {
    const response = await api.post<ApiResponse<Record<string, string>>>('/finance/business-units/defaults', {
      sumber_modul: sumberModul,
      business_unit_code: businessUnitCode,
    });

    return response.data.data;
  },

  // ------------------------------------------------------------------ dana
  async funds(activeOnly = false) {
    const response = await api.get<ApiResponse<{ funds: Fund[]; types: string[] }>>('/finance/funds', {
      params: activeOnly ? { active_only: 1 } : {},
    });

    return response.data.data;
  },

  async createFund(payload: Partial<Fund>) {
    const response = await api.post<ApiResponse<Fund>>('/finance/funds', payload);
    return response.data.data;
  },

  async updateFund(id: number, payload: Partial<Fund>) {
    const response = await api.patch<ApiResponse<Fund>>(`/finance/funds/${id}`, payload);
    return response.data.data;
  },

  async syncFundAccounts(id: number, accountIds: number[], contraAccountIds?: number[]) {
    const response = await api.post<ApiResponse<Fund>>(`/finance/funds/${id}/accounts`, {
      account_ids: accountIds,
      contra_account_ids: contraAccountIds,
    });

    return response.data.data;
  },

  async deleteFund(id: number) {
    await api.delete(`/finance/funds/${id}`);
  },

  // ------------------------------------------------------------- saldo awal
  async openingBalances() {
    const response = await api.get<ApiResponse<{ balances: OpeningBalance[]; summary: OpeningSummary }>>('/finance/opening-balances');
    return response.data.data;
  },

  async saveOpeningBalances(entries: { account_id: number; jenis_saldo: 'debet' | 'kredit'; saldo: number | string; keterangan?: string }[]) {
    const response = await api.post<ApiResponse<{ balances: OpeningBalance[]; summary: OpeningSummary }>>('/finance/opening-balances', { entries });
    return response.data.data;
  },

  async deleteOpeningBalance(id: number) {
    const response = await api.delete<ApiResponse<OpeningSummary>>(`/finance/opening-balances/${id}`);
    return response.data.data;
  },

  async postOpeningBalances(tanggal?: string) {
    const response = await api.post<ApiResponse<{ transaction: { id: number; transaction_number: string }; summary: OpeningSummary }>>('/finance/opening-balances/post', tanggal ? { tanggal } : {});
    return response.data.data;
  },

  // ------------------------------------------------------- permohonan periode
  async reopenRequests(page = 1) {
    const response = await api.get<ApiResponse<{ data: ReopenRequest[] }>>('/finance/period-reopen-requests', { params: { page } });
    return response.data.data.data ?? [];
  },

  async requestReopen(periodId: number, reason: string) {
    const response = await api.post<ApiResponse<ReopenRequest>>('/finance/period-reopen-requests', { period_id: periodId, reason });
    return response.data.data;
  },

  async decideReopen(id: number, approved: boolean, note?: string) {
    const response = await api.post<ApiResponse<ReopenRequest>>(`/finance/period-reopen-requests/${id}/decide`, { approved, note });
    return response.data.data;
  },

  async periods() {
    const response = await api.get<ApiResponse<{ data: { id: number; code: string; name: string; status: string }[] }>>('/finance/periods');
    return response.data.data.data ?? [];
  },

  // ----------------------------------------------------------- permohonan void
  async voidRequests(page = 1) {
    const response = await api.get<ApiResponse<{ data: VoidRequest[] }>>('/finance/journal-void-requests', { params: { page } });
    return response.data.data.data ?? [];
  },

  async requestVoid(transactionId: number, reason: string) {
    const response = await api.post<ApiResponse<VoidRequest>>('/finance/journal-void-requests', { transaction_id: transactionId, reason });
    return response.data.data;
  },

  async decideVoid(id: number, approved: boolean, note?: string) {
    const response = await api.post<ApiResponse<VoidRequest>>(`/finance/journal-void-requests/${id}/decide`, { approved, note });
    return response.data.data;
  },

  async journals(search = '') {
    const response = await api.get<ApiResponse<{ data: { id: number; transaction_number: string; description?: string; transaction_date?: string }[] }>>('/finance/journals', {
      params: { search, per_page: 25 },
    });

    return response.data.data.data ?? [];
  },

  // ---------------------------------------------------------------- laporan
  async trialBalance(from?: string, to?: string) {
    const response = await api.get<ApiResponse<TrialBalance>>('/finance/reports/trial-balance', { params: { from, to } });
    return response.data.data;
  },

  async profitLossByUnit(from?: string, to?: string) {
    const response = await api.get<ApiResponse<ProfitLossByUnit>>('/finance/reports/profit-loss-by-unit', { params: { from, to } });
    return response.data.data;
  },

  async fundStatement(from?: string, to?: string) {
    const response = await api.get<ApiResponse<FundStatement>>('/finance/reports/fund-statement', { params: { from, to } });
    return response.data.data;
  },

  // -------------------------------------------------------------- aset tetap
  async assets(params: { status?: string; kode_unit?: string; search?: string } = {}) {
    const response = await api.get<ApiResponse<FixedAsset[]>>('/finance/assets', { params });
    return response.data.data;
  },

  async createAsset(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<FixedAsset>>('/finance/assets', payload);
    return response.data.data;
  },

  async updateAsset(id: number, payload: Record<string, unknown>) {
    const response = await api.patch<ApiResponse<FixedAsset>>(`/finance/assets/${id}`, payload);
    return response.data.data;
  },

  async disposeAsset(id: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<{ transaction: { transaction_number: string } }>>(`/finance/assets/${id}/dispose`, payload);
    return response.data.data;
  },

  async depreciationPreview(periode: string) {
    const response = await api.get<ApiResponse<DepreciationPreview>>('/finance/depreciations/preview', { params: { periode } });
    return response.data.data;
  },

  async runDepreciation(periode: string) {
    const response = await api.post<ApiResponse<{ periode: string; diposting: number; dilewati: number; total: string }>>('/finance/depreciations/run', { periode });
    return response.data.data;
  },

  // ------------------------------------------------------------------ akrual
  async accruals(params: { status?: string; jenis?: string } = {}) {
    const response = await api.get<ApiResponse<{ accruals: Accrual[]; due_for_reversal: number }>>('/finance/accruals', { params });
    return response.data.data;
  },

  async createAccrual(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<Accrual>>('/finance/accruals', payload);
    return response.data.data;
  },

  async postAccrual(id: number) {
    const response = await api.post<ApiResponse<{ accrual: Accrual }>>(`/finance/accruals/${id}/post`, {});
    return response.data.data;
  },

  async reverseDueAccruals(tanggal?: string) {
    const response = await api.post<ApiResponse<{ dibalik: number; total: string }>>('/finance/accruals/reverse-due', tanggal ? { tanggal } : {});
    return response.data.data;
  },

  // -------------------------------------------------------------- persediaan
  async inventoryMasuk(itemId: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<{ transaction: { transaction_number: string }; nilai: string }>>(`/finance/inventory/items/${itemId}/masuk`, payload);
    return response.data.data;
  },

  async inventoryKeluar(itemId: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<{ transaction: { transaction_number: string }; nilai: string }>>(`/finance/inventory/items/${itemId}/keluar`, payload);
    return response.data.data;
  },

  // ------------------------------------------------------------------ akun COA
  async accounts() {
    const response = await api.get<ApiResponse<AccountOption[]>>('/finance/accounts', { params: { all: 1, is_active: 1 } });
    return response.data.data;
  },
};
