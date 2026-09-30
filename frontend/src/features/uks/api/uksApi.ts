import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  UksDashboard,
  UksImmunization,
  UksIncident,
  UksLetter,
  UksMe,
  UksMedicine,
  UksMetadata,
  UksOfficer,
  UksScreening,
  UksStudentOption,
  UksStudentRecord,
  UksVisitRow,
} from '../types';

export const uksApi = {
  metadata: () => api.get<ApiResponse<UksMetadata>>('/uks/meta').then((r) => r.data.data),

  me: () => api.get<ApiResponse<UksMe>>('/uks/me').then((r) => r.data.data),

  students: (search: string) =>
    api
      .get<ApiResponse<UksStudentOption[]>>('/uks/students', { params: { search } })
      .then((r) => r.data.data),

  dashboard: (params: { from?: string; to?: string }) =>
    api.get<ApiResponse<UksDashboard>>('/uks/dashboard', { params }).then((r) => r.data.data),

  visits: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<UksVisitRow>>('/uks/visits', { params }).then((r) => r.data),

  visit: (id: number) => api.get<ApiResponse<UksVisitRow>>(`/uks/visits/${id}`).then((r) => r.data.data),

  createVisit: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<UksVisitRow>>('/uks/visits', payload).then((r) => r.data.data),

  handleVisit: (id: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<UksVisitRow>>(`/uks/visits/${id}/handle`, payload).then((r) => r.data.data),

  giveMedication: (id: number, payload: Record<string, unknown>) =>
    api.post(`/uks/visits/${id}/medications`, payload).then((r) => r.data),

  referVisit: (id: number, payload: Record<string, unknown>) =>
    api.post(`/uks/visits/${id}/referral`, payload).then((r) => r.data),

  consentVisit: (id: number, payload: Record<string, unknown>) =>
    api.post(`/uks/visits/${id}/consent`, payload).then((r) => r.data),

  notifyParent: (id: number) => api.post(`/uks/visits/${id}/notify-parent`).then((r) => r.data),

  closeVisit: (id: number) => api.post(`/uks/visits/${id}/close`).then((r) => r.data),

  medicines: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<UksMedicine>>('/uks/medicines', { params }).then((r) => r.data),

  createMedicine: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<UksMedicine>>('/uks/medicines', payload).then((r) => r.data.data),

  stockMovement: (id: number, payload: Record<string, unknown>) =>
    api.post(`/uks/medicines/${id}/movements`, payload).then((r) => r.data),

  record: (studentId: number) =>
    api.get<ApiResponse<UksStudentRecord>>(`/uks/records/${studentId}`).then((r) => r.data.data),

  saveProfile: (studentId: number, payload: Record<string, unknown>) =>
    api.put(`/uks/records/${studentId}/profile`, payload).then((r) => r.data),

  screenings: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<UksScreening>>('/uks/screenings', { params }).then((r) => r.data),

  createScreening: (payload: Record<string, unknown>) =>
    api.post('/uks/screenings', payload).then((r) => r.data),

  immunizations: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<UksImmunization>>('/uks/immunizations', { params }).then((r) => r.data),

  createImmunization: (payload: Record<string, unknown>) =>
    api.post('/uks/immunizations', payload).then((r) => r.data),

  incidents: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<UksIncident>>('/uks/incidents', { params }).then((r) => r.data),

  createIncident: (payload: Record<string, unknown>) =>
    api.post('/uks/incidents', payload).then((r) => r.data),

  updateIncident: (id: number, payload: Record<string, unknown>) =>
    api.put(`/uks/incidents/${id}`, payload).then((r) => r.data),

  letters: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<UksLetter>>('/uks/letters', { params }).then((r) => r.data),

  createLetter: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<UksLetter>>('/uks/letters', payload).then((r) => r.data.data),

  officers: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<UksOfficer>>('/uks/officers', { params }).then((r) => r.data),

  officerCandidates: (search: string) =>
    api
      .get<ApiResponse<{ id: number; full_name: string; nip: string | null }[]>>('/uks/officers/candidates', {
        params: { search },
      })
      .then((r) => r.data.data),

  createOfficer: (payload: Record<string, unknown>) =>
    api.post('/uks/officers', payload).then((r) => r.data),

  updateOfficer: (id: number, payload: Record<string, unknown>) =>
    api.put(`/uks/officers/${id}`, payload).then((r) => r.data),
};
