import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  BkAppointmentRow, BkAssessmentRow, BkCaseRow, BkDashboardWidgets, BkFollowUpRow, BkGoalRow,
  BkInterventionRow, BkNoteRow, BkPlanRow, BkReferralRow, BkSessionRow, BkSupportProfile, BkTimelineEvent,
} from '../types';

/**
 * Klien API Modul BK.
 *
 * Endpoint portal (`/bk/portal/*`) sengaja dipisah karena dipakai siswa & orang
 * tua, sedangkan sisanya untuk staf BK. Tidak ada endpoint di sini yang
 * memintakan isi catatan konseling kepada pihak yang tidak berwenang — server
 * tetap penentu akhirnya.
 */
export const bkApi = {
  me: () => api.get<ApiResponse<{ user: { id: number; name: string }; roles: string[]; permissions: string[] }>>('/bk/me'),
  metadata: () => api.get<ApiResponse<{ enums: Record<string, { value: string; label: string }[]>; report_types: string[] }>>('/bk/metadata'),
  dashboard: () => api.get<ApiResponse<BkDashboardWidgets>>('/bk/dashboard'),
  today: () => api.get<ApiResponse<{ appointments: Record<string, unknown>[]; follow_ups: Record<string, unknown>[] }>>('/bk/dashboard/today'),
  health: () => api.get<ApiResponse<Record<string, unknown>>>('/bk/health'),

  supportProfile: (studentId: number) => api.get<ApiResponse<BkSupportProfile>>(`/bk/students/${studentId}/support-profile`),

  // ------------------------------------------------------------------ referral
  referrals: (params?: Record<string, unknown>) => api.get<PaginatedResponse<BkReferralRow>>('/bk/referrals', { params }),
  referral: (id: number) => api.get<ApiResponse<BkReferralRow & { screenings?: Record<string, unknown>[] }>>(`/bk/referrals/${id}`),
  createReferral: (payload: Record<string, unknown>) => api.post<ApiResponse<BkReferralRow>>('/bk/referrals', payload),
  receiveReferral: (id: number, note?: string) => api.post<ApiResponse<BkReferralRow>>(`/bk/referrals/${id}/receive`, { note }),
  screenReferral: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<Record<string, unknown>>>(`/bk/referrals/${id}/screen`, payload),
  openCase: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<BkCaseRow>>(`/bk/referrals/${id}/open-case`, payload),
  rejectReferral: (id: number, reason: string) => api.post<ApiResponse<BkReferralRow>>(`/bk/referrals/${id}/reject`, { reason }),

  // ---------------------------------------------------------------------- kasus
  cases: (params?: Record<string, unknown>) => api.get<PaginatedResponse<BkCaseRow>>('/bk/cases', { params }),
  case: (id: number) => api.get<ApiResponse<{ case: BkCaseRow; closure_blockers: string[]; confidential_access: boolean }>>(`/bk/cases/${id}`),
  updateCase: (id: number, payload: Record<string, unknown>) => api.patch<ApiResponse<BkCaseRow>>(`/bk/cases/${id}`, payload),
  transitionCase: (id: number, status: string, note?: string) => api.post<ApiResponse<BkCaseRow>>(`/bk/cases/${id}/transition`, { status, note }),
  assignCase: (id: number, counselorUserId: number, force = false) => api.post<ApiResponse<BkCaseRow>>(`/bk/cases/${id}/assign`, { counselor_user_id: counselorUserId, force }),
  workload: () => api.get<ApiResponse<{ counselors: Record<string, unknown>[]; candidates: Record<string, unknown>[] }>>('/bk/cases/workload'),
  closeCase: (id: number, closureReason: string, summary?: string, force = false) => api.post<ApiResponse<BkCaseRow>>(`/bk/cases/${id}/close`, { closure_reason: closureReason, summary, force }),
  reopenCase: (id: number, reason: string) => api.post<ApiResponse<BkCaseRow>>(`/bk/cases/${id}/reopen`, { reason }),
  reviewCase: (id: number, note?: string, nextReviewDate?: string) => api.post<ApiResponse<BkCaseRow>>(`/bk/cases/${id}/review`, { note, next_review_date: nextReviewDate }),
  escalateCase: (id: number, level: string, reason: string) => api.post<ApiResponse<BkCaseRow>>(`/bk/cases/${id}/escalate`, { level, reason }),
  timeline: (id: number) => api.get<ApiResponse<BkTimelineEvent[]>>(`/bk/cases/${id}/timeline`),
  addParticipant: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<null>>(`/bk/cases/${id}/participants`, payload),

  // ------------------------------------------------------- rekaman di kasus
  screenCase: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<Record<string, unknown>>>(`/bk/cases/${id}/screenings`, payload),
  sessions: (id: number) => api.get<ApiResponse<BkSessionRow[]>>(`/bk/cases/${id}/sessions`),
  createSession: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<BkSessionRow>>(`/bk/cases/${id}/sessions`, payload),
  notes: (id: number) => api.get<ApiResponse<BkNoteRow[]>>(`/bk/cases/${id}/notes`),
  createNote: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<BkNoteRow>>(`/bk/cases/${id}/notes`, payload),
  assessments: (id: number) => api.get<ApiResponse<BkAssessmentRow[]>>(`/bk/cases/${id}/assessments`),
  assignAssessment: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<BkAssessmentRow>>(`/bk/cases/${id}/assessments`, payload),
  plans: (id: number) => api.get<ApiResponse<BkPlanRow[]>>(`/bk/cases/${id}/plans`),
  createPlan: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<BkPlanRow>>(`/bk/cases/${id}/plans`, payload),
  updateGoal: (goalId: number, payload: Record<string, unknown>) => api.patch<ApiResponse<BkGoalRow>>(`/bk/goals/${goalId}`, payload),
  interventions: (id: number) => api.get<ApiResponse<BkInterventionRow[]>>(`/bk/cases/${id}/interventions`),
  createIntervention: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<BkInterventionRow>>(`/bk/cases/${id}/interventions`, payload),
  updateIntervention: (interventionId: number, payload: Record<string, unknown>) => api.patch<ApiResponse<BkInterventionRow>>(`/bk/interventions/${interventionId}`, payload),
  followUps: (id: number) => api.get<ApiResponse<BkFollowUpRow[]>>(`/bk/cases/${id}/follow-ups`),
  createFollowUp: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<BkFollowUpRow>>(`/bk/cases/${id}/follow-ups`, payload),
  documents: (id: number) => api.get<ApiResponse<Record<string, unknown>[]>>(`/bk/cases/${id}/documents`),
  meetings: (id: number) => api.get<ApiResponse<Record<string, unknown>[]>>(`/bk/cases/${id}/meetings`),

  // ------------------------------------------------------------------ tindak lanjut
  allFollowUps: (params?: Record<string, unknown>) => api.get<PaginatedResponse<BkFollowUpRow>>('/bk/follow-ups', { params }),
  completeFollowUp: (id: number, payload: Record<string, unknown>) => api.post<ApiResponse<BkFollowUpRow>>(`/bk/follow-ups/${id}/complete`, payload),
  rescheduleFollowUp: (id: number, dueDate: string, reason?: string) => api.post<ApiResponse<BkFollowUpRow>>(`/bk/follow-ups/${id}/reschedule`, { due_date: dueDate, reason }),

  // -------------------------------------------------------------- janji temu
  appointments: (params?: Record<string, unknown>) => api.get<PaginatedResponse<BkAppointmentRow>>('/bk/appointments', { params }),
  createAppointment: (payload: Record<string, unknown>) => api.post<ApiResponse<BkAppointmentRow>>('/bk/appointments', payload),
  checkConflicts: (payload: Record<string, unknown>) => api.post<ApiResponse<{ type: string; label: string; detail?: string | null }[]>>('/bk/appointments/check-conflicts', payload),
  rescheduleAppointment: (id: number, start: string, end: string, reason?: string) => api.patch<ApiResponse<BkAppointmentRow>>(`/bk/appointments/${id}/reschedule`, { scheduled_start: start, scheduled_end: end, reason }),
  cancelAppointment: (id: number, reason: string) => api.post<ApiResponse<BkAppointmentRow>>(`/bk/appointments/${id}/cancel`, { reason }),
  appointmentAttendance: (id: number, status: string, note?: string) => api.post<ApiResponse<BkAppointmentRow>>(`/bk/appointments/${id}/attendance`, { attendance_status: status, note }),

  // ------------------------------------------------------------------ laporan & audit
  reports: (type: string, params?: Record<string, unknown>) => api.get<ApiResponse<{ type: string; range: Record<string, string>; rows: Record<string, unknown>[]; meta: Record<string, unknown> }>>(`/bk/reports/${type}`, { params }),
  audit: (params?: Record<string, unknown>) => api.get<PaginatedResponse<Record<string, unknown>>>('/bk/audit', { params }),

  // ------------------------------------------------------------------ portal
  portalSummary: () => api.get<ApiResponse<{ student_ids: number[]; is_guardian: boolean; pending_consents: number; queued_requests: number }>>('/bk/portal/summary'),
  portalRequests: (params?: Record<string, unknown>) => api.get<PaginatedResponse<Record<string, unknown>>>('/bk/portal/requests', { params }),
  requestByStudent: (payload: Record<string, unknown>) => api.post<ApiResponse<Record<string, unknown>>>('/bk/portal/requests/student', payload),
  requestByGuardian: (payload: Record<string, unknown>) => api.post<ApiResponse<Record<string, unknown>>>('/bk/portal/requests/guardian', payload),
  portalAppointments: (params?: Record<string, unknown>) => api.get<PaginatedResponse<BkAppointmentRow>>('/bk/portal/appointments', { params }),
  portalConsents: (params?: Record<string, unknown>) => api.get<PaginatedResponse<Record<string, unknown>>>('/bk/portal/consents', { params }),
  decideConsent: (id: number, decision: 'APPROVED' | 'DECLINED', note?: string) => api.post<ApiResponse<Record<string, unknown>>>(`/bk/portal/consents/${id}/decide`, { decision, note }),
};
