import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  CompleteJournalPayload,
  LessonPlan,
  LessonPlanPayload,
  LessonPlanQueryParams,
  TeachingJournal,
  TeachingJournalPayload,
  TeachingJournalQueryParams,
  TeachingRecap,
  TeachingToday,
} from '@/types/teaching';

function buildParams(params: LessonPlanQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.filter) out.filter = params.filter;
  return out;
}

/**
 * Buang field opsional yang masih berupa string kosong.
 *
 * Form memakai `''` sebagai "belum diisi"; kalau dikirim apa adanya, aturan
 * `integer`/`date_format` bisa menolaknya walau field itu opsional.
 */
function stripEmpty<T extends object>(payload: T): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(payload)) {
    if (value === '') continue;
    out[key] = value;
  }
  return out;
}

/** RPP / modul ajar */
export const lessonPlansApi = {
  async list(params: LessonPlanQueryParams = {}) {
    const res = await api.get<PaginatedResponse<LessonPlan>>('/lesson-plans', {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<LessonPlan>>(`/lesson-plans/${id}`);
    return res.data.data;
  },

  async create(payload: LessonPlanPayload) {
    const res = await api.post<ApiResponse<LessonPlan>>('/lesson-plans', stripEmpty(payload));
    return res.data.data;
  },

  async update(id: number, payload: LessonPlanPayload) {
    const res = await api.put<ApiResponse<LessonPlan>>(
      `/lesson-plans/${id}`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/lesson-plans/${id}`);
  },

  async submit(id: number) {
    const res = await api.post<ApiResponse<LessonPlan>>(`/lesson-plans/${id}/submit`);
    return res.data;
  },

  async approve(id: number) {
    const res = await api.post<ApiResponse<LessonPlan>>(`/lesson-plans/${id}/approve`);
    return res.data;
  },

  async reject(id: number, rejectionReason: string) {
    const res = await api.post<ApiResponse<LessonPlan>>(`/lesson-plans/${id}/reject`, {
      rejection_reason: rejectionReason,
    });
    return res.data;
  },
};

/** Jurnal mengajar */
export const teachingJournalsApi = {
  async list(params: TeachingJournalQueryParams = {}) {
    const query = buildParams(params);
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;

    const res = await api.get<PaginatedResponse<TeachingJournal>>('/teaching-journals', {
      params: query,
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<TeachingJournal>>(`/teaching-journals/${id}`);
    return res.data.data;
  },

  async create(payload: TeachingJournalPayload) {
    const res = await api.post<ApiResponse<TeachingJournal>>(
      '/teaching-journals',
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async update(id: number, payload: TeachingJournalPayload) {
    const res = await api.put<ApiResponse<TeachingJournal>>(
      `/teaching-journals/${id}`,
      stripEmpty(payload),
    );
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/teaching-journals/${id}`);
  },

  /** Menandai jam mengajar benar-benar terlaksana + mencatat realisasi JP. */
  async complete(id: number, payload: CompleteJournalPayload) {
    const res = await api.post<ApiResponse<TeachingJournal>>(
      `/teaching-journals/${id}/complete`,
      stripEmpty(payload),
    );
    return res.data;
  },

  /** Jadwal mengajar hari ini beserta penanda mana yang belum dijurnal. */
  async today(params: { date?: string; semester_id?: number | ''; teacher_id?: number | '' } = {}) {
    const query: Record<string, unknown> = {};
    if (params.date) query.date = params.date;
    if (params.semester_id) query.semester_id = params.semester_id;
    if (params.teacher_id) query.teacher_id = params.teacher_id;

    const res = await api.get<ApiResponse<TeachingToday>>('/teaching-journals/today', {
      params: query,
    });
    return res.data.data;
  },

  /** Rekap JP terealisasi vs JP terjadwal per guru. */
  async recap(params: { semester_id: number | ''; teacher_id?: number | '' }) {
    const query: Record<string, unknown> = { semester_id: params.semester_id };
    if (params.teacher_id) query.teacher_id = params.teacher_id;

    const res = await api.get<ApiResponse<TeachingRecap>>('/teaching-journals/recap', {
      params: query,
    });
    return res.data.data;
  },
};
