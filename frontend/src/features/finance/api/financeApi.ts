import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  FinanceBankAccountOption,
  FinanceBilling,
  FinanceBillingItem,
  FinanceCashAccountOption,
  FinanceInvoice,
  FinancePayment,
} from '../types';

export interface FinanceListParams {
  page?: number;
  per_page?: number;
  all?: boolean;
  search?: string;
  status?: string;
  date_from?: string;
  date_to?: string;
}

function paramsOf(params: FinanceListParams = {}) {
  const out: Record<string, string | number> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.all) out.all = 1;
  if (params.search) out.search = params.search;
  if (params.status) out.status = params.status;
  if (params.date_from) out.date_from = params.date_from;
  if (params.date_to) out.date_to = params.date_to;
  return out;
}

export interface CreateBillingPayload {
  customer_type: 'student';
  customer_id: number;
  student_id: number;
  due_date?: string;
  notes?: string;
  items: FinanceBillingItem[];
}

export interface CreatePaymentPayload {
  invoice_id: number;
  amount: number;
  method: 'cash' | 'bank_transfer' | 'manual_transfer';
  cash_account_id?: number;
  bank_account_id?: number;
  reference_number?: string;
  notes?: string;
  paid_at?: string;
}

export const financeApi = {
  async studentOptions(search = '') {
    const response = await api.get<ApiResponse<{ id: number; nis: string; full_name: string }[]>>('/finance/billing-students', {
      params: search ? { search } : {},
    });
    return response.data.data;
  },

  async billings(params: FinanceListParams = {}) {
    const response = await api.get<PaginatedResponse<FinanceBilling>>('/finance/billings', { params: paramsOf(params) });
    return response.data;
  },

  async billing(id: number) {
    const response = await api.get<ApiResponse<FinanceBilling>>(`/finance/billings/${id}`);
    return response.data.data;
  },

  async createBilling(payload: CreateBillingPayload) {
    const response = await api.post<ApiResponse<FinanceBilling>>('/finance/billings', payload);
    return response.data.data;
  },

  async issueInvoice(billingId: number) {
    const response = await api.post<ApiResponse<FinanceInvoice>>(`/finance/billings/${billingId}/invoice`);
    return response.data.data;
  },

  async invoices(params: FinanceListParams = {}) {
    const response = await api.get<PaginatedResponse<FinanceInvoice>>('/finance/invoices', { params: paramsOf(params) });
    return response.data;
  },

  async invoice(id: number) {
    const response = await api.get<ApiResponse<FinanceInvoice>>(`/finance/invoices/${id}`);
    return response.data.data;
  },

  async payments(params: FinanceListParams = {}) {
    const response = await api.get<PaginatedResponse<FinancePayment>>('/finance/payments', { params: paramsOf(params) });
    return response.data;
  },

  async createPayment(payload: CreatePaymentPayload) {
    const response = await api.post<ApiResponse<FinancePayment>>('/finance/payments', payload);
    return response.data.data;
  },

  async verifyPayment(id: number) {
    const response = await api.post<ApiResponse<FinancePayment>>(`/finance/payments/${id}/verify`);
    return response.data.data;
  },

  async rejectPayment(id: number, reason: string) {
    const response = await api.post<ApiResponse<FinancePayment>>(`/finance/payments/${id}/reject`, { reason });
    return response.data.data;
  },

  async cashAccounts() {
    const response = await api.get<ApiResponse<FinanceCashAccountOption[]>>('/finance/cash-accounts', {
      params: { all: 1, is_active: 1 },
    });
    return response.data.data;
  },

  async bankAccounts() {
    const response = await api.get<ApiResponse<FinanceBankAccountOption[]>>('/finance/bank-accounts', {
      params: { all: 1, is_active: 1 },
    });
    return response.data.data;
  },
};
