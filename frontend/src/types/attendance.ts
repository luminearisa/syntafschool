/**
 * Tipe untuk modul A6 — Presensi siswa, presensi pegawai, dan koreksi presensi.
 *
 * Dipisah dari `@/types` agar pengerjaan paralel tidak menyunting file bersama.
 */

/** Status baris presensi siswa (bukan status kehadirannya). */
export type StudentAttendanceRowStatus = 'recorded' | 'verified' | 'locked';

export type CorrectionStatus = 'pending' | 'approved' | 'rejected';

/** Kode status kehadiran yang dipakai backend. */
export type AttendanceCode = 'H' | 'A' | 'I' | 'S' | 'T';

export interface StudentAttendance {
  id: number;
  student_id: number;
  /** API memakai `class_id`; kolom DB-nya `school_class_id`. */
  class_id: number;
  semester_id: number;
  schedule_id: number | null;
  subject_id: number | null;
  date: string;
  attendance_status_id: number;
  status_code: string | null;
  status_name: string | null;
  leave_type_id: number | null;
  time_in: string | null;
  notes: string | null;
  recorded_by: number | null;
  verified_by: number | null;
  verified_at: string | null;
  status: StudentAttendanceRowStatus;

  student?: { id: number; nis: string; full_name: string } | null;
  school_class?: { id: number; name: string; code: string } | null;
  subject?: { id: number; code: string; name: string } | null;
  semester?: { id: number; name: string } | null;
  leave_type?: { id: number; name: string } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface StudentAttendancePayload {
  student_id: number | '';
  class_id: number | '';
  semester_id: number | '';
  schedule_id?: number | '';
  subject_id?: number | '';
  date: string;
  attendance_status_id: number | '';
  leave_type_id?: number | '';
  time_in?: string;
  notes?: string;
}

export interface StudentAttendanceQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  from?: string;
  to?: string;
  filter?: Record<string, string | number | boolean>;
}

/* ============================ LEMBAR INPUT KELAS ============================ */

/** Satu siswa pada lembar input presensi kelas. */
export interface AttendanceSheetItem {
  student_id: number;
  nis: string;
  full_name: string;
  attendance_id: number | null;
  attendance_status_id: number | null;
  status_code: string | null;
  leave_type_id: number | null;
  time_in: string | null;
  notes: string | null;
  recorded: boolean;
}

export interface AttendanceSheet {
  class_id: number;
  semester_id: number;
  date: string;
  /** null bila semester itu belum punya data hari efektif. */
  is_school_day: boolean | null;
  total: number;
  recorded: number;
  items: AttendanceSheetItem[];
}

export interface AttendanceSheetParams {
  class_id: number | '';
  semester_id: number | '';
  date: string;
  schedule_id?: number | '';
}

export interface BulkAttendanceEntryPayload {
  student_id: number;
  attendance_status_id: number;
  leave_type_id?: number | null;
  time_in?: string | null;
  notes?: string | null;
}

export interface BulkAttendancePayload {
  class_id: number | '';
  semester_id: number | '';
  schedule_id?: number | '';
  subject_id?: number | '';
  date: string;
  entries: BulkAttendanceEntryPayload[];
}

export interface BulkAttendanceResult {
  class_id: number;
  semester_id: number;
  date: string;
  created: number;
  updated: number;
  total: number;
}

/* ================================= REKAP ================================= */

export interface StudentRecap {
  student_id: number;
  semester_id: number;
  /** null bila semester belum punya data hari efektif. */
  effective_days: number | null;
  recorded_days: number;
  /** Penyebut yang benar-benar dipakai menghitung persentase. */
  basis: 'effective_days' | 'recorded_days';
  present: number;
  late: number;
  sick: number;
  permitted: number;
  absent: number;
  total_present: number;
  percentage: number;
  breakdown: Record<string, number>;
}

export interface ClassRecapItem extends StudentRecap {
  student: { id: number; nis: string; full_name: string };
}

export interface ClassRecap {
  class_id: number;
  semester_id: number;
  education_level_id: number | null;
  effective_days: number | null;
  student_count: number;
  items: ClassRecapItem[];
  totals: {
    present: number;
    late: number;
    sick: number;
    permitted: number;
    absent: number;
    average_percentage: number;
  };
}

/* ============================ PRESENSI PEGAWAI ============================ */

export interface EmployeeAttendance {
  id: number;
  employee_id: number;
  date: string;
  check_in: string | null;
  check_out: string | null;
  attendance_status_id: number;
  status_code: string | null;
  status_name: string | null;
  leave_type_id: number | null;
  notes: string | null;
  recorded_by: number | null;

  employee?: { id: number; nip: string | null; full_name: string } | null;
  leave_type?: { id: number; name: string } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface EmployeeAttendancePayload {
  employee_id: number | '';
  date: string;
  check_in?: string;
  check_out?: string;
  attendance_status_id: number | '';
  leave_type_id?: number | '';
  notes?: string;
}

export interface EmployeeRecapItem {
  employee_id: number;
  employee: { id: number; nip: string | null; full_name: string };
  total_days: number;
  present: number;
  late: number;
  sick: number;
  permitted: number;
  absent: number;
  breakdown: Record<string, number>;
}

export interface EmployeeRecap {
  from: string;
  to: string;
  items: EmployeeRecapItem[];
  totals: {
    employees: number;
    present: number;
    late: number;
    sick: number;
    permitted: number;
    absent: number;
  };
}

/* ============================ KOREKSI PRESENSI ============================ */

export interface AttendanceCorrection {
  id: number;
  student_attendance_id: number;
  old_status_id: number | null;
  new_status_id: number;
  old_status_name: string | null;
  new_status_name: string | null;
  reason: string;
  requested_by: number | null;
  approved_by: number | null;
  status: CorrectionStatus;
  approved_at: string | null;
  is_pending: boolean;

  attendance?: {
    id: number;
    student_id: number;
    class_id: number;
    date: string;
    student: { id: number; nis: string; full_name: string } | null;
  } | null;
  requester?: { id: number; name: string } | null;
  approver?: { id: number; name: string } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface AttendanceCorrectionPayload {
  student_attendance_id: number | '';
  new_status_id: number | '';
  leave_type_id?: number | '';
  reason: string;
}

export interface AttendanceCorrectionQueryParams {
  page?: number;
  per_page?: number;
  all?: boolean;
  student_id?: number | '';
  filter?: Record<string, string | number | boolean>;
}
