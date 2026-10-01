import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  ActivityAchievementRow,
  ActivityAssessmentResultRow,
  ActivityAssessmentRow,
  ActivityAttendanceSheet,
  ActivityAttendanceSummary,
  ActivityCapacity,
  ActivityCategoryNode,
  ActivityCertificateRow,
  ActivityCertificateVerification,
  ActivityCompletionEvaluation,
  ActivityConsentRow,
  ActivityDashboard,
  ActivityEligibilityResult,
  ActivityHealth,
  ActivityMembershipRow,
  ActivityMembershipSummary,
  ActivityMeta,
  ActivityPortfolio,
  ActivityProgramRow,
  ActivityRegistrationRow,
  ActivityScheduleRow,
  ActivitySessionRow,
  ActivityTranscript,
} from '../types';

/**
 * Klien API Modul Student Activity.
 *
 * Seluruh panggilan memakai instance axios bersama (token & penanganan 401 sudah
 * diatur di sana). Nama fungsi memakai kalimat kerja pengguna — "register",
 * "recordAttendance", "verifyCertificate" — supaya layar tidak berbicara dalam
 * bahasa tabel.
 */
export const activityApi = {
  me: () => api.get<ApiResponse<ActivityMeta>>('/activities/me'),

  metadata: () => api.get<ApiResponse<{ catalog: { categories: ActivityCategoryNode[] } }>>('/activities/metadata'),

  dashboard: () => api.get<ApiResponse<ActivityDashboard>>('/activities/dashboard'),

  health: () => api.get<ApiResponse<ActivityHealth>>('/activities/health'),

  categories: () => api.get<ApiResponse<ActivityCategoryNode[]>>('/activities/categories'),

  discover: (params?: Record<string, unknown>) =>
    api.get<PaginatedResponse<ActivityProgramRow>>('/activities/discover', { params }),

  programs: (params?: Record<string, unknown>) =>
    api.get<PaginatedResponse<ActivityProgramRow>>('/activities/programs', { params }),

  program: (programId: number) => api.get<ApiResponse<ActivityProgramRow>>(`/activities/programs/${programId}`),

  myPrograms: () => api.get<ApiResponse<ActivityProgramRow[]>>('/activities/my-programs'),

  capacity: (programId: number) => api.get<ApiResponse<ActivityCapacity>>(`/activities/programs/${programId}/capacity`),

  checkEligibility: (programId: number, studentId: number) =>
    api.post<ApiResponse<ActivityEligibilityResult>>(`/activities/programs/${programId}/eligibility`, {
      student_id: studentId,
    }),

  register: (programId: number, payload: { student_id: number; motivation?: string; idempotency_key?: string }) =>
    api.post<ApiResponse<ActivityRegistrationRow>>(`/activities/programs/${programId}/registrations`, payload),

  myRegistrations: (params?: Record<string, unknown>) =>
    api.get<PaginatedResponse<ActivityRegistrationRow>>('/activities/registrations/mine', { params }),

  registrations: (params?: Record<string, unknown>) =>
    api.get<PaginatedResponse<ActivityRegistrationRow>>('/activities/registrations', { params }),

  registration: (id: number) =>
    api.get<ApiResponse<{ registration: ActivityRegistrationRow; history: { to: string; actor?: string; notes?: string; at?: string }[]; consents: unknown[] }>>(
      `/activities/registrations/${id}`,
    ),

  decideRegistration: (id: number, decision: string, notes?: string) =>
    api.post<ApiResponse<ActivityRegistrationRow>>(`/activities/registrations/${id}/decide`, { decision, notes }),

  cancelRegistration: (id: number, reason?: string) =>
    api.post<ApiResponse<ActivityRegistrationRow>>(`/activities/registrations/${id}/cancel`, { reason }),

  waitlist: (programId: number) =>
    api.get<ApiResponse<{ id: number; registration_id: number; student?: string; position: number; status?: string }[]>>(
      `/activities/programs/${programId}/waitlist`,
    ),

  myMemberships: () => api.get<PaginatedResponse<ActivityMembershipRow>>('/activities/memberships', { params: { per_page: 50 } }),

  memberships: (params?: Record<string, unknown>) =>
    api.get<PaginatedResponse<ActivityMembershipRow>>('/activities/memberships', { params }),

  membershipForStudent: (studentId: number) =>
    api.get<ApiResponse<ActivityMembershipSummary[]>>(`/activities/students/${studentId}/memberships`),

  suspendMembership: (id: number, reason: string) =>
    api.post<ApiResponse<ActivityMembershipRow>>(`/activities/memberships/${id}/suspend`, { reason }),

  reactivateMembership: (id: number, reason?: string) =>
    api.post<ApiResponse<ActivityMembershipRow>>(`/activities/memberships/${id}/reactivate`, { reason }),

  withdraw: (id: number, reason: string) =>
    api.post<ApiResponse<{ id: number; status: string }>>(`/activities/memberships/${id}/withdraw`, { reason }),

  schedules: (programId: number) =>
    api.get<ApiResponse<ActivityScheduleRow[]>>(`/activities/programs/${programId}/schedules`),

  sessions: (params?: Record<string, unknown>) => api.get<ApiResponse<ActivitySessionRow[]>>('/activities/sessions', { params }),

  generateSessions: (programId: number, from?: string, to?: string) =>
    api.post<ApiResponse<{ created: number; skipped: number }>>(`/activities/programs/${programId}/sessions/generate`, { from, to }),

  attendanceSheet: (sessionId: number) =>
    api.get<ApiResponse<ActivityAttendanceSheet>>(`/activities/sessions/${sessionId}/attendance`),

  recordAttendance: (sessionId: number, payload: { student_id: number; status: string; minutes_late?: number | null; notes?: string | null }) =>
    api.post(`/activities/sessions/${sessionId}/attendance`, payload),

  recordAttendanceBulk: (sessionId: number, rows: { student_id: number; status: string }[], idempotencyKey?: string) =>
    api.post<ApiResponse<{ recorded: number; failed: unknown[] }>>(`/activities/sessions/${sessionId}/attendance/bulk`, {
      rows,
      idempotency_key: idempotencyKey,
    }),

  completeSession: (sessionId: number) => api.post(`/activities/sessions/${sessionId}/complete`, {}),

  programAttendance: (programId: number) =>
    api.get<ApiResponse<{ program_id: number; sessions: number; coverage: number | null; students: { student_id: number; name?: string; summary: ActivityAttendanceSummary }[] }>>(
      `/activities/programs/${programId}/attendance/summary`,
    ),

  studentAttendance: (studentId: number, programId: number) =>
    api.get<ApiResponse<ActivityAttendanceSummary>>(`/activities/students/${studentId}/programs/${programId}/attendance`),

  assessments: (programId: number) =>
    api.get<ApiResponse<ActivityAssessmentRow[]>>(`/activities/programs/${programId}/assessments`),

  createAssessment: (programId: number, payload: Record<string, unknown>) =>
    api.post<ApiResponse<ActivityAssessmentRow>>(`/activities/programs/${programId}/assessments`, payload),

  publishAssessment: (assessmentId: number) =>
    api.post<ApiResponse<ActivityAssessmentRow>>(`/activities/assessments/${assessmentId}/publish`, {}),

  assessmentResults: (assessmentId: number) =>
    api.get<ApiResponse<ActivityAssessmentResultRow[]>>(`/activities/assessments/${assessmentId}/results`),

  submitResult: (assessmentId: number, payload: { student_id: number; score?: number | null; feedback?: string | null; level?: string | null }) =>
    api.post<ApiResponse<ActivityAssessmentResultRow>>(`/activities/assessments/${assessmentId}/results`, payload),

  progress: (membershipId: number) =>
    api.get<ApiResponse<{ attendance_percentage: string | number | null; average_score: string | number | null; sessions_attended: number; assessments_completed: number }>>(
      `/activities/memberships/${membershipId}/progress`,
    ),

  completion: (membershipId: number) =>
    api.get<ApiResponse<ActivityCompletionEvaluation>>(`/activities/memberships/${membershipId}/completion`),

  completeActivity: (membershipId: number, payload: { notes?: string; waive_rules?: boolean }) =>
    api.post(`/activities/memberships/${membershipId}/complete`, payload),

  achievements: (params?: Record<string, unknown>) =>
    api.get<PaginatedResponse<ActivityAchievementRow>>('/activities/achievements', { params }),

  createAchievement: (payload: Record<string, unknown>) =>
    api.post<ApiResponse<ActivityAchievementRow>>('/activities/achievements', payload),

  verifyAchievement: (id: number, decision: string, notes?: string) =>
    api.post<ApiResponse<ActivityAchievementRow>>(`/activities/achievements/${id}/verify`, { decision, notes }),

  certificates: (params?: Record<string, unknown>) =>
    api.get<PaginatedResponse<ActivityCertificateRow>>('/activities/certificates', { params }),

  issueCertificate: (completionId: number, force = false) =>
    api.post<ApiResponse<ActivityCertificateRow>>(`/activities/completions/${completionId}/certificate`, { force }),

  issueAchievementCertificate: (achievementId: number) =>
    api.post<ApiResponse<ActivityCertificateRow[]>>(`/activities/achievements/${achievementId}/certificate`, {}),

  revokeCertificate: (id: number, reason: string) =>
    api.post<ApiResponse<ActivityCertificateRow>>(`/activities/certificates/${id}/revoke`, { reason }),

  // Verifikasi publik: tanpa token, tanpa data pribadi.
  verifyCertificate: (number: string) =>
    api.post<ApiResponse<ActivityCertificateVerification>>('/activities/certificates/verify', { number }),

  portfolio: (studentId: number, periodId?: number) =>
    api.get<ApiResponse<ActivityPortfolio>>(`/activities/students/${studentId}/portfolio`, { params: { period_id: periodId } }),

  transcript: (studentId: number, periodId?: number) =>
    api.get<ApiResponse<ActivityTranscript>>(`/activities/students/${studentId}/transcript`, { params: { period_id: periodId } }),

  storePortfolio: (studentId: number, periodId?: number) =>
    api.post<ApiResponse<{ id: number; public_token?: string | null }>>('/activities/portfolios', {
      student_id: studentId,
      period_id: periodId,
    }),

  sharePortfolio: (portfolioId: number, enabled: boolean) =>
    api.post(`/activities/portfolios/${portfolioId}/share`, { enabled }),

  reports: (type: string, params?: Record<string, unknown>) =>
    api.get<ApiResponse<{ type: string; columns: string[]; rows: Record<string, unknown>[]; totals: Record<string, unknown> }>>(
      `/activities/reports/${type}`,
      { params },
    ),

  // Portal orang tua
  parentDashboard: () => api.get<ApiResponse<ActivityDashboard>>('/activities/portal/parent'),

  parentChild: (studentId: number) => api.get<ApiResponse<Record<string, unknown>>>(`/activities/portal/parent/children/${studentId}`),

  pendingConsents: () => api.get<ApiResponse<ActivityConsentRow[]>>('/activities/portal/consents'),

  decideConsent: (consentId: number, decision: 'APPROVED' | 'DECLINED', notes?: string) =>
    api.post<ApiResponse<ActivityConsentRow>>(`/activities/portal/consents/${consentId}/decide`, { decision, notes }),
};
