export type RoleType = 'employee' | 'guardian' | 'student';

export interface RoleContext {
  type: RoleType;
  id: number;
  label: string;
  subtitle?: string | null;
}

export interface SessionUser {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  avatar_path?: string | null;
}

export interface AuthPayload {
  token: string;
  user: SessionUser;
  contexts: RoleContext[];
  active_context: RoleType | null;
  /** Izin efektif peran aktif — hanya untuk tampilan menu; server menegakkan sendiri. */
  permissions?: string[];
}

export interface ChildCard {
  id: number;
  full_name: string;
  nick_name?: string | null;
  nis: string;
  photo_path?: string | null;
  class_name?: string | null;
  attendance?: Record<string, number>;
  finance?: { outstanding: number; overdue: number };
}

export interface HomePayload {
  context: RoleType;
  children: ChildCard[];
  unread_notifications: number;
}

export interface AttendanceRecord {
  date: string;
  time_in?: string | null;
  notes?: string | null;
  code: string;
  status: string;
  color?: string | null;
  subject?: string | null;
}

export interface ScoreRow {
  score: string;
  grade?: string | null;
  predicate?: string | null;
  name: string;
  max_score: string;
  date?: string | null;
  subject: string;
  type: string;
}

export interface ReportCardRow {
  id: number;
  type: string;
  semester?: string | null;
  academic_year?: string | null;
  average_score?: string | null;
  rank?: number | null;
  homeroom_note?: string | null;
  items: { subject?: string | null; score?: string | null; grade?: string | null; predicate?: string | null; description?: string | null }[];
}

export interface ScheduleRow {
  day_id: number;
  start_time: string;
  end_time: string;
  subject: string;
  teacher?: string | null;
  room?: string | null;
}

export interface InvoiceRow {
  id: number;
  invoice_number: string;
  status: string;
  issue_date?: string | null;
  due_date?: string | null;
  total: string;
  paid_amount: string;
  remaining_amount: string;
  notes?: string | null;
}

export interface FinancePayload {
  summary: { totals: { outstanding_total: number; overdue_total: number; paid_total: number; billing_total: number; open_invoice_count: number } };
  invoices: InvoiceRow[];
}

export interface EmployeeAttendanceRow {
  date: string;
  check_in?: string | null;
  check_out?: string | null;
  status?: string | null;
  code?: string | null;
  color?: string | null;
}

export interface EmployeeHome {
  employee: { id: number; full_name: string; nip?: string | null; is_teacher: boolean };
  today: string;
  attendance: EmployeeAttendanceRow | null;
  schedule: { schedule_id: number; start_time: string; end_time: string; subject: string; class: string; room?: string | null; has_journal: boolean }[];
  pending_approvals: number;
  unread_notifications: number;
}

export interface NotificationItem {
  id: string;
  title: string;
  body?: string | null;
  read: boolean;
  created_at: string;
}
