import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  DraftAnswer,
  VisitDetail,
  VisitDisputeRow,
  VisitFindingRow,
  VisitListRow,
  VisitPreview,
} from '../types';

interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; per_page: number; total: number; last_page: number };
}

export interface VisitFilters {
  status?: string[];
  open?: boolean;
  mine?: 'supervisor' | 'supervisee';
  activity_kind?: string;
  supervisee_employee_id?: number;
  program_id?: number;
  from?: string;
  to?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

/** Klien API pelaksanaan kegiatan supervisi (`/api/v1/supervisi/visits`). */
export const supervisiVisitApi = {
  async list(filters: VisitFilters = {}) {
    const params: Record<string, unknown> = { per_page: 15, ...filters };

    if (filters.status && filters.status.length > 0) {
      params.status = filters.status;
    }

    const response = await api.get<ApiResponse<Paginated<VisitListRow> | VisitListRow[]>>('/supervisi/visits', { params });
    const payload = response.data.data;

    return Array.isArray(payload) ? { rows: payload, meta: undefined } : { rows: payload.data, meta: payload.meta };
  },

  async create(payload: {
    supervisee_employee_id: number;
    supervisor_employee_id?: number;
    instrument_version_id?: number;
    activity_kind?: string;
    approach?: string;
    lesson_topic?: string | null;
    observed_at?: string | null;
    duration_minutes?: number | null;
  }) {
    const response = await api.post<ApiResponse<VisitDetail>>('/supervisi/visits', payload);
    return response.data.data;
  },

  async detail(visitId: number) {
    const response = await api.get<ApiResponse<VisitDetail>>(`/supervisi/visits/${visitId}`);
    return response.data.data;
  },

  async update(visitId: number, payload: Record<string, unknown>) {
    const response = await api.patch<ApiResponse<VisitDetail>>(`/supervisi/visits/${visitId}`, payload);
    return response.data.data;
  },

  async saveAnswers(visitId: number, answers: DraftAnswer[]) {
    const response = await api.put<ApiResponse<{ preview: VisitPreview; checklist: string[] }>>(
      `/supervisi/visits/${visitId}/answers`,
      { answers },
    );
    return response.data.data;
  },

  /**
   * Sinkronisasi antrean jawaban luring (Fase 5).
   *
   * Server menjawab per baris: tersimpan, dilewati (sudah pernah dikirim),
   * ditolak (nilai tidak sah), atau konflik (server lebih baru). Pemanggil
   * memakai jawaban itu untuk membersihkan antrean di perangkat.
   */
  async syncOfflineAnswers(
    visitId: number,
    payload: { device?: string; answers: Array<Record<string, unknown>> },
  ) {
    const response = await api.post<
      ApiResponse<{
        applied: number;
        skipped: Array<{ indicator_id: number }>;
        rejected: Array<{ indicator_id: number; reason: string }>;
        conflicts: Array<{ indicator_id: number; statement?: string; reason: string }>;
        visit: { id: number; code: string; status: string; answers_count: number; is_locked: boolean };
        server_time: string;
      }>
    >(`/supervisi/visits/${visitId}/answers/sync`, payload);

    return response.data.data;
  },

  /** Formulir kosong siap cetak (mode luring). */
  async blankForm(visitId: number): Promise<Blob> {
    const response = await api.get(`/supervisi/visits/${visitId}/blank-form`, { responseType: 'blob' });

    return response.data as Blob;
  },

  async preview(visitId: number) {
    const response = await api.get<ApiResponse<{ preview: VisitPreview }>>(`/supervisi/visits/${visitId}/preview`);
    return response.data.data.preview;
  },

  async submit(visitId: number) {
    const response = await api.post<ApiResponse<VisitDetail>>(`/supervisi/visits/${visitId}/submit`);
    return response.data.data;
  },

  async finalize(visitId: number, payload: { summary?: string; strengths?: string; improvements?: string } = {}) {
    const response = await api.post<ApiResponse<VisitDetail>>(`/supervisi/visits/${visitId}/finalize`, payload);
    return response.data.data;
  },

  async reopen(visitId: number, reason: string) {
    const response = await api.post<ApiResponse<VisitDetail>>(`/supervisi/visits/${visitId}/reopen`, { reason });
    return response.data.data;
  },

  async cancel(visitId: number, reason?: string) {
    await api.post(`/supervisi/visits/${visitId}/cancel`, { reason });
  },

  async destroy(visitId: number) {
    await api.delete(`/supervisi/visits/${visitId}`);
  },

  async recordConference(
    visitId: number,
    kind: 'PRE' | 'POST',
    payload: {
      held_at?: string | null;
      location?: string | null;
      participants?: Array<{ name: string; role?: string | null }>;
      answers?: Array<{ question: string; answer?: string | null }>;
      agreements?: string[];
      reflection?: string | null;
      next_focus?: string | null;
    },
  ) {
    const response = await api.put(`/supervisi/visits/${visitId}/conferences/${kind}`, payload);
    return response.data;
  },

  async saveFindings(visitId: number, findings: VisitFindingRow[]) {
    const response = await api.put<ApiResponse<VisitFindingRow[]>>(`/supervisi/visits/${visitId}/findings`, { findings });
    return response.data.data;
  },

  async uploadEvidence(
    visitId: number,
    payload: { file?: File | null; url?: string | null; kind?: string; caption?: string; visibility?: string },
  ) {
    const form = new FormData();
    if (payload.file) form.append('file', payload.file);
    if (payload.url) form.append('url', payload.url);
    form.append('kind', payload.kind ?? 'DOCUMENT');
    form.append('caption', payload.caption ?? '');
    form.append('visibility', payload.visibility ?? 'SUPERVISEE');

    const response = await api.post(`/supervisi/visits/${visitId}/evidence`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    return response.data;
  },

  async deleteEvidence(visitId: number, evidenceId: number) {
    await api.delete(`/supervisi/visits/${visitId}/evidence/${evidenceId}`);
  },

  async acknowledge(visitId: number, note?: string) {
    const response = await api.post(`/supervisi/visits/${visitId}/acknowledge`, { note });
    return response.data;
  },

  async dispute(
    visitId: number,
    payload: { reason: string; proposed_scores?: Array<{ indicator_id: number; score: number; reason?: string }> },
  ) {
    const response = await api.post<ApiResponse<VisitDisputeRow>>(`/supervisi/visits/${visitId}/dispute`, payload);
    return response.data.data;
  },

  async resolveDispute(
    disputeId: number,
    payload: { status: string; decision_note: string; moderator_employee_id?: number | null },
  ) {
    const response = await api.post(`/supervisi/disputes/${disputeId}/resolve`, payload);
    return response.data;
  },
};
