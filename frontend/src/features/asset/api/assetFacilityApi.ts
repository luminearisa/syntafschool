import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type { Facility, FacilityAvailability, FacilityBooking, ListParams } from '../types';

const toForm = <T extends object>(payload: T) => payload;

/* ── Fasilitas ───────────────────────────────────────────────────────────── */

export const facilitiesApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<Facility>>('/facilities', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<Facility>>(`/facilities/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<Facility>>('/facilities', toForm(payload)).then((r) => r.data.data),
  update: (id: number, payload: Record<string, unknown>) =>
    api.patch<ApiResponse<Facility>>(`/facilities/${id}`, toForm(payload)).then((r) => r.data.data),

  /** Diperiksa SEBELUM menyimpan booking; pesannya menjelaskan alasan penolakan. */
  availability: (id: number, params: { date?: string; from?: string; to?: string }) =>
    api.get<ApiResponse<FacilityAvailability>>(`/facilities/${id}/availability`, { params }).then((r) => r.data.data),

  utilization: (id: number, params: { from?: string; to?: string } = {}) =>
    api.get<ApiResponse<{ rows: { date: string; bookings: number; minutes: number; utilization_rate: string }[] }>>(`/facilities/${id}/utilization`, { params }).then((r) => r.data.data),

  setOperatingHours: (id: number, hours: { day_of_week: number; open_time?: string | null; close_time?: string | null; is_closed?: boolean }[]) =>
    api.post<ApiResponse<Facility>>(`/facilities/${id}/operating-hours`, { hours }).then((r) => r.data.data),

  blackouts: (id: number) =>
    api.get<ApiResponse<{ id: number; start_at: string; end_at: string; reason?: string }[]>>(`/facilities/${id}/blackouts`).then((r) => r.data.data),
  addBlackout: (id: number, payload: { start_at: string; end_at: string; reason?: string }) =>
    api.post<ApiResponse<Facility>>(`/facilities/${id}/blackouts`, toForm(payload)).then((r) => r.data.data),

  /** Penutupan mengembalikan daftar booking terdampak — bukan sekadar mengubah status. */
  close: (id: number, payload: { reason: string; from?: string; to?: string }) =>
    api
      .post<ApiResponse<{ facility: Facility; affected: { id: number; booking_number: string; title: string; start_at: string }[] }>>(
        `/facilities/${id}/close`,
        toForm(payload),
      )
      .then((r) => r.data.data),
  reopen: (id: number, payload: { notes?: string } = {}) =>
    api.post<ApiResponse<Facility>>(`/facilities/${id}/reopen`, toForm(payload)).then((r) => r.data.data),

  book: (id: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<FacilityBooking>>(`/facilities/${id}/book`, toForm(payload)).then((r) => r.data.data),
};

/* ── Booking fasilitas ───────────────────────────────────────────────────── */

export const bookingsApi = {
  list: (params: ListParams = {}) =>
    api.get<PaginatedResponse<FacilityBooking>>('/facility-bookings', { params }).then((r) => r.data),
  show: (id: number) => api.get<ApiResponse<FacilityBooking>>(`/facility-bookings/${id}`).then((r) => r.data.data),
  approve: (id: number, payload: { notes?: string } = {}) =>
    api.post<ApiResponse<FacilityBooking>>(`/facility-bookings/${id}/approve`, toForm(payload)).then((r) => r.data.data),
  reject: (id: number, payload: { reason: string }) =>
    api.post<ApiResponse<FacilityBooking>>(`/facility-bookings/${id}/reject`, toForm(payload)).then((r) => r.data.data),
  checkIn: (id: number) => api.post<ApiResponse<FacilityBooking>>(`/facility-bookings/${id}/check-in`, {}).then((r) => r.data.data),
  complete: (id: number, payload: { notes?: string; attendee_actual?: number } = {}) =>
    api.post<ApiResponse<FacilityBooking>>(`/facility-bookings/${id}/complete`, toForm(payload)).then((r) => r.data.data),
  cancel: (id: number, payload: { reason: string }) =>
    api.post<ApiResponse<FacilityBooking>>(`/facility-bookings/${id}/cancel`, toForm(payload)).then((r) => r.data.data),
  quickBook: (facilityId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<FacilityBooking>>(`/facilities/${facilityId}/book`, toForm(payload)).then((r) => r.data.data),
};
