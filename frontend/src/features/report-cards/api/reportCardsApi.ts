import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type { ReportCard, ReportCardLookups } from '../types';

export interface ReportCardListParams {
  page?: number;
  per_page?: number;
  class_id?: number | '';
  semester_id?: number | '';
  type?: string;
  status?: string;
  search?: string;
}

export const reportCardsApi = {
  async lookups() {
    const response = await api.get<ApiResponse<ReportCardLookups>>('/report-cards/lookups');
    return response.data.data;
  },

  async list(params: ReportCardListParams = {}) {
    const query: Record<string, string | number> = {};
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
    if (params.class_id) query.class_id = params.class_id;
    if (params.semester_id) query.semester_id = params.semester_id;
    if (params.type) query.type = params.type;
    if (params.status) query.status = params.status;
    if (params.search) query.search = params.search;
    const response = await api.get<PaginatedResponse<ReportCard>>('/report-cards', { params: query });
    return response.data;
  },

  async show(id: number) {
    const response = await api.get<ApiResponse<ReportCard>>(`/report-cards/${id}`);
    return response.data.data;
  },

  async generate(payload: { class_id: number; semester_id: number; type: string }) {
    const response = await api.post<ApiResponse<{ generated: number; reports: ReportCard[] }>>('/report-cards/generate', payload);
    return response.data.data;
  },

  async update(id: number, payload: { notes: string; homeroom_note: string }) {
    const response = await api.put<ApiResponse<ReportCard>>(`/report-cards/${id}`, payload);
    return response.data.data;
  },

  async ready(id: number) {
    const response = await api.post<ApiResponse<ReportCard>>(`/report-cards/${id}/ready`);
    return response.data.data;
  },

  async approve(id: number) {
    const response = await api.post<ApiResponse<ReportCard>>(`/report-cards/${id}/approve`);
    return response.data.data;
  },

  async publish(id: number) {
    const response = await api.post<ApiResponse<ReportCard>>(`/report-cards/${id}/publish`);
    return response.data.data;
  },

  async reopen(id: number) {
    const response = await api.post<ApiResponse<ReportCard>>(`/report-cards/${id}/reopen`);
    return response.data.data;
  },

  async pdf(id: number) {
    const response = await api.get<Blob>(`/report-cards/${id}/pdf`, { responseType: 'blob' });
    return response.data;
  },
};
