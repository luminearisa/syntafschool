/**
 * Tipe Modul Bimbingan & Konseling (BK).
 *
 * Bentuk data mengikuti respons `/api/v1/bk/*`; kolom rahasia (`counselor_note`,
 * `discussion`) hanya hadir bila pemanggil memang berwenang membacanya — jadi
 * `undefined` di sini berarti "server menahan", bukan "belum diisi".
 */

export interface BkStudentRef {
  id: number;
  full_name: string;
  nis?: string | null;
}

export interface BkReferralRow {
  id: number;
  referral_number: string;
  student_id: number;
  student?: BkStudentRef | null;
  source_type: string;
  category: string;
  priority: string;
  status: string;
  confidentiality_level: string;
  description?: string;
  referred_at?: string | null;
  case_id?: number | null;
  case?: { id: number; case_number: string; status: string } | null;
}

export interface BkCaseRow {
  id: number;
  case_number: string;
  student_id: number;
  student?: BkStudentRef | null;
  case_type: string;
  category: string;
  title?: string | null;
  priority: string;
  severity: string;
  status: string;
  confidentiality_level: string;
  assigned_counselor_id?: number | null;
  counselor?: { id: number; name: string } | null;
  opened_at?: string | null;
  target_review_date?: string | null;
  next_review_date?: string | null;
  escalation_level?: string;
  risk_flag?: boolean;
  tags?: { id: number; name: string; color?: string | null }[];
}

export interface BkTimelineEvent {
  id: number;
  event: string;
  event_label: string;
  summary: string;
  actor?: string | null;
  actor_role?: string | null;
  status_from?: string | null;
  status_to?: string | null;
  occurred_at?: string | null;
}

export interface BkSessionRow {
  id: number;
  session_number: string;
  session_type: string;
  mode: string;
  status: string;
  scheduled_at?: string | null;
  started_at?: string | null;
  ended_at?: string | null;
  counselor?: string | null;
  attendance_status: string;
  objective?: string | null;
  session_summary?: string | null;
  counselor_note?: string | null;
  has_counselor_note?: boolean;
  next_action?: string | null;
}

export interface BkNoteRow {
  id: number;
  note_type: string;
  body: string;
  confidentiality_level: string;
  is_sensitive: boolean;
  author?: string | null;
  author_role?: string | null;
  occurred_at?: string | null;
  amended_from_id?: number | null;
}

export interface BkFollowUpRow {
  id: number;
  follow_up_number?: string;
  title: string;
  type: string;
  description?: string | null;
  status: string;
  due_date?: string | null;
  is_overdue?: boolean;
  assigned_to?: number | null;
  assigned_to_user?: { id: number; name: string } | null;
  case?: { id: number; case_number: string } | null;
}

export interface BkAppointmentRow {
  id: number;
  appointment_number: string;
  student?: BkStudentRef | null;
  counselor?: { id: number; name: string } | null;
  session_type: string;
  mode: string;
  scheduled_start?: string | null;
  scheduled_end?: string | null;
  location?: string | null;
  status: string;
  attendance_status: string;
  conflict_state?: string;
  conflict_details?: { type: string; label: string; detail?: string | null }[] | null;
}

export interface BkAssessmentRow {
  id: number;
  assessment_number: string;
  template?: string | null;
  target: string;
  status: string;
  assigned_at?: string | null;
  due_date?: string | null;
  total_score?: number | string | null;
}

export interface BkInterventionRow {
  id: number;
  intervention_number?: string;
  type: string;
  title: string;
  description?: string | null;
  status: string;
  start_date?: string | null;
  target_date?: string | null;
  result?: string | null;
  follow_up_required?: boolean;
  owner?: { id: number; name: string } | null;
}

export interface BkPlanRow {
  id: number;
  plan_number: string;
  title: string;
  problem_statement: string;
  status: string;
  started_on?: string | null;
  target_end_on?: string | null;
  goals?: BkGoalRow[];
}

export interface BkGoalRow {
  id: number;
  goal: string;
  objective?: string | null;
  success_indicator?: string | null;
  progress: number;
  status: string;
  timeline_end?: string | null;
}

export interface BkDashboardWidgets {
  active_cases: number;
  new_referrals: number;
  pending_screening: number;
  unassigned_cases: number;
  today_sessions: number;
  upcoming_sessions: number;
  overdue_follow_ups: number;
  open_interventions: number;
  pending_support_requests: number;
  review_due_soon: number;
  cases_by_category: { key: string; total: number }[];
  cases_by_priority: { key: string; total: number }[];
  cases_by_counselor: { counselor: string; total: number }[];
  cases_by_status: { key: string; total: number }[];
  average_case_duration_days: number | null;
  sessions_this_week: number;
}

export interface BkSupportProfile {
  student?: (BkStudentRef & { class_name?: string | null; is_active?: boolean }) | null;
  academic: Record<string, unknown>;
  attendance: Record<string, unknown>;
  discipline: Record<string, unknown>;
  boarding?: Record<string, unknown> | null;
  health?: Record<string, unknown> | null;
  development: { label: string; detail?: string | null }[];
  cases: Record<string, unknown>[];
  active_case_count: number;
  interventions: Record<string, unknown>[];
  follow_ups: Record<string, unknown>[];
  appointments: Record<string, unknown>[];
  support_requests: Record<string, unknown>[];
  timeline: { case_number: string; event: string; event_label: string; summary: string; actor?: string | null; occurred_at?: string | null }[];
  visibility: { cases: boolean; counseling: boolean; note: string };
}
