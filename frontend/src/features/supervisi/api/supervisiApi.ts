import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  BuilderMeta,
  CoverageResponse,
  DraftSection,
  Instrument,
  InstrumentVersion,
  Supervisor,
  SupervisorCandidate,
  SupervisorKindOption,
  VersionDetail,
} from '../types';

interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; per_page: number; total: number; last_page: number };
}

/** Klien API modul Supervisi (`/api/v1/supervisi/*`). */
export const supervisiApi = {
  async meta() {
    const response = await api.get<ApiResponse<BuilderMeta>>('/supervisi/meta/instruments');
    return response.data.data;
  },

  // ---------------- Supervisor (pengaturan siapa boleh menilai) ----------------
  async supervisorKinds() {
    const response = await api.get<ApiResponse<SupervisorKindOption[]>>('/supervisi/supervisors/kinds');
    return response.data.data;
  },

  async supervisors(params: { kind?: string; active?: boolean; search?: string } = {}) {
    const response = await api.get<ApiResponse<Paginated<Supervisor>>>('/supervisi/supervisors', {
      params: { per_page: 100, ...params },
    });
    const payload = response.data.data;
    return Array.isArray(payload) ? payload : payload.data;
  },

  async candidates(search?: string) {
    const response = await api.get<ApiResponse<SupervisorCandidate[]>>('/supervisi/supervisors/candidates', {
      params: search ? { search } : undefined,
    });
    return response.data.data;
  },

  async coverage() {
    const response = await api.get<ApiResponse<CoverageResponse>>('/supervisi/supervisors/coverage');
    return response.data.data;
  },

  async eligible(superviseeEmployeeId: number, category?: string) {
    const response = await api.get<ApiResponse<Array<Supervisor & { eligible: boolean; reason?: string | null }>>>(
      '/supervisi/supervisors/eligible',
      { params: { supervisee_employee_id: superviseeEmployeeId, category } },
    );
    return response.data.data;
  },

  async createSupervisor(payload: Partial<Supervisor> & { employee_id: number; kind: string }) {
    const response = await api.post<ApiResponse<Supervisor>>('/supervisi/supervisors', payload);
    return response.data.data;
  },

  async updateSupervisor(id: number, payload: Partial<Supervisor>) {
    const response = await api.patch<ApiResponse<Supervisor>>(`/supervisi/supervisors/${id}`, payload);
    return response.data.data;
  },

  async deactivateSupervisor(id: number, reason?: string) {
    await api.delete(`/supervisi/supervisors/${id}`, { data: { reason } });
  },

  // ---------------- Instrumen ----------------
  async instruments(params: { category?: string; target_type?: string; search?: string } = {}) {
    const response = await api.get<ApiResponse<Paginated<Instrument>>>('/supervisi/instruments', {
      params: { per_page: 100, ...params },
    });
    const payload = response.data.data;
    return Array.isArray(payload) ? payload : payload.data;
  },

  async createInstrument(payload: {
    name: string;
    category: string;
    target_type: string;
    description?: string | null;
    scoring_mode?: string;
  }) {
    const response = await api.post<ApiResponse<{ instrument: Instrument; version: InstrumentVersion }>>(
      '/supervisi/instruments',
      payload,
    );
    return response.data.data;
  },

  async version(versionId: number) {
    const response = await api.get<ApiResponse<VersionDetail>>(`/supervisi/instrument-versions/${versionId}`);
    return response.data.data;
  },

  async saveStructure(versionId: number, sections: DraftSection[]) {
    const response = await api.put<ApiResponse<VersionDetail>>(
      `/supervisi/instrument-versions/${versionId}/structure`,
      { sections },
    );
    return response.data.data;
  },

  async publishVersion(versionId: number) {
    const response = await api.post<ApiResponse<InstrumentVersion>>(
      `/supervisi/instrument-versions/${versionId}/publish`,
    );
    return response.data.data;
  },

  async cloneVersion(versionId: number, changeNote?: string) {
    const response = await api.post<ApiResponse<InstrumentVersion>>(
      `/supervisi/instrument-versions/${versionId}/clone`,
      { change_note: changeNote },
    );
    return response.data.data;
  },

  async deactivateInstrument(id: number, force = false) {
    await api.delete(`/supervisi/instruments/${id}`, { params: force ? { force: true } : undefined });
  },
};
