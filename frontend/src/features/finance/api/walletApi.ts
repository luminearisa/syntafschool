import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type { WalletAccount, WalletLedgerEntry, WalletTopUp } from '../types/wallet';

export interface WalletListParams {
  page?: number;
  per_page?: number;
  search?: string;
  owner_type?: string;
  status?: string;
}

export const walletApi = {
  async studentOptions(search = '') {
    const response = await api.get<ApiResponse<{ id: number; nis: string; full_name: string }[]>>('/wallet/students', {
      params: search ? { search } : {},
    });
    return response.data.data;
  },

  async createWallet(payload: { student_id: number; notes?: string }) {
    const response = await api.post<ApiResponse<WalletAccount>>('/wallet/wallets', payload);
    return response.data.data;
  },

  async wallets(params: WalletListParams = {}) {
    const response = await api.get<PaginatedResponse<WalletAccount>>('/wallet/wallets', { params });
    return response.data;
  },

  async topups(params: { page?: number; per_page?: number; pending_verification?: boolean } = {}) {
    const response = await api.get<PaginatedResponse<WalletTopUp>>('/wallet/topups', {
      params: {
        page: params.page,
        per_page: params.per_page,
        ...(params.pending_verification ? { pending_verification: 1 } : {}),
      },
    });
    return response.data;
  },

  async verifyTopUp(id: number) {
    const response = await api.post<ApiResponse<WalletTopUp>>(`/wallet/topups/${id}/verify`);
    return response.data.data;
  },

  async rejectTopUp(id: number, reason: string) {
    const response = await api.post<ApiResponse<WalletTopUp>>(`/wallet/topups/${id}/reject`, { reason });
    return response.data.data;
  },

  async ledger(walletId: number, params: { page?: number; per_page?: number; date_from?: string; date_to?: string } = {}) {
    const response = await api.get<PaginatedResponse<WalletLedgerEntry>>(`/wallet/wallets/${walletId}/ledger`, { params });
    return response.data;
  },
};
