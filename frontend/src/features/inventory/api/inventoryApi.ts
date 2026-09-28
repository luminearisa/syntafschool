import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type { InventoryItem, InventoryItemPayload, InventoryLookups } from '../types';

export interface InventoryListParams {
  page?: number;
  per_page?: number;
  search?: string;
  category_id?: number | '';
  location_id?: number | '';
  low_stock?: boolean;
  is_active?: boolean;
}

export const inventoryApi = {
  async lookups() {
    const response = await api.get<ApiResponse<InventoryLookups>>('/inventory/lookups');
    return response.data.data;
  },

  async list(params: InventoryListParams = {}) {
    const query: Record<string, string | number> = {};
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
    if (params.search) query.search = params.search;
    if (params.category_id) query.category_id = params.category_id;
    if (params.location_id) query.location_id = params.location_id;
    if (params.low_stock) query.low_stock = 1;
    if (typeof params.is_active === 'boolean') query.is_active = params.is_active ? 1 : 0;
    const response = await api.get<PaginatedResponse<InventoryItem>>('/inventory/items', { params: query });
    return response.data;
  },

  async show(id: number) {
    const response = await api.get<ApiResponse<InventoryItem>>(`/inventory/items/${id}`);
    return response.data.data;
  },

  async create(payload: InventoryItemPayload) {
    const response = await api.post<ApiResponse<InventoryItem>>('/inventory/items', payload);
    return response.data.data;
  },

  async update(id: number, payload: InventoryItemPayload) {
    const { quantity: _quantity, ...metadata } = payload;
    const response = await api.put<ApiResponse<InventoryItem>>(`/inventory/items/${id}`, metadata);
    return response.data.data;
  },

  async adjust(id: number, payload: { direction: 'in' | 'out'; quantity: number; reason: string; reference?: string }) {
    const response = await api.post<ApiResponse<InventoryItem>>(`/inventory/items/${id}/adjust`, payload);
    return response.data.data;
  },

  async archive(id: number) {
    await api.delete(`/inventory/items/${id}`);
  },
};
