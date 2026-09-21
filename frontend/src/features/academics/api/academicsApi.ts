import { api } from '@/lib/axios';
import type {
  AcademicYear,
  ActivePeriod,
  ApiResponse,
  ClassStudentItem,
  MasterQueryParams,
  PaginatedResponse,
  SchoolClass,
  SchoolClassPayload,
  Semester,
} from '@/types';

function buildParams(params: MasterQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.filter) out.filter = params.filter;
  return out;
}

export const academicsApi = {
  /** Periode akademik aktif */
  async activePeriod() {
    const res = await api.get<ApiResponse<ActivePeriod>>('/academic/active');
    return res.data.data;
  },

  /** Aktifkan tahun ajaran */
  async activateYear(id: number) {
    const res = await api.post<ApiResponse<AcademicYear>>(
      `/academic-years/${id}/activate`,
    );
    return res.data.data;
  },

  /** Aktifkan semester */
  async activateSemester(id: number) {
    const res = await api.post<ApiResponse<Semester>>(
      `/semesters/${id}/activate`,
    );
    return res.data.data;
  },
};

export const classesApi = {
  async list(params: MasterQueryParams = {}) {
    const res = await api.get<PaginatedResponse<SchoolClass>>('/classes', {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<SchoolClass>>(`/classes/${id}`);
    return res.data.data;
  },

  async create(payload: SchoolClassPayload) {
    const res = await api.post<ApiResponse<SchoolClass>>('/classes', payload);
    return res.data.data;
  },

  async update(id: number, payload: SchoolClassPayload) {
    const res = await api.put<ApiResponse<SchoolClass>>(`/classes/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/classes/${id}`);
  },

  async students(id: number) {
    const res = await api.get<ApiResponse<ClassStudentItem[]>>(
      `/classes/${id}/students`,
    );
    return res.data.data;
  },

  async addStudents(id: number, studentIds: number[]) {
    await api.post(`/classes/${id}/students`, { student_ids: studentIds });
  },

  async removeStudent(id: number, studentId: number) {
    await api.delete(`/classes/${id}/students/${studentId}`);
  },
};
