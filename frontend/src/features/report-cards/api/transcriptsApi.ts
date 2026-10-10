import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type { Transcript, TranscriptLookups } from '../types';

export interface TranscriptListParams {
  page?: number;
  per_page?: number;
  student_id?: number | '';
  academic_year_id?: number | '';
  search?: string;
}

export const transcriptsApi = {
  async lookups() {
    const response = await api.get<ApiResponse<TranscriptLookups>>('/transcripts/lookups');
    return response.data.data;
  },

  async list(params: TranscriptListParams = {}) {
    const query: Record<string, string | number> = {};
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
    if (params.student_id) query.student_id = params.student_id;
    if (params.academic_year_id) query.academic_year_id = params.academic_year_id;
    if (params.search) query.search = params.search;
    const response = await api.get<PaginatedResponse<Transcript>>('/transcripts', { params: query });
    return response.data;
  },

  async show(id: number) {
    const response = await api.get<ApiResponse<Transcript>>(`/transcripts/${id}`);
    return response.data.data;
  },

  async generate(payload: { student_id: number; academic_year_id?: number | null }) {
    const response = await api.post<ApiResponse<Transcript>>('/transcripts/generate', payload);
    return response.data.data;
  },

  async pdf(id: number) {
    const response = await api.get<Blob>(`/transcripts/${id}/pdf`, { responseType: 'blob' });
    return response.data;
  },
};
