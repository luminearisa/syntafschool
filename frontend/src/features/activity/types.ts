/**
 * Tipe Modul Student Activity & Extracurricular.
 *
 * Bentuknya mengikuti respons API `/api/v1/activities/*`: ringkas untuk daftar,
 * lengkap pada detail. Nilai enum dikirim sebagai string UPPER_SNAKE_CASE
 * beserta label bahasa Indonesia dari backend.
 */

export type ActivityProgramType =
  | 'EXTRACURRICULAR' | 'CLUB' | 'ORGANIZATION' | 'SPORT' | 'ART' | 'ACADEMIC'
  | 'RELIGIOUS' | 'LEADERSHIP' | 'COMMUNITY_SERVICE' | 'COMPETITION' | 'OTHER';

export type ActivityStatus = 'DRAFT' | 'PUBLISHED' | 'ACTIVE' | 'CLOSED' | 'ARCHIVED';
export type RegistrationStatus =
  | 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'SELECTED' | 'WAITLISTED' | 'APPROVED' | 'REJECTED' | 'CANCELLED';
export type MembershipStatus = 'ACTIVE' | 'SUSPENDED' | 'INACTIVE' | 'COMPLETED' | 'WITHDRAWN';
export type AttendanceStatus = 'PRESENT' | 'ABSENT' | 'EXCUSED' | 'LATE' | 'SICK' | 'PERMISSION';
export type SessionStatus = 'SCHEDULED' | 'OPEN' | 'COMPLETED' | 'CANCELLED' | 'POSTPONED';

export interface ActivityCategoryNode {
  id: number;
  code: string;
  name: string;
  description?: string | null;
  icon?: string | null;
  sort_order: number;
  children: ActivityCategoryNode[];
}

export interface ActivityProgramRow {
  id: number;
  code: string;
  name: string;
  type?: ActivityProgramType;
  delivery_type?: string;
  description?: string | null;
  status: ActivityStatus;
  category?: { id: number; name: string; code?: string } | null;
  period?: { id: number; name: string } | null;
  coach?: { id: number; full_name: string } | null;
  advisor?: { id: number; full_name: string } | null;
  minimum_capacity?: number | null;
  maximum_capacity?: number | null;
  selection_method?: string;
  requires_parent_consent?: boolean;
  requires_payment?: boolean;
  fee_amount?: string | number | null;
  registration_opens?: string | null;
  registration_closes?: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
  published_at?: string | null;
  skills?: { id: number; name: string; code?: string }[];
  objectives?: { id: number; name: string; code?: string }[];
  requirements?: ActivityRequirement[];
  schedules?: ActivityScheduleRow[];
  room?: { id: number; name: string } | null;
}

export interface ActivityRequirement {
  id: number;
  type: string;
  operator: string;
  value?: unknown;
  is_mandatory: boolean;
  message?: string | null;
}

export interface ActivityScheduleRow {
  id: number;
  day_of_week: number;
  start_time: string;
  end_time: string;
  location?: string | null;
  room?: { id: number; name: string } | null;
  session_type?: string;
  status: string;
}

export interface ActivityEligibilityResult {
  passed: boolean;
  failures: { code: string; message: string; details?: unknown }[];
  warnings: { code: string; message: string; details?: unknown }[];
  checks: { code: string; message: string }[];
  capacity: ActivityCapacity;
  conflicts: { type: string; message: string }[];
}

export interface ActivityCapacity {
  active: number;
  waitlisted: number;
  maximum: number | null;
  minimum: number | null;
  is_full: boolean;
  seats_left: number | null;
}

export interface ActivityRegistrationRow {
  id: number;
  code: string;
  status: RegistrationStatus;
  status_label?: string;
  program_id: number;
  program?: { id: number; name: string; code?: string } | null;
  period?: { id: number; name: string } | null;
  student_id: number;
  student?: { id: number; full_name: string; nis?: string } | null;
  motivation?: string | null;
  decision_notes?: string | null;
  waitlist_position?: number | null;
  submitted_at?: string | null;
  decided_at?: string | null;
  validation_result?: {
    failures?: { code: string; message: string }[];
    warnings?: { code: string; message: string }[];
    conflicts?: { type: string; message: string }[];
  } | null;
}

export interface ActivityMembershipRow {
  id: number;
  program_id: number;
  program?: { id: number; name: string; code?: string } | null;
  period_id?: number | null;
  period?: { id: number; name: string } | null;
  student_id: number;
  student?: { id: number; full_name: string; nis?: string } | null;
  role: string;
  role_label?: string;
  status: MembershipStatus;
  status_label?: string;
  joined_at?: string | null;
  ended_at?: string | null;
  progress?: ActivityProgressRow | null;
}

/**
 * Ringkasan keanggotaan untuk portal siswa/orang tua.
 *
 * Bentuknya lebih ringkas daripada `ActivityMembershipRow` karena memang
 * berasal dari endpoint portal (`students/{id}/memberships`) — bukan tabel
 * keanggotaan dengan seluruh kolomnya.
 */
export interface ActivityMembershipSummary {
  id: number;
  program_id: number;
  program?: string | null;
  period?: string | null;
  role: string;
  role_label?: string | null;
  status: MembershipStatus;
  status_label?: string | null;
  joined_at?: string | null;
  attendance_percentage?: number | null;
  average_score?: number | null;
}

export interface ActivityProgressRow {
  id: number;
  status: string;
  sessions_total: number;
  sessions_attended: number;
  attendance_percentage: string | number | null;
  assessments_total: number;
  assessments_completed: number;
  average_score: string | number | null;
  skills?: Record<string, { level?: string | null; score?: number | null; evidence_count?: number }> | null;
}

export interface ActivitySessionRow {
  id: number;
  program_id: number;
  program_name?: string | null;
  session_number: number;
  date: string;
  start_time: string;
  end_time: string;
  room?: string | null;
  coach?: string | null;
  topic?: string | null;
  status: SessionStatus;
  status_label?: string;
  attendance_count?: number;
  attendance_locked?: boolean;
}

export interface ActivityAttendanceSheet {
  session: {
    student_id: number;
    membership_id: number;
    name?: string | null;
    nis?: string | null;
    status?: AttendanceStatus | null;
    status_label?: string | null;
    minutes_late?: number | null;
    reason?: string | null;
  }[];
  meta: { session_id: number; program_id: number; date: string; locked: boolean };
}

export interface ActivityAttendanceSummary {
  student_id: number;
  program_id: number;
  sessions_held: number;
  sessions_recorded: number;
  present: number;
  excused: number;
  late: number;
  absent: number;
  percentage: number | null;
  trend?: { date: string; status: AttendanceStatus }[];
}

export interface ActivityAssessmentRow {
  id: number;
  program_id: number;
  title: string;
  type: string;
  max_score: string | number;
  is_final: boolean;
  status: string;
  rubric?: { id: number; title: string } | null;
  session_id?: number | null;
}

export interface ActivityAssessmentResultRow {
  student_id: number;
  student?: string | null;
  score: number | null;
  level?: string | null;
  level_label?: string | null;
  feedback?: string | null;
  assessed_by?: string | null;
  assessed_at?: string | null;
}

export interface ActivityCompletionEvaluation {
  eligible: boolean;
  blockers: string[];
  snapshot: {
    rules: Record<string, unknown>;
    attendance_percentage: number;
    sessions_attended: number;
    assessments_completed: number;
    average_score: number | null;
    final_score: number | null;
  };
}

export interface ActivityCertificateRow {
  id: number;
  certificate_number: string;
  verification_code: string;
  type: string;
  type_label?: string;
  title: string;
  student?: { id: number; full_name: string } | null;
  program?: { id: number; name: string } | null;
  issued_date: string;
  status: string;
  status_label?: string;
}

export interface ActivityCertificateVerification {
  result: string;
  result_label: string;
  valid: boolean;
  certificate: {
    number: string;
    type?: string;
    type_label?: string;
    title: string;
    program?: string | null;
    issued_date?: string | null;
    status?: string | null;
  } | null;
  verified_at: string;
}

export interface ActivityAchievementRow {
  id: number;
  title: string;
  type?: string;
  type_label?: string;
  level?: string;
  level_label?: string;
  rank?: string | null;
  rank_label?: string | null;
  ownership?: 'INDIVIDUAL' | 'TEAM';
  awarded_date?: string | null;
  organizer?: string | null;
  program?: { id: number; name: string } | null;
  competition?: { id: number; name: string } | null;
  verification_status?: string;
  verification_label?: string;
  team_members?: number;
}

export interface ActivityCompetitionRow {
  id: number;
  code: string;
  name: string;
  level?: string;
  participant_type?: string;
  organizer?: string | null;
  location?: string | null;
  status: string;
  status_label?: string;
  registration_opens?: string | null;
  registration_closes?: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
  program?: { id: number; name: string } | null;
  categories?: { id: number; name: string }[];
  participants_count?: number;
  teams_count?: number;
  matches_count?: number;
}

export interface ActivityCompetitionParticipantRow {
  id: number;
  student_id: number;
  category_id?: number | null;
  student?: { id: number; full_name: string; nis?: string | null } | null;
  status: string;
  status_label?: string;
}

export interface ActivityCompetitionTeamRow {
  id: number;
  name: string;
  category_id?: number | null;
  status?: string;
  members?: {
    id?: number;
    student_id: number;
    role?: string | null;
    receives_achievement?: boolean;
    student?: { id: number; full_name: string } | null;
  }[];
}

export interface ActivityCompetitionMatchRow {
  id: number;
  match_number?: number | null;
  status: string;
  status_label?: string;
  score_a?: string | number | null;
  score_b?: string | number | null;
  winner_participant_id?: number | null;
  scheduled_at?: string | null;
  venue?: string | null;
  participant_a?: { id: number; student?: { id: number; full_name: string } | null } | null;
  participant_b?: { id: number; student?: { id: number; full_name: string } | null } | null;
  scores?: {
    id: number;
    judge_id: number;
    participant_id: number;
    total: string | number | null;
    status: string;
    correction_reason?: string | null;
  }[];
}

export interface ActivityCompetitionRoundRow {
  id: number;
  name?: string | null;
  type: string;
  type_label?: string;
  scheduled_date?: string | null;
  status?: string;
  matches?: ActivityCompetitionMatchRow[];
}

export interface ActivityCompetitionJudgeRow {
  id: number;
  user_id?: number | null;
  employee_id?: number | null;
  round_id?: number | null;
  category_id?: number | null;
  name?: string | null;
  user?: { id: number; name: string } | null;
  status?: string;
}

export interface ActivityCompetitionResultRow {
  id: number;
  rank: number;
  participant_id?: number | null;
  team_id?: number | null;
  total_score?: string | number | null;
  status?: string;
  status_label?: string;
  achievement_id?: number | null;
  participant?: { id: number; student?: { id: number; full_name: string } | null } | null;
  team?: { id: number; name: string } | null;
}

export interface ActivityCompetitionDetail extends ActivityCompetitionRow {
  categories: { id: number; name: string }[];
  participants: ActivityCompetitionParticipantRow[];
  teams: ActivityCompetitionTeamRow[];
  rounds: ActivityCompetitionRoundRow[];
  judges: ActivityCompetitionJudgeRow[];
  results: ActivityCompetitionResultRow[];
}

export interface ActivityJudgeAssignmentRow {
  match_id: number;
  round?: string | null;
  competition?: string | null;
  scheduled_at?: string | null;
  participant_a?: string | null;
  participant_b?: string | null;
  locked: boolean;
}

export interface ActivityPortfolio {
  student: { id: number; name: string; nis?: string | null; class_name?: string | null } | null;
  period_id?: number | null;
  generated_at: string;
  summary: {
    activities: number;
    active_activities: number;
    skills: number;
    achievements: number;
    verified_achievements: number;
    certificates: number;
    leadership_roles: number;
    projects: number;
    average_attendance: number | null;
  };
  activities: {
    membership_id: number;
    program?: string | null;
    period?: string | null;
    role_label?: string | null;
    status_label?: string | null;
    joined_at?: string | null;
    attendance_percentage?: number | null;
    average_score?: number | null;
    completed: boolean;
  }[];
  skills: { skill: string; level?: string | null; score?: number | null; evidence_count: number; sources?: string[] }[];
  projects: { id: number; title: string; status?: string | null; completed_on?: string | null }[];
  leadership: { program?: string | null; role?: string | null; period?: string | null }[];
  community_service: { program?: string | null; joined_at?: string | null }[];
  achievements: ActivityAchievementRow[];
  certificates: { id: number; number: string; title: string; type?: string; issued_date?: string; program?: string | null }[];
  coach_feedback: unknown[];
}

export interface ActivityTranscript {
  student: { id: number; name: string; nis?: string | null } | null;
  generated_at: string;
  rows: {
    period?: string | null;
    activity?: string | null;
    code?: string | null;
    role?: string | null;
    status?: string | null;
    joined_at?: string | null;
    ended_at?: string | null;
    attendance_percentage?: number | null;
    average_score?: number | null;
    achievements: number;
    certificate?: string | null;
  }[];
  totals: {
    activities: number;
    completed: number;
    average_attendance: number | null;
    average_score: number | null;
  };
}

export interface ActivityDashboard {
  admin?: {
    programs: { total: number; active: number; draft: number };
    students: { active_members: number; memberships: number; average_activities_per_student: number };
    registrations: { total: number; pending: number; waitlisted: number };
    attendance: { records: number; present: number; percentage: number | null };
    today_sessions: number;
    upcoming_sessions: number;
    competitions: { ongoing: number; upcoming: number };
    achievements: { total: number; verified: number; this_year: number };
    certificates: { issued: number; revoked: number };
    popular_activities: { program_id: number; program?: string | null; members: number }[];
    capacity_alerts: {
      program_id: number;
      activity?: string | null;
      capacity: number;
      active: number;
      waitlist: number;
      seats_left: number;
      fill_rate: number;
    }[];
  } | null;
  coach?: {
    programs: { id: number; name: string; members: number; sessions: number; assessments: number }[];
    today_sessions: {
      id: number;
      program?: string | null;
      start_time: string;
      end_time: string;
      topic?: string | null;
      status?: string | null;
      attendance_filled: number;
    }[];
    upcoming_sessions: { id: number; program?: string | null; date: string; start_time: string }[];
    pending_assessments: number;
    pending_registrations?: number;
    attendance_completion?: number | null;
    message?: string;
  };
  student?: {
    my_activities: { id: number; program?: string | null; role?: string | null; attendance_percentage?: number | null; average_score?: number | null }[];
    today_schedule: { id: number; program?: string | null; start_time: string; end_time: string; topic?: string | null }[];
    upcoming_sessions: { program?: string | null; date: string; start_time: string }[];
    achievements: number;
    certificates: number;
    completed_activities: number;
  };
  parent?: {
    children: {
      student_id: number;
      pending_consents: number;
      my_activities: { id: number; program?: string | null; role?: string | null }[];
      achievements: number;
      certificates: number;
    }[];
  };
}

export interface ActivityMeta {
  student_id: number | null;
  employee_id: number | null;
  child_student_ids: number[];
  coached_program_ids: number[];
  pending_registration_decisions: number;
  permissions: string[];
}

export interface ActivityAuditRow {
  id: number;
  action: string;
  action_label?: string;
  subject_type?: string | null;
  subject_id?: number | null;
  actor_user_id?: number | null;
  actor?: { id: number; name: string } | null;
  actor_role?: string | null;
  program_id?: number | null;
  student_id?: number | null;
  before?: Record<string, unknown> | null;
  after?: Record<string, unknown> | null;
  metadata?: Record<string, unknown> | null;
  created_at?: string | null;
}

export interface ActivityChargeRow {
  id: number;
  program?: { id: number; name: string } | null;
  student?: { id: number; full_name: string } | null;
  type?: string;
  description?: string | null;
  amount?: string | number | null;
  currency?: string | null;
  status: string;
  external_reference?: string | null;
  requested_at?: string | null;
}

export interface ActivityEquipmentRequestRow {
  id: number;
  program?: { id: number; name: string } | null;
  student?: { id: number; full_name: string } | null;
  description?: string | null;
  quantity?: number | null;
  issued_quantity?: number | null;
  returned_quantity?: number | null;
  status: string;
  due_on?: string | null;
  issued_at?: string | null;
  returned_at?: string | null;
  notes?: string | null;
}

export interface ActivityFacilityBookingRow {
  id: number;
  program?: { id: number; name: string } | null;
  schedule?: { id: number; day_of_week: number; start_time: string; end_time: string } | null;
  room_id?: number | null;
  date?: string | null;
  start_time?: string | null;
  end_time?: string | null;
  status: string;
  external_reference?: string | null;
  conflict_reason?: string | null;
}

export interface ActivityConsentRow {
  id: number;
  registration_code?: string | null;
  program_id: number;
  program_name?: string | null;
  student_id: number;
  student_name?: string | null;
  scope: string;
  scope_label?: string;
  decision: string;
  decision_label?: string;
  notes?: string | null;
  requested_at?: string | null;
  decided_at?: string | null;
  expires_at?: string | null;
  can_decide: boolean;
}

export interface ActivityHealth {
  data: Record<string, number>;
  integrations: Record<string, unknown>;
  recent_integration_calls: {
    id: number;
    integration?: string;
    action: string;
    status?: string;
    duration_ms?: number | null;
    error?: string | null;
    created_at?: string | null;
  }[];
  audit: { total: number; last_24h: number; by_action: Record<string, number> };
}
