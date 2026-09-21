import { api } from '@/lib/axios';
import type {
  ApiResponse,
  PaginatedResponse,
  Student,
  StudentAccountPayload,
  StudentHistoryEntry,
  StudentHistoryPayload,
  StudentGuardianInput,
  StudentPayload,
  StudentQueryParams,
  User,
} from '@/types';

function buildParams(params: StudentQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.unassigned) out.unassigned = 1;
  if (params.class_id) out.class_id = params.class_id;
  if (params.academic_year_id) out.academic_year_id = params.academic_year_id;

  const filter: Record<string, string | number> = {};
  if (params.status_id) filter.status_id = params.status_id;
  if (params.gender_id) filter.gender_id = params.gender_id;
  if (typeof params.is_active === 'boolean') {
    filter.is_active = params.is_active ? 1 : 0;
  }
  if (Object.keys(filter).length > 0) out.filter = filter;

  return out;
}

export const studentsApi = {
  async list(params: StudentQueryParams = {}) {
    const res = await api.get<PaginatedResponse<Student>>('/students', {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<Student>>(`/students/${id}`);
    return res.data.data;
  },

  async create(payload: StudentPayload) {
    const res = await api.post<ApiResponse<Student>>('/students', payload);
    return res.data.data;
  },

  async update(id: number, payload: StudentPayload) {
    const res = await api.put<ApiResponse<Student>>(`/students/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/students/${id}`);
  },

  async toggleActive(id: number) {
    const res = await api.patch<ApiResponse<Student>>(`/students/${id}/toggle-active`);
    return res.data.data;
  },

  async createAccount(id: number, payload: StudentAccountPayload) {
    const res = await api.post<ApiResponse<User>>(`/students/${id}/account`, payload);
    return res.data.data;
  },

  /** Tambahkan wali: pakai wali yang ada (guardian_id) atau buat baru (full_name). */
  async attachGuardian(id: number, payload: StudentGuardianInput) {
    const res = await api.post<ApiResponse<Student>>(`/students/${id}/guardians`, payload);
    return res.data.data;
  },

  async updateGuardian(
    id: number,
    guardianId: number,
    payload: { guardian_relation_id?: number | ''; is_primary: boolean },
  ) {
    const res = await api.patch<ApiResponse<Student>>(
      `/students/${id}/guardians/${guardianId}`,
      payload,
    );
    return res.data.data;
  },

  async detachGuardian(id: number, guardianId: number) {
    const res = await api.delete<ApiResponse<Student>>(
      `/students/${id}/guardians/${guardianId}`,
    );
    return res.data.data;
  },

  async histories(id: number) {
    const res = await api.get<ApiResponse<StudentHistoryEntry[]>>(
      `/students/${id}/histories`,
    );
    return res.data.data;
  },

  async storeHistory(id: number, payload: StudentHistoryPayload) {
    const res = await api.post<ApiResponse<StudentHistoryEntry>>(
      `/students/${id}/histories`,
      payload,
    );
    return res.data.data;
  },

  async removeHistory(id: number, historyId: number) {
    await api.delete(`/students/${id}/histories/${historyId}`);
  },
};
