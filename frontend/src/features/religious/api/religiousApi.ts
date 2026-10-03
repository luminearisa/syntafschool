import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  PrayerScheduleDay,
  ReligiousActivity,
  ReligiousActivitySession,
  ReligiousAnalytics,
  ReligiousBooking,
  ReligiousDashboard,
  ReligiousFridayPrayer,
  ReligiousKhutbah,
  ReligiousMeta,
  ReligiousMosque,
  ReligiousMosqueArea,
  ReligiousMosqueOverview,
  ReligiousPrayerType,
  ReligiousPrayerWindow,
  ReligiousRamadanProgram,
  ReligiousAttendanceSummary,
} from '@/types/religious';

type Query = Record<string, string | number | boolean | undefined | null>;

function cleanParams(params: Query = {}): Record<string, string | number | boolean> {
  return Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  ) as Record<string, string | number | boolean>;
}

async function getData<T>(path: string, params?: Query): Promise<T> {
  const response = await api.get<ApiResponse<T>>(path, { params: cleanParams(params) });
  return response.data.data;
}

async function postData<T>(path: string, payload?: Record<string, unknown>): Promise<T> {
  const body = payload
    ? Object.fromEntries(Object.entries(payload).filter(([, value]) => value !== undefined && value !== ''))
    : undefined;
  const response = await api.post<ApiResponse<T>>(path, body);
  return response.data.data;
}

export const religiousApi = {
  meta: () => getData<ReligiousMeta>('/religious/meta'),
  integrations: () => getData<{
    prayer_time?: Record<string, unknown>;
    integrations?: Record<string, { available?: boolean; reason?: string | null }>;
    provided_contracts?: string[];
    note?: string;
  }>('/religious/integrations'),
  dashboard: (date: string) => getData<ReligiousDashboard>('/religious/dashboard', { date }),
  parentDashboard: (date: string) => getData<{
    enabled: boolean;
    reason?: string;
    date?: string;
    children?: Array<{
      student_id: number;
      prayer_attendance?: Array<{ prayer_type: string; status: string }>;
      achievements?: Array<{ title: string; date: string }>;
    }>;
    privacy_note?: string;
  }>('/religious/parent/dashboard', { date }),

  listMosques: (params: { term?: string; status?: string; campus_id?: number | '' } = {}) =>
    getData<ReligiousMosque[]>('/religious/mosques', { ...params, limit: 200 }),
  mosqueOverview: (id: number) => getData<ReligiousMosqueOverview>(`/religious/mosques/${id}`),
  mosqueAreas: (id: number) => getData<ReligiousMosqueArea[]>(`/religious/mosques/${id}/areas`),
  createMosque: (payload: Record<string, unknown>) => postData<ReligiousMosqueOverview>('/religious/mosques', payload),
  updateMosque: (id: number, payload: Record<string, unknown>) =>
    api.put<ApiResponse<ReligiousMosque>>(`/religious/mosques/${id}`, payload).then((response) => response.data.data),
  createMosqueArea: (id: number, payload: Record<string, unknown>) =>
    postData<ReligiousMosqueArea>(`/religious/mosques/${id}/areas`, payload),

  prayerTypes: () => getData<ReligiousPrayerType[]>('/religious/prayer-types'),
  prayerSources: () => getData<{ sources: Array<Record<string, unknown>>; provider: Record<string, unknown> }>(
    '/religious/prayer-time-sources',
  ),
  prayerSchedule: (params: { date: string; mosque_id?: number | ''; campus_id?: number | '' }) =>
    getData<PrayerScheduleDay>('/religious/prayer-schedules', params),
  savePrayerSchedule: (payload: Record<string, unknown>) =>
    postData<Record<string, unknown>>('/religious/prayer-schedules', payload),
  generatePrayerSchedule: (payload: Record<string, unknown>) =>
    postData<{ created: number; date: string; source?: string; reason?: string; schedule_ids?: number[] }>(
      '/religious/prayer-schedules/generate',
      payload,
    ),
  generatePrayerRange: (payload: Record<string, unknown>) =>
    postData<Record<string, { created: number; date: string; source?: string; reason?: string }>>(
      '/religious/prayer-schedules/generate-range',
      payload,
    ),
  prayerScheduleVersions: (id: number) => getData<{
    schedule: Record<string, unknown>;
    versions: Array<Record<string, unknown>>;
  }>(`/religious/prayer-schedules/${id}/versions`),

  attendanceStatistics: (params: { date: string; prayer_type?: string; mosque_id?: number | ''; campus_id?: number | '' }) =>
    getData<ReligiousAttendanceSummary>('/religious/prayer-attendance/statistics', params),
  attendanceDashboard: (params: { date: string; prayer_type?: string; mosque_id?: number | ''; campus_id?: number | '' }) =>
    getData<{
      date: string;
      summary: ReligiousAttendanceSummary;
      by_prayer?: ReligiousAttendanceSummary['by_prayer'];
      by_dormitory?: Record<string, ReligiousAttendanceSummary>;
      by_grade?: Record<string, ReligiousAttendanceSummary>;
      note?: string;
    }>('/religious/attendance/dashboard', params),
  attendanceWindow: (params: {
    prayer_type: string;
    at?: string;
    mosque_id?: number | '';
    campus_id?: number | '';
    dormitory_id?: number | '';
    grade_level?: string;
    prayer_schedule_id?: number | '';
  }) => getData<ReligiousPrayerWindow>('/religious/prayer-attendance/window', params),
  recordPrayerAttendance: (payload: Record<string, unknown>) =>
    postData<{
      recorded: boolean;
      duplicate: boolean;
      attendance: { id: number; status: string; prayer_type: string; attendance_date: string };
      window?: { checked?: boolean; authorized_override?: boolean };
    }>('/religious/prayer-attendance', payload),
  recordPrayerAttendanceBulk: (payload: Record<string, unknown>) =>
    postData<Record<string, unknown>>('/religious/prayer-attendance/bulk', payload),
  closeAttendanceDay: (payload: Record<string, unknown>) =>
    postData<Record<string, unknown>>('/religious/attendance/close-day', payload),

  activities: (params: { activity_type?: string; mosque_id?: number | ''; status?: string } = {}) =>
    getData<ReligiousActivity[]>('/religious/activities', { ...params, limit: 200 }),
  createActivity: (payload: Record<string, unknown>) =>
    postData<ReligiousActivity>('/religious/activities', payload),
  publishActivity: (id: number) => postData<ReligiousActivity>(`/religious/activities/${id}/publish`),
  activitySessions: (id: number) => getData<ReligiousActivitySession[]>(`/religious/activities/${id}/sessions`),
  createActivitySession: (id: number, payload: Record<string, unknown>) =>
    postData<ReligiousActivitySession>(`/religious/activities/${id}/sessions`, payload),
  activityParticipants: (id: number) => getData<{
    activity_id: number;
    total: number;
    [status: string]: number;
  }>(`/religious/activities/${id}/participants`),
  addActivityParticipant: (id: number, payload: Record<string, unknown>) =>
    postData<Record<string, unknown>>(`/religious/activities/${id}/participants`, payload),
  completeActivity: (id: number, payload?: Record<string, unknown>) =>
    postData<Record<string, unknown>>(`/religious/activities/${id}/complete`, payload),

  ramadan: (date: string) => getData<{
    programs: ReligiousRamadanProgram[];
    dashboard?: Record<string, unknown>;
  }>('/religious/ramadan', { date }),
  createRamadanProgram: (payload: Record<string, unknown>) =>
    postData<ReligiousRamadanProgram>('/religious/ramadan/programs', payload),
  generateRamadanDays: (id: number, total_days: number) =>
    postData<Record<string, unknown>>(`/religious/ramadan/programs/${id}/days`, { total_days }),
  ramadanReport: (id: number) => getData<Record<string, unknown>>(`/religious/ramadan/programs/${id}/report`),

  analytics: (params: { from: string; to: string }) =>
    getData<ReligiousAnalytics>('/religious/analytics', params),
  prayerReport: (params: { from: string; to: string; group_by?: string; mosque_id?: number | '' }) =>
    getData<{ from: string; to: string; group_by: string; rows: Array<Record<string, unknown>> }>(
      '/religious/analytics/prayer',
      params,
    ),

  bookings: (params: { mosque_id?: number | ''; status?: string; from?: string; to?: string } = {}) =>
    getData<ReligiousBooking[]>('/religious/bookings', { ...params, limit: 200 }),
  createBooking: (payload: Record<string, unknown>) => postData<ReligiousBooking>('/religious/bookings', payload),
  approveBooking: (id: number, payload?: Record<string, unknown>) =>
    postData<ReligiousBooking>(`/religious/bookings/${id}/approve`, payload),
  rejectBooking: (id: number, reason: string) =>
    postData<ReligiousBooking>(`/religious/bookings/${id}/reject`, { reason }),

  friday: (params: { mosque_id?: number | ''; from?: string; to?: string } = {}) =>
    getData<{ history: ReligiousFridayPrayer[]; upcoming: Array<Record<string, unknown>> }>(
      '/religious/friday',
      params,
    ),
  scheduleFriday: (payload: Record<string, unknown>) =>
    postData<ReligiousFridayPrayer>('/religious/friday', payload),
  recordFridayAttendance: (id: number, actual_attendance: number) =>
    postData<ReligiousFridayPrayer>(`/religious/friday/${id}/attendance`, { actual_attendance }),
  khutbahs: (params: { term?: string; category_code?: string } = {}) =>
    getData<ReligiousKhutbah[]>('/religious/khutbahs', { ...params, limit: 200 }),
  khutbahCategories: () => getData<Array<{ id: number; code: string; name: string }>>('/religious/khutbah-categories'),
  createKhutbah: (payload: Record<string, unknown>) =>
    postData<ReligiousKhutbah>('/religious/khutbahs', payload),
  transitionKhutbah: (id: number, status: string, reason?: string) =>
    postData<ReligiousKhutbah>(`/religious/khutbahs/${id}/transition`, { status, reason }),
};
