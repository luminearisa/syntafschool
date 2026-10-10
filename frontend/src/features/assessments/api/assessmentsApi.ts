import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type { AssessmentLookups, AssessmentPayload, AssessmentRow, AssessmentSheet, BulkScoreEntry } from '../types';

export interface AssessmentListParams {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
}

export const assessmentsApi = {
  async lookups() {
    const response = await api.get<ApiResponse<AssessmentLookups>>('/assessment-lookups');
    return response.data.data;
  },

  async list(params: AssessmentListParams = {}) {
    const query: Record<string, unknown> = {};
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
    if (params.search) query.search = params.search;
    if (params.status) query.filter = { status: params.status };
    const response = await api.get<PaginatedResponse<AssessmentRow>>('/assessments', { params: query });
    return response.data;
  },

  async create(payload: AssessmentPayload) {
    const response = await api.post<ApiResponse<AssessmentRow>>('/assessments', payload);
    return response.data.data;
  },

  async update(id: number, payload: AssessmentPayload) {
    const response = await api.put<ApiResponse<AssessmentRow>>(`/assessments/${id}`, payload);
    return response.data.data;
  },

  async remove(id: number) {
    await api.delete(`/assessments/${id}`);
  },

  async sheet(id: number) {
    const response = await api.get<ApiResponse<AssessmentSheet>>(`/assessments/${id}/sheet`);
    return response.data.data;
  },

  async saveScores(id: number, entries: BulkScoreEntry[], reason?: string) {
    const response = await api.post<ApiResponse<{ created: number; updated: number; total: number }>>(
      `/assessments/${id}/scores/bulk`,
      { entries, reason: reason || undefined },
    );
    return response.data.data;
  },

  async submit(id: number, payload: { force?: boolean; reason?: string } = {}) {
    const response = await api.post<ApiResponse<AssessmentRow>>(`/assessments/${id}/submit`, payload);
    return response.data.data;
  },

  async verify(id: number) {
    const response = await api.post<ApiResponse<AssessmentRow>>(`/assessments/${id}/verify`);
    return response.data.data;
  },

  async finalize(id: number, payload: { force?: boolean; reason?: string } = {}) {
    const response = await api.post<ApiResponse<AssessmentRow>>(`/assessments/${id}/finalize`, payload);
    return response.data.data;
  },

  async reopen(id: number, reason: string) {
    const response = await api.post<ApiResponse<AssessmentRow>>(`/assessments/${id}/reopen`, { reason });
    return response.data.data;
  },

  /** Unduh rekap nilai (buku nilai) satu mapel-kelas-semester sebagai CSV. */
  async gradebookExport(params: { class_id: number; subject_id: number; semester_id?: number }) {
    const response = await api.get<Blob>('/gradebook/export', { params, responseType: 'blob' });
    return response.data;
  },
};
