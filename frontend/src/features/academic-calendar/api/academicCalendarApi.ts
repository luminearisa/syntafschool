import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  AcademicCalendarResult,
  AcademicEvent,
  AcademicEventPayload,
  EffectiveDay,
  EffectiveDayPayload,
  EffectiveDaysGenerateResult,
  EffectiveDaysSummary,
  EventType,
  EventTypePayload,
} from '@/types/academicCalendar';

/** Jenis event kalender akademik */
export const eventTypesApi = {
  async list(params: { all?: boolean; search?: string } = {}) {
    const query: Record<string, unknown> = {};
    if (params.all) query.all = 1;
    if (params.search) query.search = params.search;

    const res = await api.get<PaginatedResponse<EventType>>('/event-types', { params: query });
    return res.data;
  },

  async create(payload: EventTypePayload) {
    const res = await api.post<ApiResponse<EventType>>('/event-types', payload);
    return res.data.data;
  },

  async update(id: number, payload: EventTypePayload) {
    const res = await api.put<ApiResponse<EventType>>(`/event-types/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/event-types/${id}`);
  },
};

export interface AcademicEventQuery {
  semester_id?: number | '';
  event_type_id?: number | '';
  is_holiday?: boolean;
  from?: string;
  to?: string;
  search?: string;
  all?: boolean;
  per_page?: number;
}

/** Event akademik + tampilan kalender per bulan */
export const academicEventsApi = {
  async list(params: AcademicEventQuery = {}) {
    const query: Record<string, unknown> = {};
    if (params.semester_id) query.semester_id = params.semester_id;
    if (params.event_type_id) query.event_type_id = params.event_type_id;
    if (params.is_holiday !== undefined) query.is_holiday = params.is_holiday ? 1 : 0;
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;
    if (params.search) query.search = params.search;
    if (params.all) query.all = 1;
    if (params.per_page) query.per_page = params.per_page;

    const res = await api.get<PaginatedResponse<AcademicEvent>>('/academic-events', { params: query });
    return res.data;
  },

  /** Kalender dikelompokkan per bulan + total event. */
  async calendar(semesterId: number | '', eventTypeId: number | '' = '') {
    const params: Record<string, unknown> = { semester_id: semesterId };
    if (eventTypeId) params.event_type_id = eventTypeId;

    const res = await api.get<ApiResponse<AcademicCalendarResult> & { meta: { total: number } }>(
      '/academic-calendar',
      { params },
    );
    return res.data;
  },

  async create(payload: AcademicEventPayload) {
    const res = await api.post<ApiResponse<AcademicEvent>>('/academic-events', payload);
    return res.data.data;
  },

  async update(id: number, payload: AcademicEventPayload) {
    const res = await api.put<ApiResponse<AcademicEvent>>(`/academic-events/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/academic-events/${id}`);
  },
};

/** Hari efektif per tanggal */
export const effectiveDaysApi = {
  async list(params: {
    semester_id?: number | '';
    education_level_id?: number | '';
    is_school_day?: boolean;
    from?: string;
    to?: string;
    all?: boolean;
    per_page?: number;
  } = {}) {
    const query: Record<string, unknown> = {};
    if (params.semester_id) query.semester_id = params.semester_id;
    if (params.education_level_id) query.education_level_id = params.education_level_id;
    if (params.is_school_day !== undefined) query.is_school_day = params.is_school_day ? 1 : 0;
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;
    if (params.all) query.all = 1;
    if (params.per_page) query.per_page = params.per_page;

    const res = await api.get<PaginatedResponse<EffectiveDay>>('/effective-days', { params: query });
    return res.data;
  },

  async create(payload: EffectiveDayPayload) {
    const res = await api.post<ApiResponse<EffectiveDay>>('/effective-days', payload);
    return res.data.data;
  },

  async update(id: number, payload: EffectiveDayPayload) {
    const res = await api.put<ApiResponse<EffectiveDay>>(`/effective-days/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/effective-days/${id}`);
  },

  /** Hitung hari efektif dari rentang semester dikurangi libur. */
  async generate(payload: {
    semester_id: number | '';
    education_level_id?: number | '';
    overwrite?: boolean;
  }) {
    const res = await api.post<ApiResponse<EffectiveDaysGenerateResult>>(
      '/effective-days/generate',
      payload,
    );
    return res.data;
  },

  async summary(params: { semester_id: number | ''; education_level_id?: number | '' }) {
    const query: Record<string, unknown> = { semester_id: params.semester_id };
    if (params.education_level_id) query.education_level_id = params.education_level_id;

    const res = await api.get<ApiResponse<EffectiveDaysSummary>>('/effective-days/summary', {
      params: query,
    });
    return res.data.data;
  },
};
