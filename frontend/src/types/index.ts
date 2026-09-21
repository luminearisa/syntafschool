export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
}

export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  from?: number;
  to?: number;
}

export interface PaginatedResponse<T> {
  success: boolean;
  message: string;
  data: T[];
  meta: PaginationMeta;
}

export interface ApiError {
  success: false;
  message: string;
  errors?: Record<string, string[]>;
}

export interface User {
  id: number;
  name: string;
  username: string | null;
  email: string;
  phone: string | null;
  avatar_path: string | null;
  userable_type: string | null;
  userable_id: number | null;
  is_active: boolean;
  last_login_at: string | null;
  roles?: string[];
  permissions?: string[];
  created_at: string | null;
  updated_at: string | null;
}

export interface LoginCredentials {
  email: string;
  password: string;
  device_name?: string;
}

export interface LoginResult {
  token: string;
  user: User;
}

/** Record master data generik */
export interface MasterRecord {
  id: number | string;
  [key: string]: unknown;
}

/** Parameter query standar */
export interface MasterQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  all?: boolean;
  include?: string;
  filter?: Record<string, string | number | boolean>;
}

/* ============================ RBAC ============================ */

export interface Permission {
  id: number;
  name: string;
  guard_name: string;
}

export interface Role {
  id: number;
  name: string;
  guard_name: string;
  permissions?: string[];
  users_count?: number;
  created_at?: string | null;
}

/** Respons GET /permissions */
export interface PermissionCollection {
  items: Permission[];
  grouped: Record<string, string[]>;
}

export interface RolePayload {
  name: string;
  permissions: string[];
}

export interface UserPayload {
  name: string;
  username: string | null;
  email: string;
  password?: string;
  phone: string | null;
  is_active: boolean;
  roles: string[];
}

/* ============================ AKADEMIK ============================ */

export interface AcademicYear {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  is_active: boolean;
}

export interface Semester {
  id: number;
  academic_year_id: number;
  name: 'Ganjil' | 'Genap' | string;
  start_date: string;
  end_date: string;
  is_active: boolean;
  academic_year?: AcademicYear;
}

export interface ActivePeriod {
  academic_year: AcademicYear | null;
  semester: Semester | null;
}

export interface SchoolClass {
  id: number;
  school_id: number | null;
  academic_year_id: number;
  education_level_id: number | null;
  room_id: number | null;
  name: string;
  code: string;
  homeroom_teacher_id: number | null;
  capacity: number;
  is_active: boolean;
  students_count?: number;
  class_subjects_count?: number;
  academic_year?: AcademicYear;
  education_level?: { id: number; name: string } | null;
  room?: { id: number; name: string } | null;
  homeroom_teacher?: { id: number; full_name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface SchoolClassPayload {
  academic_year_id: number | '';
  education_level_id: number | '';
  room_id: number | '';
  school_id?: number | '';
  name: string;
  code: string;
  homeroom_teacher_id: number | '';
  capacity: number | '';
  is_active: boolean;
}

export interface ClassStudentItem {
  class_student_id: number;
  status: string;
  student: {
    id: number;
    nis: string;
    nisn: string | null;
    full_name: string;
  } | null;
}

/** Mapel yang diajarkan pada sebuah kelas/rombel */
export interface ClassSubject {
  id: number;
  school_class_id: number;
  subject_id: number;
  employee_id: number | null;
  academic_year_id: number;
  subject?: {
    id: number;
    code: string;
    name: string;
    category: string;
    credit: string | number | null;
  } | null;
  teacher?: {
    id: number;
    nip: string | null;
    full_name: string;
  } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface ClassSubjectInput {
  subject_id: number;
  employee_id?: number | null;
}

export interface ClassSubjectCopyResult {
  added: number;
  skipped: number;
}

/* ============================ JADWAL PELAJARAN ============================ */

export type TimeSlotType = 'pelajaran' | 'istirahat' | 'upacara' | 'lainnya';

/** Jam pelajaran — kerangka waktu yang dipakai grid jadwal */
export interface TimeSlot {
  id: number;
  school_id: number | null;
  day_id: number;
  slot_number: number;
  label: string | null;
  start_time: string;
  end_time: string;
  type: TimeSlotType;
  is_active: boolean;
  day?: { id: number; name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TimeSlotPayload {
  day_id: number | '';
  slot_number: number | '';
  label: string;
  start_time: string;
  end_time: string;
  type: TimeSlotType;
  is_active: boolean;
}

export interface TimeSlotGenerateResult {
  created: number;
  updated: number;
  skipped: number;
}

export interface Schedule {
  id: number;
  school_class_id: number;
  subject_id: number;
  employee_id: number | null;
  room_id: number | null;
  day_id: number;
  semester_id: number;
  time_slot_id: number | null;
  start_time: string;
  end_time: string;
  note: string | null;
  school_class?: { id: number; name: string; code: string } | null;
  subject?: { id: number; code: string; name: string } | null;
  teacher?: { id: number; nip: string | null; full_name: string } | null;
  room?: { id: number; code: string; name: string } | null;
  day?: { id: number; name: string } | null;
  time_slot?: {
    id: number;
    slot_number: number;
    label: string | null;
    start_time: string;
    end_time: string;
    type: TimeSlotType;
  } | null;
  changes?: ScheduleChange[];
  created_at?: string | null;
  updated_at?: string | null;
}

export interface SchedulePayload {
  school_class_id: number | '';
  subject_id: number | '';
  employee_id: number | '';
  room_id: number | '';
  day_id: number | '';
  semester_id: number | '';
  time_slot_id: number | '';
  start_time: string;
  end_time: string;
  note: string;
}

/** Hasil deteksi bentrok dari backend (kelas / guru / ruang / jam) */
export interface ScheduleConflict {
  type: 'kelas' | 'guru' | 'ruang' | 'jam';
  message: string;
  schedule_id?: number;
}

export interface ConflictCheckResult {
  has_conflict: boolean;
  conflicts: ScheduleConflict[];
}

export type ScheduleChangeType = 'pengganti' | 'pindah' | 'batal';

/** Perubahan jadwal harian: guru pengganti, pindah jam, pembatalan */
export interface ScheduleChange {
  id: number;
  schedule_id: number;
  date: string;
  type: ScheduleChangeType;
  substitute_employee_id: number | null;
  new_room_id: number | null;
  new_day_id: number | null;
  new_start_time: string | null;
  new_end_time: string | null;
  reason: string | null;
  substitute_teacher?: { id: number; full_name: string } | null;
  new_room?: { id: number; name: string } | null;
  new_day?: { id: number; name: string } | null;
  schedule?: {
    id: number;
    start_time: string;
    end_time: string;
    school_class?: { id: number; name: string } | null;
    subject?: { id: number; name: string } | null;
    teacher?: { id: number; full_name: string } | null;
    room?: { id: number; name: string } | null;
    day?: { id: number; name: string } | null;
  } | null;
  created_at?: string | null;
}

export interface ScheduleChangePayload {
  date: string;
  type: ScheduleChangeType;
  substitute_employee_id?: number | '';
  new_room_id?: number | '';
  new_day_id?: number | '';
  new_start_time?: string;
  new_end_time?: string;
  reason?: string;
}

/** Grid mingguan: baris = jam pelajaran, kolom = hari */
export interface ScheduleGrid {
  days: { id: number; name: string }[];
  slots: TimeSlot[];
  entries: Schedule[];
  changes: ScheduleChange[];
  date: string | null;
}

export interface ScheduleGridParams {
  semester_id: number | '';
  school_class_id?: number | '';
  employee_id?: number | '';
  room_id?: number | '';
  date?: string;
}

/* ============================ KEPEGAWAIAN ============================ */

export type EmployeeType = 'guru' | 'staf';
export type EmploymentStatus = 'PNS' | 'PPPK' | 'Honorer' | 'GTY';

export interface Employee {
  id: number;
  school_id: number | null;
  nip: string | null;
  full_name: string;
  gender_id: number | null;
  religion_id: number | null;
  birth_place: string | null;
  birth_date: string | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  photo_path: string | null;
  position: string | null;
  employee_type: EmployeeType;
  employment_status: EmploymentStatus;
  join_date: string | null;
  is_teacher: boolean;
  is_active: boolean;
  gender?: { id: number; name: string } | null;
  religion?: { id: number; name: string } | null;
  school?: { id: number; name: string } | null;
  homeroom_classes_count?: number;
  class_subjects_count?: number;
  has_account?: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface EmployeePayload {
  school_id?: number | '';
  nip: string | null;
  full_name: string;
  gender_id?: number | '';
  religion_id?: number | '';
  birth_place?: string | null;
  birth_date?: string | null;
  address?: string | null;
  phone?: string | null;
  email?: string | null;
  position?: string | null;
  employee_type: EmployeeType;
  employment_status: EmploymentStatus;
  join_date?: string | null;
  is_teacher: boolean;
  is_active: boolean;
}

export interface EmployeeQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  teachers_only?: boolean;
  employee_type?: EmployeeType | '';
  employment_status?: EmploymentStatus | '';
  is_active?: boolean;
}

export interface EmployeeAccountPayload {
  email: string;
  username?: string | null;
  password: string;
  role: string;
}

/* ============================ KESISWAAN ============================ */

export type BloodType = 'A' | 'B' | 'AB' | 'O';
export type StudentHistoryStatus = 'baru' | 'naik' | 'tinggal' | 'lulus' | 'pindah' | 'keluar';

/** Relasi wali pada satu siswa (pivot student_guardians) */
export interface StudentGuardianLink {
  id: number;
  full_name: string;
  phone: string | null;
  email: string | null;
  occupation_id: number | null;
  occupation?: { id: number; name: string } | null;
  guardian_relation_id: number | null;
  is_primary: boolean;
}

export interface StudentHistoryEntry {
  id: number;
  academic_year_id: number;
  academic_year?: { id: number; name: string } | null;
  school_class_id: number | null;
  class_name: string | null;
  education_level?: string | null;
  status: StudentHistoryStatus;
  note: string | null;
}

export interface Student {
  id: number;
  school_id: number | null;
  nis: string;
  nisn: string | null;
  full_name: string;
  nick_name: string | null;
  gender_id: number | null;
  religion_id: number | null;
  birth_place: string | null;
  birth_date: string | null;
  age?: number | null;
  blood_type: BloodType | null;
  address: string | null;
  province_id: string | null;
  city_id: string | null;
  district_id: string | null;
  village_id: string | null;
  postal_code: string | null;
  phone: string | null;
  email: string | null;
  photo_path: string | null;
  residence_type_id: number | null;
  transportation_id: number | null;
  child_order: number | null;
  sibling_count: number | null;
  previous_school: string | null;
  admission_date: string | null;
  status_id: number | null;
  is_active: boolean;

  gender?: { id: number; name: string } | null;
  religion?: { id: number; name: string } | null;
  status?: { id: number; name: string } | null;
  school?: { id: number; name: string } | null;
  province?: { id: string; name: string } | null;
  city?: { id: string; name: string } | null;
  district?: { id: string; name: string } | null;
  village?: { id: string; name: string } | null;
  residence_type?: { id: number; name: string } | null;
  transportation?: { id: number; name: string } | null;

  guardians?: StudentGuardianLink[];
  histories?: StudentHistoryEntry[];
  current_class?: { class_student_id: number; id: number; name: string; code: string } | null;
  guardians_count?: number;
  has_account?: boolean;

  created_at?: string | null;
  updated_at?: string | null;
}

/** Satu baris wali di dalam form siswa */
export interface StudentGuardianInput {
  guardian_id?: number | '';
  full_name?: string | null;
  guardian_relation_id?: number | '';
  is_primary: boolean;
  phone?: string | null;
  email?: string | null;
  occupation_id?: number | '';
  income_range_id?: number | '';
  education_background_id?: number | '';
  gender_id?: number | '';
  religion_id?: number | '';
  address?: string | null;
}

export interface StudentPayload {
  school_id?: number | '';
  nis: string;
  nisn?: string | null;
  full_name: string;
  nick_name?: string | null;
  gender_id?: number | '';
  religion_id?: number | '';
  birth_place?: string | null;
  birth_date?: string | null;
  blood_type?: BloodType | null;
  address?: string | null;
  province_id?: string | null;
  city_id?: string | null;
  district_id?: string | null;
  village_id?: string | null;
  postal_code?: string | null;
  phone?: string | null;
  email?: string | null;
  residence_type_id?: number | '';
  transportation_id?: number | '';
  child_order?: number | '';
  sibling_count?: number | '';
  previous_school?: string | null;
  admission_date?: string | null;
  status_id?: number | '';
  is_active: boolean;
  guardians?: StudentGuardianInput[];
}

export interface StudentQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  class_id?: number | '';
  academic_year_id?: number | '';
  unassigned?: boolean;
  status_id?: number | '';
  gender_id?: number | '';
  is_active?: boolean;
}

export interface StudentAccountPayload {
  email: string;
  username?: string | null;
  password: string;
  role?: string;
}

export interface StudentHistoryPayload {
  academic_year_id: number | '';
  school_class_id?: number | '';
  status: StudentHistoryStatus;
  note?: string | null;
}

/* ============================ WALI MURID ============================ */

export interface Guardian {
  id: number;
  full_name: string;
  gender_id: number | null;
  religion_id: number | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  occupation_id: number | null;
  income_range_id: number | null;
  education_background_id: number | null;

  gender?: { id: number; name: string } | null;
  religion?: { id: number; name: string } | null;
  occupation?: { id: number; name: string } | null;
  income_range?: { id: number; name: string } | null;
  education_background?: { id: number; name: string } | null;

  students?: {
    id: number;
    nis: string;
    full_name: string;
    guardian_relation_id: number | null;
    is_primary: boolean;
  }[];
  students_count?: number;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface GuardianPayload {
  full_name: string;
  gender_id?: number | '';
  religion_id?: number | '';
  address?: string | null;
  phone?: string | null;
  email?: string | null;
  occupation_id?: number | '';
  income_range_id?: number | '';
  education_background_id?: number | '';
}

export interface GuardianQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  student_id?: number | '';
  unlinked?: boolean;
  occupation_id?: number | '';
}

/** Satu item rantai wilayah berjenjang */
export interface RegionOption {
  value: string;
  label: string;
}
