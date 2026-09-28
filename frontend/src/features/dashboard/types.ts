export interface DashboardStudentAnalytics {
  active_total: number;
  admitted_this_month: number;
  admitted_last_month: number;
  admissions_trend: Array<{
    period: string;
    label: string;
    count: number;
  }>;
}

export interface DashboardAcademicAnalytics {
  academic_year: string | null;
  active_classes: number;
  enrolled_students: number;
}

export interface DashboardAttendanceDay {
  date: string;
  total: number;
  present: number;
  rate: number | null;
}

export interface DashboardAttendanceAnalytics {
  days: number;
  daily: DashboardAttendanceDay[];
  summary: {
    total: number;
    present: number;
    rate: number | null;
    statuses: Array<{
      code: string;
      name: string;
      color: string | null;
      total: number;
    }>;
  };
  today: {
    total: number;
    rate: number | null;
  };
}

export interface DashboardFinanceAnalytics {
  series: Array<{
    period: string;
    label: string;
    received: number | null;
    billed: number | null;
  }>;
  received_this_month: number | null;
  billed_this_month: number | null;
  outstanding_total?: number;
  outstanding_invoices?: number;
  pending_payments?: number;
}

export interface DashboardOverview {
  as_of: string;
  attendance_days: number;
  students?: DashboardStudentAnalytics;
  academic?: DashboardAcademicAnalytics;
  attendance?: DashboardAttendanceAnalytics;
  finance?: DashboardFinanceAnalytics;
}
