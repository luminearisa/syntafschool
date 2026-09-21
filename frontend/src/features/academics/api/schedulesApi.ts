import { api } from '@/lib/axios';
import type {
  ApiResponse,
  ConflictCheckResult,
  PaginatedResponse,
  Schedule,
  ScheduleChange,
  ScheduleChangePayload,
  ScheduleGrid,
  ScheduleGridParams,
  SchedulePayload,
  TimeSlot,
  TimeSlotGenerateResult,
  TimeSlotPayload,
} from '@/types';

/** Bangun params grid tanpa menyertakan filter kosong. */
function gridParams(params: ScheduleGridParams) {
  const out: Record<string, unknown> = { semester_id: params.semester_id };
  if (params.school_class_id) out.school_class_id = params.school_class_id;
  if (params.employee_id) out.employee_id = params.employee_id;
  if (params.room_id) out.room_id = params.room_id;
  if (params.date) out.date = params.date;
  return out;
}

/** Jadwal pelajaran — jadwal pokok, grid mingguan, dan perubahan harian */
export const schedulesApi = {
  async list(params: {
    page?: number;
    per_page?: number;
    search?: string;
    all?: boolean;
    filter?: Record<string, string | number | boolean>;
  } = {}) {
    const query: Record<string, unknown> = {};
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
    if (params.search) query.search = params.search;
    if (params.all) query.all = 1;
    if (params.filter) query.filter = params.filter;

    const res = await api.get<PaginatedResponse<Schedule>>('/schedules', {
      params: query,
    });
    return res.data;
  },

  async create(payload: SchedulePayload) {
    const res = await api.post<ApiResponse<Schedule>>('/schedules', payload);
    return res.data.data;
  },

  async update(id: number, payload: SchedulePayload) {
    const res = await api.put<ApiResponse<Schedule>>(`/schedules/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/schedules/${id}`);
  },

  /** Cek bentrok tanpa menyimpan — dipakai untuk peringatan dini di form. */
  async checkConflict(payload: {
    school_class_id: number | '';
    day_id: number | '';
    semester_id: number | '';
    start_time: string;
    end_time: string;
    employee_id?: number | '';
    room_id?: number | '';
    ignore_id?: number;
  }) {
    const res = await api.post<ApiResponse<ConflictCheckResult>>(
      '/schedules/check-conflict',
      payload,
    );
    return res.data.data;
  },

  async grid(params: ScheduleGridParams) {
    const res = await api.get<ApiResponse<ScheduleGrid>>('/schedules/grid', {
      params: gridParams(params),
    });
    return res.data.data;
  },

  /* ---------------- Perubahan jadwal (guru pengganti / pindah / batal) ---------------- */

  async changes(params: {
    date?: string;
    from?: string;
    to?: string;
    employee_id?: number | '';
    type?: string;
    schedule_id?: number;
  } = {}) {
    const query: Record<string, unknown> = {};
    if (params.date) query.date = params.date;
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;
    if (params.employee_id) query.employee_id = params.employee_id;

    const filter: Record<string, string | number> = {};
    if (params.type) filter.type = params.type;
    if (params.schedule_id) filter.schedule_id = params.schedule_id;
    if (Object.keys(filter).length) query.filter = filter;

    const res = await api.get<ApiResponse<ScheduleChange[]>>('/schedule-changes', {
      params: query,
    });
    return res.data.data;
  },

  async createChange(scheduleId: number, payload: ScheduleChangePayload) {
    const res = await api.post<ApiResponse<ScheduleChange>>(
      `/schedules/${scheduleId}/changes`,
      payload,
    );
    return res.data.data;
  },

  async removeChange(id: number) {
    await api.delete(`/schedule-changes/${id}`);
  },
};

/** Jam pelajaran — baris grid jadwal */
export const timeSlotsApi = {
  async list(params: { all?: boolean; day_id?: number | '' } = {}) {
    const query: Record<string, unknown> = {};
    if (params.all) query.all = 1;
    if (params.day_id) query.filter = { day_id: params.day_id };

    const res = await api.get<PaginatedResponse<TimeSlot>>('/time-slots', {
      params: query,
    });
    return res.data.data;
  },

  async create(payload: TimeSlotPayload) {
    const res = await api.post<ApiResponse<TimeSlot>>('/time-slots', payload);
    return res.data.data;
  },

  async update(id: number, payload: TimeSlotPayload) {
    const res = await api.put<ApiResponse<TimeSlot>>(`/time-slots/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/time-slots/${id}`);
  },

  /** Isi jam pelajaran dari template default untuk hari-hari terpilih. */
  async generate(dayIds: number[], overwrite = false) {
    const res = await api.post<ApiResponse<TimeSlotGenerateResult>>(
      '/time-slots/generate',
      { day_ids: dayIds, overwrite },
    );
    return res.data;
  },
};
