import type { PaginationMeta } from './index';

/* ============ referensi kecil (nested di resource) ============ */

export interface BoardingStudentRef {
  id: number;
  full_name: string | null;
  nis: string | null;
}

export interface BoardingDormitoryRef {
  id: number;
  code: string;
  name: string;
}

/* ============ struktur asrama ============ */

export type BoardingGender = 'male' | 'female' | (string & {});
export type BoardingRoomType = 'regular' | 'isolation' | (string & {});

export interface BoardingDormitory {
  id: number;
  code: string;
  name: string;
  gender: BoardingGender;
  gender_label: string;
  capacity: number | null;
  location: string | null;
  description: string | null;
  status: string;
  status_label: string;
  is_active: boolean;
  buildings_count: number;
  rooms_count: number;
  current_occupancy: number;
}

export interface BoardingBuilding {
  id: number;
  dormitory_id: number;
  dormitory?: BoardingDormitoryRef | null;
  code: string;
  name: string;
  gender: BoardingGender;
  floor_count: number;
  floors_count: number;
  current_occupancy: number;
  description: string | null;
  status: string;
  status_label: string;
}

export interface BoardingFloor {
  id: number;
  building_id: number;
  building?: { id: number; code: string; name: string; dormitory_id: number } | null;
  dormitory_id?: number;
  floor_number: number;
  name: string | null;
  display_name: string;
  description: string | null;
  rooms_count: number;
  current_occupancy: number;
}

export interface BoardingRoom {
  id: number;
  code: string;
  name: string | null;
  floor_id: number;
  floor?: { id: number; floor_number: number; name: string | null; display_name: string } | null;
  building_id: number;
  dormitory_id: number;
  dormitory?: BoardingDormitoryRef | null;
  gender: BoardingGender;
  gender_label: string;
  capacity: number;
  room_type: BoardingRoomType;
  room_type_label: string;
  is_isolation: boolean;
  status: string;
  status_label: string;
  description: string | null;
  current_occupancy: number;
  available_beds: number;
  beds_count: number;
  remaining_capacity: number;
}

export interface BoardingBed {
  id: number;
  room_id: number;
  room?: { id: number; code: string; name: string | null } | null;
  bed_number: string;
  label: string | null;
  display_name: string;
  status: string;
  status_label: string;
  notes: string | null;
  active_placement?: {
    id: number;
    student_id: number;
    start_date: string;
    student?: BoardingStudentRef | null;
  } | null;
}

/* ============ penempatan ============ */

export interface BoardingPlacement {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  dormitory_id: number;
  dormitory?: BoardingDormitoryRef | null;
  building_id: number | null;
  building?: { id: number; code: string; name: string } | null;
  floor_id: number | null;
  floor?: { id: number; floor_number: number; display_name: string } | null;
  room_id: number;
  room?: { id: number; code: string; name: string | null; capacity: number; gender: BoardingGender } | null;
  bed_id: number | null;
  bed?: { id: number; bed_number: string; label: string | null } | null;
  start_date: string;
  end_date: string | null;
  status: 'active' | 'inactive' | (string & {});
  status_label: string;
  is_active: boolean;
  end_reason: string | null;
  end_reason_label: string | null;
  replaced_by_placement_id: number | null;
  notes: string | null;
  assigned_by?: string | null;
  created_at?: string;
}

export interface BoardingRoomOccupancy {
  room?: BoardingRoom;
  beds?: Array<{
    bed: BoardingBed;
    placement?: BoardingPlacement | null;
  }> | BoardingBed[];
  occupants?: Array<Record<string, unknown>>;
  [key: string]: unknown;
}

/* ============ pengasuh & kelompok ============ */

export interface BoardingMusyrif {
  id: number;
  employee_id: number;
  employee?: { id: number; full_name: string | null; nip: string | null } | null;
  dormitory_id: number | null;
  dormitory?: BoardingDormitoryRef | null;
  building_id: number | null;
  building?: { id: number; code: string; name: string } | null;
  room_id: number | null;
  room?: { id: number; code: string; name: string | null } | null;
  area_label: string | null;
  position: string;
  position_label: string;
  start_date: string;
  end_date: string | null;
  status: string;
  status_label: string;
  notes: string | null;
  assigned_by?: string | null;
}

export interface BoardingGroup {
  id: number;
  code: string;
  name: string;
  musyrif_employee_id: number | null;
  musyrif?: { id: number; full_name: string | null; nip: string | null } | null;
  dormitory_id: number | null;
  dormitory?: BoardingDormitoryRef | null;
  description: string | null;
  status: string;
  status_label: string;
  members_count: number;
}

export interface BoardingGroupMember {
  id: number;
  group_id: number;
  group?: { id: number; code: string; name: string } | null;
  student_id: number;
  student?: BoardingStudentRef | null;
  joined_date: string | null;
  left_date: string | null;
  is_current: boolean;
}

/* ============ kegiatan, tipe absensi, jadwal ============ */

export interface BoardingAttendanceType {
  id: number;
  code: string;
  name: string;
  category: string;
  category_label: string;
  is_present: boolean;
  is_excused: boolean;
  requires_note: boolean;
  requires_approval: boolean;
  color: string | null;
  is_active: boolean;
}

export interface BoardingActivity {
  id: number;
  code: string;
  name: string;
  category: string;
  category_label: string;
  default_attendance_type_id: number | null;
  default_attendance_type?: { id: number; code: string; name: string } | null;
  late_after_minutes: number | null;
  is_required: boolean;
  is_active: boolean;
  description: string | null;
  attendance_types?: BoardingAttendanceType[];
  schedules_count: number;
}

export interface BoardingActivitySchedule {
  id: number;
  activity_id: number;
  activity?: { id: number; code: string; name: string } | null;
  dormitory_id: number | null;
  dormitory?: BoardingDormitoryRef | null;
  group_id: number | null;
  group?: { id: number; code: string; name: string } | null;
  day_of_week: number;
  day_label: string;
  start_time: string;
  end_time: string;
  late_cutoff: string | null;
  effective_start: string | null;
  effective_end: string | null;
  is_active: boolean;
  notes: string | null;
}

export interface BoardingWeeklyDay {
  day: number;
  day_label: string;
  schedules: BoardingActivitySchedule[];
}

/* ============ absensi ============ */

export interface BoardingAttendance {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  activity_id: number | null;
  activity?: { id: number; code: string; name: string; category: string } | null;
  schedule_id: number | null;
  attendance_type_id: number;
  attendance_type?: { id: number; code: string; name: string; category: string; color: string | null } | null;
  is_present: boolean;
  date: string;
  session: string | null;
  recorded_at: string | null;
  is_late: boolean;
  late_minutes: number | null;
  source: string;
  source_label: string;
  device: string | null;
  note: string | null;
  recorded_by?: string | null;
  revision?: number;
  revised_at: string | null;
  revised_by?: string | null;
}

export interface BoardingAttendanceRoster {
  activity: { id: number; code: string; name: string } | null;
  date: string;
  session: string | null;
  schedule: BoardingActivitySchedule | null;
  scope: Record<string, unknown>;
  restricted: boolean;
  total: number;
  attendance_types: BoardingAttendanceType[];
  summary: {
    total: number;
    recorded: number;
    pending: number;
    present: number;
    late: number;
    unrecorded: number;
  };
  students: Array<{
    student_id: number;
    student?: BoardingStudentRef | null;
    full_name?: string | null;
    nis?: string | null;
    attendance?: BoardingAttendance | null;
    attendance_type_id?: number | null;
    note?: string | null;
    [key: string]: unknown;
  }>;
}

export interface BoardingAttendanceRecap {
  from: string;
  to: string;
  activity: { id: number; name: string } | null;
  filters: Record<string, unknown>;
  attendance_types: Array<{
    id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    is_present: boolean;
    color: string | null;
  }>;
  totals: Record<string, number>;
  students: Array<{
    student?: BoardingStudentRef | null;
    placement?: Record<string, unknown> | null;
    total: number;
    present: number;
    late: number;
    excused: number;
    unexcused: number;
    not_present: number;
    types: Record<string, number>;
    corrected: number;
    first_date: string | null;
    last_date: string | null;
  }>;
}

/* Pantauan kelengkapan pengisian absensi per asrama (satu tanggal). */
export interface BoardingAttendanceMonitoring {
  date: string;
  month: string;
  activities: Array<{ id: number; name: string }>;
  dormitories: Array<{
    id: number;
    code: string;
    name: string;
    status: string;
    supervisors: string[];
    targets: number;
    daily_records: number;
    activities_total: number;
    filled_activities: Array<{ id: number; name: string; targets: number; recorded: number }>;
    partial_activities: Array<{ id: number; name: string; targets: number; recorded: number }>;
    unfilled_activities: Array<{ id: number; name: string; targets: number; recorded: number }>;
    monthly_percentage: number | null;
  }>;
}

/* Agregat absensi untuk grafik dasbor. */
export interface BoardingAttendanceStatistics {
  period: { from: string; to: string };
  summary: {
    records: number;
    present: number;
    late: number;
    not_present: number;
    present_percent: number;
  };
  status_distribution: Array<{
    type_id: number;
    code: string;
    name: string;
    color: string;
    is_present: boolean;
    count: number;
  }>;
  daily: Array<{
    date: string;
    total: number;
    present: number;
    late: number;
    not_present: number;
  }>;
  by_dormitory: Array<{
    dormitory_id: number;
    name: string;
    total: number;
    present: number;
    late: number;
    not_present: number;
    present_percent: number;
  }>;
  top_absentees: Array<{
    student_id: number;
    full_name: string;
    nis: string;
    dormitory: string;
    room: string;
    absent_count: number;
  }>;
}

/* ============ izin keluar ============ */

export interface BoardingPermissionApproval {
  id: number;
  permission_id: number;
  step: number;
  action: string;
  action_label: string;
  acted_by?: string | null;
  acted_by_name: string | null;
  acted_role: string | null;
  note: string | null;
  acted_at: string | null;
}

export interface BoardingPermission {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  permission_type: string;
  permission_type_label: string;
  destination: string | null;
  reason: string | null;
  start_at: string;
  expected_return_at: string;
  actual_return_at: string | null;
  checked_out_at: string | null;
  checked_in_at: string | null;
  late_return: boolean;
  status: string;
  status_label: string;
  is_active: boolean;
  is_out: boolean;
  is_overdue: boolean;
  current_step: number;
  required_steps: number;
  remaining_steps: number;
  can_submit: boolean;
  can_approve: boolean;
  can_reject: boolean;
  can_check_out: boolean;
  can_check_in: boolean;
  can_cancel: boolean;
  requested_by_name: string | null;
  approvals: BoardingPermissionApproval[];
  attachment_path: string | null;
  notes: string | null;
}

/* ============ kepulangan & kunjungan ============ */

export interface BoardingHomecoming {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  type: string;
  type_label: string;
  home_date: string;
  return_date: string;
  actual_return_date: string | null;
  late_return: boolean;
  is_late_return: boolean;
  transport: string | null;
  picked_up_by: string | null;
  returned_with: string | null;
  status: string;
  status_label: string;
  is_active: boolean;
  is_home: boolean;
  is_overdue: boolean;
  can_submit: boolean;
  can_approve: boolean;
  can_reject: boolean;
  can_check_out: boolean;
  can_check_in: boolean;
  can_cancel: boolean;
  requested_by_name: string | null;
  approved_by_name: string | null;
  approved_at: string | null;
  checked_out_by_name: string | null;
  checked_in_by_name: string | null;
  notes: string | null;
}

export interface BoardingVisitor {
  id: number;
  visitor_name: string;
  identity_number: string | null;
  phone: string | null;
  relationship: string | null;
  student_id: number | null;
  student?: BoardingStudentRef | null;
  visit_date: string;
  purpose: string | null;
  check_in_at: string | null;
  check_out_at: string | null;
  duration_minutes: number | null;
  status: string;
  status_label: string;
  is_present: boolean;
  can_check_in: boolean;
  can_check_out: boolean;
  can_cancel: boolean;
  recorded_by_name: string | null;
  approved_by_name: string | null;
  notes: string | null;
}

/* ============ kedisiplinan ============ */

export interface BoardingViolationType {
  id: number;
  code: string;
  name: string;
  description: string | null;
  category: string;
  category_label: string;
  severity: string;
  severity_label: string;
  point: number;
  is_active: boolean;
  violations_count?: number;
  options?: { categories: Record<string, string>; severities: Record<string, string> };
}

export interface BoardingViolation {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  violation_type_id: number;
  type?: {
    id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    severity: string;
    severity_label: string;
    master_point?: number;
  } | null;
  type_name: string | null;
  category: string | null;
  severity: string | null;
  date: string;
  narrative: string | null;
  points: number;
  evidence_path: string | null;
  status: string;
  status_label: string;
  is_open: boolean;
  can_mark_actioned: boolean;
  can_close: boolean;
  recorded_by_name: string | null;
  disciplinary_actions_count: number;
}

export interface BoardingDisciplinaryAction {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  violation_id: number | null;
  violation?: { id: number; date: string; points: number } | null;
  is_auto: boolean;
  action: string;
  action_label: string;
  date: string;
  officer: string | null;
  status: string;
  status_label: string;
  notes: string | null;
  is_pending: boolean;
  can_complete: boolean;
  can_cancel: boolean;
  recorded_by_name: string | null;
}

export interface BoardingPointsSummary {
  student_id: number;
  point_label: string;
  total_points: number;
  violation_count: number;
  open_count: number;
  by_category: Array<{ category: string; category_label: string; points: number; count: number; share: number }>;
  thresholds: Array<{
    key: string;
    label: string;
    threshold: number;
    reached: boolean;
    action?: string;
  }>;
  next_threshold: { key: string; label: string; threshold: number; remaining: number } | null;
  recent: Array<{
    id: number;
    date: string;
    points: number;
    type_name: string | null;
    category: string | null;
    status: string;
  }>;
}

/* ============ prestasi & kesehatan ============ */

export interface BoardingAchievement {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  title: string;
  description: string | null;
  category: string;
  category_label: string;
  level: string | null;
  level_label: string | null;
  date: string;
  evidence_path: string | null;
  recorded_by_name: string | null;
}

export interface BoardingAchievementOptions {
  categories: Record<string, string>;
  levels: Record<string, string>;
}

export interface BoardingAchievementSummary {
  total: number;
  by_category: Record<string, number>;
  by_level: Record<string, number>;
  [key: string]: unknown;
}

export interface BoardingHealthRecord {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  condition: string | null;
  complaint: string | null;
  action: string | null;
  date: string;
  status: string;
  status_label: string;
  is_open: boolean;
  allowed_next_statuses: string[];
  recorded_by_name: string | null;
  notes: string | null;
}

/* ============ monitoring & dashboard ============ */

export type BoardingStudentStatus =
  | 'in_dorm'
  | 'out_with_permission'
  | 'home'
  | 'sick'
  | 'isolation'
  | 'absent'
  | 'unknown';

export interface BoardingStudentSummary {
  student_id: number;
  status: BoardingStudentStatus;
  status_label: string;
  in_boarding: boolean;
  dormitory_id: number | null;
  dormitory_code: string | null;
  dormitory_name: string | null;
  building_name: string | null;
  floor_name: string | null;
  room_id: number | null;
  room_code: string | null;
  room_name: string | null;
  room_type: string | null;
  bed_number: string | null;
  musyrif_name: string | null;
  full_name?: string | null;
  nis?: string | null;
}

export interface BoardingMonitoringOverview {
  counts: Record<BoardingStudentStatus, number>;
  total: number;
  page: number;
  per_page: number;
  rows: BoardingStudentSummary[];
  status_options: Record<string, string>;
}

export interface BoardingDashboard {
  generated_at: string;
  point_label: string;
  students: {
    in_boarding: number;
    status_counts: Record<BoardingStudentStatus, number>;
    out_now: number;
  };
  beds: { total: number; occupied: number; available: number; maintenance: number };
  attendance: {
    date: string;
    by_type: Record<string, number>;
    total: number;
    late: number;
  };
  discipline: {
    violations_month: number;
    points_month: number;
    violations_open: number;
    actions_pending: number;
  };
  permissions: { awaiting_approval: number; out: number; late_returns_month: number };
  homecomings: { awaiting_approval: number };
  visitors: { present: number };
  health: { open_records: number; in_isolation: number };
  achievements: { this_month: number };
}

/* ============ laporan, audit, pengaturan ============ */

export interface BoardingReportKind {
  kind: string;
  label: string;
}

export interface BoardingReport {
  kind: string;
  label: string;
  headers: Record<string, string>;
  total: number;
  rows: Array<Record<string, string | number | boolean | null>>;
}

export interface BoardingAuditEntry {
  id: number;
  event: string;
  description: string | null;
  subject_type: string | null;
  subject_id: number | null;
  causer: string | null;
  causer_id: number | null;
  properties: Record<string, unknown> | null;
  created_at: string;
}

export interface BoardingSettings {
  point_label: string;
  point_threshold_warning: number;
  point_threshold_coaching: number;
  point_threshold_parent_notification: number;
  permission_approval_steps: number;
  permission_checkin_grace_minutes: number;
  rfid_attendance_enabled: boolean;
  [key: string]: unknown;
}

/* ============ portal orang tua ============ */

export interface BoardingPortalChild {
  student_id: number;
  full_name: string | null;
  nis: string | null;
  in_boarding: boolean;
  status: BoardingStudentStatus;
  status_label: string;
  dormitory_name: string | null;
  room_code: string | null;
  bed_number: string | null;
  musyrif_name: string | null;
}

export interface BoardingPortalSummary {
  placement: BoardingStudentSummary;
  points: Pick<
    BoardingPointsSummary,
    'point_label' | 'total_points' | 'violation_count' | 'open_count' | 'by_category' | 'next_threshold'
  >;
  counts: {
    achievements: number;
    health_open: number;
    permissions: number;
    homecomings: number;
    visitors: number;
  };
  recent_violations: BoardingPointsSummary['recent'];
  unread_notifications: number;
}

/* ============ payload & query ============ */

export type BoardingId = number | '';

export interface BoardingListParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: 1;
  filter?: Record<string, string | number | boolean | undefined>;
}

export interface BoardingListResult<T> {
  data: T[];
  meta?: PaginationMeta;
}

export interface BoardingDormitoryPayload {
  code: string;
  name: string;
  gender: BoardingGender | '';
  capacity?: number | '';
  location?: string;
  description?: string;
  is_active?: boolean;
}

export interface BoardingBuildingPayload {
  dormitory_id: BoardingId;
  code: string;
  name: string;
  floor_count?: number | '';
  description?: string;
  is_active?: boolean;
}

export interface BoardingFloorPayload {
  building_id: BoardingId;
  floor_number: number | '';
  name?: string;
  description?: string;
}

export interface BoardingRoomPayload {
  floor_id: BoardingId;
  code: string;
  name?: string;
  capacity: number | '';
  room_type?: BoardingRoomType | '';
  description?: string;
  is_active?: boolean;
}

export interface BoardingBedPayload {
  room_id: BoardingId;
  bed_number: string;
  label?: string;
  status?: string;
  notes?: string;
}

export interface BoardingBedBulkPayload {
  room_id: BoardingId;
  count: number | '';
  prefix?: string;
}

export interface BoardingPlacementPayload {
  student_id: BoardingId;
  room_id: BoardingId;
  bed_id?: BoardingId;
  start_date?: string;
  notes?: string;
}

export interface BoardingTransferPayload {
  room_id: BoardingId;
  bed_id?: BoardingId;
  reason?: string;
  notes?: string;
}

export interface BoardingMusyrifPayload {
  employee_id: BoardingId;
  position: string;
  dormitory_id?: BoardingId;
  building_id?: BoardingId;
  room_id?: BoardingId;
  start_date: string;
  end_date?: string;
  status?: string;
  notes?: string;
}

export interface BoardingGroupPayload {
  code: string;
  name: string;
  musyrif_employee_id?: BoardingId;
  dormitory_id?: BoardingId;
  description?: string;
  status?: string;
}

export interface BoardingAttendanceTypePayload {
  code: string;
  name: string;
  category: string;
  is_present?: boolean;
  is_excused?: boolean;
  requires_note?: boolean;
  requires_approval?: boolean;
  color?: string;
  is_active?: boolean;
}

export interface BoardingActivityPayload {
  code: string;
  name: string;
  category: string;
  default_attendance_type_id?: BoardingId;
  late_after_minutes?: number | '';
  is_required?: boolean;
  is_active?: boolean;
  description?: string;
}

export interface BoardingSchedulePayload {
  activity_id: BoardingId;
  day_of_week: number | '';
  start_time: string;
  end_time: string;
  late_cutoff?: string;
  dormitory_id?: BoardingId;
  group_id?: BoardingId;
  effective_start?: string;
  effective_end?: string;
  is_active?: boolean;
  notes?: string;
}

export interface BoardingAttendancePayload {
  student_id: BoardingId;
  attendance_type_id: BoardingId;
  activity_id?: BoardingId;
  schedule_id?: BoardingId;
  date?: string;
  session?: string;
  note?: string;
}

export interface BoardingPermissionPayload {
  student_id: BoardingId;
  permission_type: string;
  destination?: string;
  reason: string;
  start_at: string;
  expected_return_at: string;
  notes?: string;
}

export interface BoardingHomecomingPayload {
  student_id: BoardingId;
  type: string;
  home_date: string;
  return_date: string;
  transport?: string;
  picked_up_by?: string;
  notes?: string;
}

export interface BoardingVisitorPayload {
  visitor_name: string;
  identity_number?: string;
  phone?: string;
  relationship?: string;
  student_id?: BoardingId;
  purpose?: string;
  visit_date?: string;
  notes?: string;
}

export interface BoardingViolationTypePayload {
  code: string;
  name: string;
  category: string;
  severity: string;
  point: number | '';
  description?: string;
  is_active?: boolean;
}

export interface BoardingViolationPayload {
  student_id: BoardingId;
  violation_type_id: BoardingId;
  date?: string;
  narrative?: string;
  evidence_path?: string;
  status?: string;
}

export interface BoardingDisciplinaryActionPayload {
  student_id: BoardingId;
  violation_id?: BoardingId;
  action: string;
  date?: string;
  officer?: string;
  notes?: string;
}

export interface BoardingAchievementPayload {
  student_id: BoardingId;
  title: string;
  description?: string;
  category: string;
  level?: string;
  date: string;
  evidence_path?: string;
}

export interface BoardingHealthPayload {
  student_id: BoardingId;
  condition?: string;
  complaint?: string;
  action?: string;
  date?: string;
  status?: string;
  notes?: string;
}

export interface BoardingIsolatePayload {
  student_id: BoardingId;
  room_id: BoardingId;
  bed_id?: BoardingId;
  reason: string;
  health_record_id?: BoardingId;
  notes?: string;
}

/* ============ titipan barang ============ */

export interface BoardingItemLoan {
  id: number;
  borrowed_at: string | null;
  returned_at: string | null;
  notes: string | null;
  processed_by?: string | null;
}

export interface BoardingItemDepositItem {
  id: number;
  deposit_id: number;
  item_type: string;
  item_type_label: string;
  item_name: string;
  status: 'in_storage' | 'borrowed' | 'returned_permanently' | string;
  status_label: string;
  notes: string | null;
  current_loan?: { id: number; borrowed_at: string | null; notes: string | null } | null;
  loans?: BoardingItemLoan[] | null;
  created_at?: string | null;
}

export interface BoardingItemDeposit {
  id: number;
  student_id: number;
  student?: BoardingStudentRef | null;
  received_by?: { id: number; name: string } | null;
  closed_by?: { id: number; name: string } | null;
  deposited_at: string | null;
  returned_at: string | null;
  status: 'active' | 'returned' | string;
  status_label: string;
  notes: string | null;
  items_count: number;
  borrowed_count: number;
  items: BoardingItemDepositItem[];
  created_at?: string | null;
}

export interface BoardingItemDepositPayload {
  student_id: BoardingId;
  deposited_at?: string;
  notes?: string;
  items: Array<{ item_type: string; item_name: string; notes?: string }>;
}
