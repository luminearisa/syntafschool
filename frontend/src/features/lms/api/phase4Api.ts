import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

/**
 * Klien API Phase 4–5: kelas langsung, konten interaktif, sertifikat,
 * gamifikasi, pemantauan, laporan, dan tata kelola.
 *
 * Semua panggilan memakai envelope yang sama (`success/data/message/meta`)
 * sehingga halaman cukup membaca `data`.
 */

export type Json = Record<string, unknown>;

export interface LiveSessionPayload {
  id: number;
  title: string;
  type: string;
  provider: string;
  status: string;
  status_label?: string;
  tone?: string;
  start_at: string;
  end_at?: string | null;
  duration_minutes?: number | null;
  joinable?: boolean;
  has_ended?: boolean;
  published_at?: string | null;
  recordings_count?: number;
  attendance_summary?: Json | null;
}

export interface CertificatePayload {
  id: number;
  certificate_number: string;
  verification_token?: string;
  course_id: number;
  course_name?: string | null;
  student_name?: string | null;
  status: string;
  status_label?: string;
  tone?: string;
  issued_at?: string | null;
  grade_value?: number | null;
  completion_percent?: number | null;
  html?: string | { body_html?: string | null } | null;
}

export interface BadgePayload {
  id: number;
  name: string;
  description?: string | null;
  points?: number;
  icon?: string | null;
  awarded_at?: string | null;
  source?: string | null;
}

export interface HealthSummary {
  status: string;
  status_label: string;
  tone?: string;
  checks?: Record<string, { status: string; detail?: string | null; latency_ms?: number | null }>;
  providers?: Json;
  mode?: string;
  checked_at?: string;
}

export interface ReportRow {
  [key: string]: string | number | boolean | null;
}

export interface ReportPayload {
  key: string;
  label: string;
  columns: string[];
  rows: ReportRow[];
  meta: { rows: number; truncated?: boolean; generated_at?: string };
}

const unwrap = <T,>(response: { data: ApiResponse<T> }): T => response.data.data as T;

export const phase4Api = {
  // ----------------------------------------------------------------- kelas langsung
  myLiveSessions: async () =>
    unwrap<{ live_now: LiveSessionPayload[]; sessions: LiveSessionPayload[] }>(
      await api.get('/lms/my/live-sessions'),
    ),

  courseLiveSessions: async (courseId: number, params: Json = {}) =>
    unwrap<{ sessions: LiveSessionPayload[]; next: LiveSessionPayload | null; options: Json }>(
      await api.get(`/lms/courses/${courseId}/live-sessions`, { params }),
    ),

  liveSession: async (sessionId: number) =>
    unwrap<LiveSessionPayload>(await api.get(`/lms/live-sessions/${sessionId}`)),

  liveJoinInfo: async (sessionId: number) =>
    unwrap<{ joinable: boolean; reason?: string; message?: string; join_url?: string | null }>(
      await api.get(`/lms/live-sessions/${sessionId}/join`),
    ),

  joinLiveSession: async (sessionId: number) =>
    unwrap<Json>(await api.post(`/lms/live-sessions/${sessionId}/join`)),

  createLiveSession: async (courseId: number, payload: Json) =>
    unwrap<LiveSessionPayload>(await api.post(`/lms/courses/${courseId}/live-sessions`, payload)),

  publishLiveSession: async (sessionId: number) =>
    unwrap<LiveSessionPayload>(await api.post(`/lms/live-sessions/${sessionId}/publish`)),

  cancelLiveSession: async (sessionId: number, reason: string) =>
    unwrap<LiveSessionPayload>(await api.post(`/lms/live-sessions/${sessionId}/cancel`, { reason })),

  liveAttendance: async (sessionId: number) =>
    unwrap<{ summary: Json; rows: Json[]; pending: number }>(
      await api.get(`/lms/live-sessions/${sessionId}/attendance`),
    ),

  markLiveAttendance: async (
    sessionId: number,
    entries: { user_id: number; status: string; minutes_attended?: number; note?: string }[],
  ) => unwrap<Json>(await api.post(`/lms/live-sessions/${sessionId}/attendance`, { entries })),

  // ------------------------------------------------------------------ sertifikat
  myCertificates: async () =>
    unwrap<{ certificates: CertificatePayload[] }>(await api.get('/lms/my/certificates')),

  certificate: async (id: number) =>
    unwrap<CertificatePayload>(await api.get(`/lms/my/certificates/${id}`)),

  registerCertificateDownload: async (id: number) =>
    unwrap<Json>(await api.get(`/lms/my/certificates/${id}/download`)),

  verifyCertificate: async (query: string) =>
    unwrap<Json>(await api.get('/lms/public/certificates/verify', { params: { query } })),

  courseCertificateEligibility: async (courseId: number) =>
    unwrap<{ requirements: Json[]; students: Json[]; course: Json }>(
      await api.get(`/lms/courses/${courseId}/certificates/eligibility`),
    ),

  issueCertificates: async (courseId: number, payload: Json = {}) =>
    unwrap<Json>(await api.post(`/lms/courses/${courseId}/certificates/issue`, payload)),

  // ------------------------------------------------------------------ gamifikasi
  myGamification: async () =>
    unwrap<{
      enabled: boolean;
      summary: Json;
      streak: Json;
      badges: BadgePayload[];
      recent: Json[];
      weights?: Record<string, number>;
    }>(await api.get('/lms/my/gamification')),

  courseLeaderboard: async (courseId: number, perPage = 20) =>
    unwrap<{ enabled: boolean; display: string; rows: Json[] }>(
      await api.get(`/lms/courses/${courseId}/leaderboard`, { params: { per_page: perPage } }),
    ),

  badgeCatalog: async () => unwrap<{ badges: Json[]; rule_types: Json[] }>(await api.get('/lms/badges')),

  // ------------------------------------------------------------ pemantauan & pesan
  courseHealth: async (courseId: number, days?: number) =>
    unwrap<{
      summary: Json;
      trend: Json;
      dashboard: Json;
      thresholds: Json;
      statuses: Json;
    }>(await api.get(`/lms/courses/${courseId}/health`, { params: days ? { days } : {} })),

  courseEngagement: async (courseId: number) =>
    unwrap<{ health: Json; leaderboard: Json[]; reviews: Json; feedback: Json; trend: Json }>(
      await api.get(`/lms/courses/${courseId}/engagement`),
    ),

  myLearningHealth: async () =>
    unwrap<{ at_risk: Json[]; recent_activity: Json[]; streak: Json }>(
      await api.get('/lms/my/learning-health'),
    ),

  sendCourseMessage: async (courseId: number, payload: Json) =>
    unwrap<Json>(await api.post(`/lms/courses/${courseId}/messages`, payload)),

  // -------------------------------------------------------------------- laporan
  reportCatalog: async () => unwrap<{ reports: Json[] }>(await api.get('/lms/reports')),

  runReport: async (key: string, params: Json = {}) =>
    unwrap<ReportPayload>(await api.get(`/lms/reports/${key}`, { params })),

  exportReport: async (key: string, params: Json = {}, format = 'CSV') =>
    unwrap<Json>(await api.post(`/lms/reports/${key}/export`, { format, ...params })),

  reportExports: async () => unwrap<{ exports: Json[] }>(await api.get('/lms/reports/exports')),

  downloadReport: async (id: number) =>
    unwrap<{ report: Json; contents: string }>(await api.get(`/lms/reports/exports/${id}/download`)),

  // ------------------------------------------------------------------- tata kelola
  lmsHealth: async () => unwrap<HealthSummary>(await api.get('/lms/health')),

  lmsIntegration: async () =>
    unwrap<{ mode: string; providers: Json; notifications: Json }>(await api.get('/lms/integration')),

  auditLog: async (params: Json = {}) =>
    unwrap<{ data: Json[]; meta: { total: number; actions?: string[] } }>(
      await api.get('/lms/audit', { params }),
    ),

  auditSummary: async (params: Json = {}) =>
    unwrap<{ total: number; last_24h: number; by_action: Record<string, number>; by_actor: Record<string, number> }>(
      await api.get('/lms/audit/summary', { params }),
    ),

  integrityChecks: async () =>
    unwrap<{ checks: Json[]; history: Json[] }>(await api.get('/lms/integrity')),

  runIntegrity: async (scope = 'all') =>
    unwrap<Json>(await api.post('/lms/integrity/run', { scope })),

  // --------------------------------------------------- siklus hidup & impor/ekspor
  courseValidation: async (courseId: number) =>
    unwrap<{ ready: boolean; checks: Json[]; validation?: Json; stats?: Json }>(
      await api.get(`/lms/courses/${courseId}/validation`),
    ),

  exportCourse: async (courseId: number, params: Json = {}) =>
    unwrap<{ summary: Json; payload: Json }>(await api.get(`/lms/courses/${courseId}/export`, { params })),

  storeCourseExport: async (courseId: number, params: Json = {}) =>
    unwrap<Json>(await api.get(`/lms/courses/${courseId}/export`, { params: { ...params, store: true } })),

  courseBackups: async (courseId: number) =>
    unwrap<{ backups: Json[] }>(await api.get(`/lms/courses/${courseId}/backups`)),

  createCourseBackup: async (courseId: number, label?: string) =>
    unwrap<Json>(await api.post(`/lms/courses/${courseId}/backups`, { label })),

  importHistory: async (params: Json = {}) =>
    unwrap<{ imports: Json[] }>(await api.get('/lms/course-imports', { params })),

  validateCourseImport: async (payload: Json) =>
    unwrap<Json>(await api.post('/lms/course-imports/validate', { payload })),

  importCourse: async (payload: Json, conflictStrategy = 'SKIP', idempotencyKey?: string) =>
    unwrap<Json>(
      await api.post('/lms/course-imports', {
        payload,
        conflict_strategy: conflictStrategy,
        idempotency_key: idempotencyKey,
      }),
    ),

  // ----------------------------------------------------------------------- SCORM
  scormPackages: async () =>
    unwrap<{ packages: Json[]; supported_versions: string[] }>(await api.get('/lms/scorm/packages')),

  scormTracking: async (activityId: number) =>
    unwrap<{ attempts?: Json[]; summary?: Json; attempt?: Json | null; events?: number }>(
      await api.get(`/lms/scorm/activities/${activityId}/tracking`),
    ),

  // ------------------------------------------------------------------ nilai (queue)
  gradeSyncStatus: async (gradebookId: number) =>
    unwrap<{ counts: Record<string, number>; rows: Json[]; failed: number; synced: number }>(
      await api.get(`/lms/gradebooks/${gradebookId}/sync`),
    ),

  gradeSyncEnqueue: async (gradebookId: number, userIds: number[] = []) =>
    unwrap<Json>(await api.post(`/lms/gradebooks/${gradebookId}/sync`, { user_ids: userIds })),

  gradeSyncRetry: async (gradebookId: number) =>
    unwrap<Json>(await api.post(`/lms/gradebooks/${gradebookId}/sync/retry`)),

  gradeSyncCompare: async (gradebookId: number, userId: number) =>
    unwrap<Json>(await api.get(`/lms/gradebooks/${gradebookId}/sync/compare/${userId}`)),
};
