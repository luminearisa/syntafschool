/**
 * Tipe kontrak API modul Tahfiz / Al-Qur'an.
 *
 * Nilai status/tipe mencerminkan konstanta model di backend — sumber
 * kebenaran tunggalnya ada di sana, bukan di berkas ini.
 */

export interface TahfizListParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  filter?: Record<string, string | number | boolean>;
}

/* ============================ PESERTA ============================ */

export type TahfizParticipantType =
  | 'student'
  | 'employee'
  | 'guardian'
  | 'public'
  | 'alumni'
  | 'other';

export type TahfizParticipantStatus = 'active' | 'inactive' | 'blacklisted';
export type TahfizGender = 'male' | 'female';

export interface TahfizParticipantProfile {
  experience_level: 'beginner' | 'intermediate' | 'advanced' | null;
  last_memorized: string | null;
  reading_ability: 'not_yet' | 'learning' | 'fluent' | 'tahsin_completed' | null;
  memorization_target: string | null;
  schedule_preference: string | null;
  education_background: string | null;
  occupation: string | null;
  emergency_contact: string | null;
}

export interface TahfizParticipantEnrollmentSummary {
  id: number;
  code: string;
  program_id: number;
  status: TahfizEnrollmentStatus;
  level_id: number | null;
  halaqah_id: number | null;
}

export interface TahfizParticipant {
  id: number;
  user_id: number | null;
  participant_type: TahfizParticipantType;
  student_id: number | null;
  employee_id: number | null;
  guardian_id: number | null;
  name: string;
  gender: TahfizGender | null;
  birth_date: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  photo_path: string | null;
  notes: string | null;
  status: TahfizParticipantStatus;

  enrollments_count: number;
  active_enrollments_count: number;
  memorization_records_count: number;

  profile?: TahfizParticipantProfile | null;
  student?: { id: number; nis: string; full_name: string } | null;
  employee?: { id: number; nip: string | null; full_name: string; position: string | null } | null;
  guardian?: { id: number; full_name: string } | null;
  enrollments?: TahfizParticipantEnrollmentSummary[];

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizParticipantPayload {
  participant_type: TahfizParticipantType;
  student_id?: number | '';
  employee_id?: number | '';
  guardian_id?: number | '';
  user_id?: number | '';
  name?: string;
  gender?: TahfizGender | '';
  birth_date?: string;
  phone?: string;
  email?: string;
  address?: string;
  notes?: string;
  status?: TahfizParticipantStatus;
  profile?: Partial<Record<keyof TahfizParticipantProfile, string>>;
}

/* ============================ PROGRAM & LEVEL ============================ */

export type TahfizProgramStatus = 'draft' | 'active' | 'archived';
export type TahfizTargetUnit = 'juz' | 'surah' | 'ayat' | 'page' | 'line';
export type TahfizDurationUnit = 'day' | 'week' | 'month' | 'semester' | 'year';

export interface TahfizProgramLevelSummary {
  id: number;
  name: string;
  code: string | null;
  order: number | null;
  juz_start: number | null;
  juz_end: number | null;
}

export interface TahfizProgram {
  id: number;
  name: string;
  code: string;
  description: string | null;
  participant_type: TahfizParticipantType | null;
  duration_value: number | null;
  duration_unit: TahfizDurationUnit | null;
  target_type: TahfizTargetUnit | 'none' | null;
  target_value: string | null;
  minimum_attendance: number | null;
  assessment_scheme: unknown[] | null;
  requires_placement_test: boolean;
  self_registration: boolean;
  status: TahfizProgramStatus;

  levels_count: number;
  enrollments_count: number;
  halaqahs_count: number;

  levels?: TahfizProgramLevelSummary[];

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizProgramPayload {
  name: string;
  code: string;
  description?: string;
  participant_type?: TahfizParticipantType | '';
  duration_value?: number | '';
  duration_unit?: TahfizDurationUnit | '';
  target_type?: TahfizTargetUnit | 'none' | '';
  target_value?: string;
  minimum_attendance?: number | '';
  requires_placement_test?: boolean;
  self_registration?: boolean;
  status?: TahfizProgramStatus;
}

export interface TahfizLevel {
  id: number;
  program_id: number | null;
  name: string;
  code: string | null;
  order: number | null;
  juz_start: number | null;
  juz_end: number | null;
  description: string | null;
  status: 'active' | 'archived';
  juz_range: string | null;

  program?: { id: number; name: string; code: string } | null;
  halaqahs_count: number;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizLevelPayload {
  program_id?: number | '';
  name: string;
  code?: string;
  order?: number | '';
  juz_start?: number | '';
  juz_end?: number | '';
  description?: string;
  status?: 'active' | 'archived';
}

/* ============================ RUBRIK ============================ */

export type TahfizRubricScope = 'tahsin' | 'tilawah' | 'hafalan' | 'placement' | 'exam' | 'tasmi';

export interface TahfizRubricItem {
  id: number;
  criterion: string;
  weight: number;
  order: number | null;
  level_descriptions: Record<string, string> | null;
}

export interface TahfizRubric {
  id: number;
  name: string;
  scope: TahfizRubricScope;
  description: string | null;
  max_score: number;
  status: 'active' | 'archived';
  total_weight: number;
  items?: TahfizRubricItem[];
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizRubricPayload {
  name: string;
  scope: TahfizRubricScope;
  description?: string;
  max_score?: number | '';
  status?: 'active' | 'archived';
  items?: { criterion: string; weight?: number | ''; order?: number | '' }[];
}

/* ============================ TAKSONOMI KESALAHAN ============================ */

export type TahfizMistakeCategory =
  | 'memorization'
  | 'pronunciation'
  | 'tajwid'
  | 'reading'
  | 'other';

export interface TahfizMistakeType {
  id: number;
  code: string;
  label: string;
  category: TahfizMistakeCategory | null;
  severity_weight: number;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizMistakeTypePayload {
  code: string;
  label: string;
  category?: TahfizMistakeCategory | '';
  severity_weight?: number | '';
  is_active?: boolean;
}

/* ============================ ITEM MUTABAAH ============================ */

export type TahfizMutabaahCategory = 'quran' | 'worship' | 'activity' | 'other';
export type TahfizMutabaahValueType = 'boolean' | 'count';

export interface TahfizMutabaahItem {
  id: number;
  code: string;
  label: string;
  category: TahfizMutabaahCategory | null;
  value_type: TahfizMutabaahValueType | null;
  unit: string | null;
  target_value: number | null;
  order: number | null;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizMutabaahItemPayload {
  code: string;
  label: string;
  category?: TahfizMutabaahCategory | '';
  value_type?: TahfizMutabaahValueType | '';
  unit?: string;
  target_value?: number | '';
  order?: number | '';
  is_active?: boolean;
}

/* ============================ INSTRUKTUR ============================ */

export type TahfizInstructorRole = 'instructor' | 'assistant' | 'musyrif' | 'musyrifah';
export type TahfizSpecialization = 'tahsin' | 'tahfiz' | 'tilawah' | 'tasmi' | 'murojaah';

export interface TahfizInstructor {
  id: number;
  employee_id: number;
  role: TahfizInstructorRole | null;
  specialization: TahfizSpecialization[] | null;
  certificate: string | null;
  notes: string | null;
  is_active: boolean;
  employee?: {
    id: number;
    full_name: string;
    nip: string | null;
    position: string | null;
    phone: string | null;
    photo_path: string | null;
  } | null;
  halaqahs_count: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizInstructorPayload {
  employee_id: number | '';
  role?: TahfizInstructorRole | '';
  specialization?: TahfizSpecialization[];
  certificate?: string;
  notes?: string;
  is_active?: boolean;
}

/* ============================ ENROLLMENT ============================ */

export type TahfizEnrollmentStatus =
  | 'draft'
  | 'registered'
  | 'assessment'
  | 'approved'
  | 'active'
  | 'suspended'
  | 'completed'
  | 'withdrawn'
  | 'rejected';

export interface TahfizEnrollment {
  id: number;
  code: string;
  participant_id: number;
  program_id: number;
  level_id: number | null;
  halaqah_id: number | null;
  school_class_id: number | null;
  academic_year_id: number | null;
  semester_id: number | null;
  status: TahfizEnrollmentStatus;
  enrolled_at: string | null;
  start_date: string | null;
  expected_end_date: string | null;
  completed_at: string | null;
  starting_juz: string | null;
  target_note: string | null;
  notes: string | null;
  approved_at: string | null;
  is_active: boolean;
  is_ongoing: boolean;

  participant?: {
    id: number;
    name: string;
    participant_type: TahfizParticipantType;
    gender: TahfizGender | null;
    phone: string | null;
  } | null;
  program?: {
    id: number;
    name: string;
    code: string;
    target_type: string | null;
    target_value: string | null;
  } | null;
  level?: { id: number; name: string; juz_start: number | null; juz_end: number | null } | null;
  halaqah?: {
    id: number;
    name: string;
    code: string;
    capacity: number | null;
    schedule_summary: string | null;
  } | null;
  school_class?: { id: number; name: string } | null;

  approved_by: number | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizEnrollmentPayload {
  participant_id: number | '';
  program_id: number | '';
  level_id?: number | '';
  halaqah_id?: number | '';
  school_class_id?: number | '';
  academic_year_id?: number | '';
  semester_id?: number | '';
  start_date?: string;
  expected_end_date?: string;
  starting_juz?: string;
  target_note?: string;
  notes?: string;
  status?: TahfizEnrollmentStatus;
}

export interface TahfizEnrollmentTransitionPayload {
  status: TahfizEnrollmentStatus;
  reason?: string;
  level_id?: number | '';
  halaqah_id?: number | '';
  completion_note?: string;
}

/* ============================ HALAQAH ============================ */

export type TahfizHalaqahStatus = 'active' | 'inactive' | 'merged';

export interface TahfizHalaqahMember {
  id: number;
  participant_id: number;
  enrollment_id: number | null;
  joined_at: string | null;
  participant: { id: number; name: string; participant_type: TahfizParticipantType } | null;
}

export interface TahfizHalaqah {
  id: number;
  program_id: number;
  level_id: number | null;
  name: string;
  code: string;
  capacity: number | null;
  room_id: number | null;
  instructor_id: number | null;
  assistant_id: number | null;
  musyrif_id: number | null;
  schedule_summary: string | null;
  academic_year_id: number | null;
  semester_id: number | null;
  status: TahfizHalaqahStatus;
  notes: string | null;

  active_members_count: number;
  has_capacity: boolean;

  program?: { id: number; name: string; code: string } | null;
  level?: { id: number; name: string; juz_start: number | null; juz_end: number | null } | null;
  room?: { id: number; name: string } | null;
  instructor?: { id: number; full_name: string; nip: string | null } | null;
  assistant?: { id: number; full_name: string; nip: string | null } | null;
  musyrif?: { id: number; full_name: string; nip: string | null } | null;
  members?: TahfizHalaqahMember[];
  schedules_count?: number;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizHalaqahPayload {
  program_id: number | '';
  level_id?: number | '';
  name: string;
  code: string;
  capacity?: number | '';
  room_id?: number | '';
  instructor_id?: number | '';
  assistant_id?: number | '';
  musyrif_id?: number | '';
  schedule_summary?: string;
  academic_year_id?: number | '';
  semester_id?: number | '';
  status?: TahfizHalaqahStatus;
  notes?: string;
}

/* ============================ JADWAL & SESI ============================ */

export type TahfizActivityType =
  | 'setoran'
  | 'murojaah'
  | 'tahsin'
  | 'tilawah'
  | 'ujian'
  | 'mentoring'
  | 'halaqah';

export interface TahfizSchedule {
  id: number;
  halaqah_id: number | null;
  program_id: number | null;
  activity_type: TahfizActivityType;
  day_of_week: number;
  day_label: string;
  start_time: string | null;
  end_time: string | null;
  room_id: number | null;
  instructor_id: number | null;
  effective_from: string | null;
  effective_until: string | null;
  status: 'active' | 'inactive';

  halaqah?: { id: number; name: string; code: string } | null;
  program?: { id: number; name: string } | null;
  room?: { id: number; name: string } | null;
  instructor?: { id: number; full_name: string } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizSchedulePayload {
  halaqah_id?: number | '';
  program_id?: number | '';
  activity_type: TahfizActivityType;
  day_of_week: number | '';
  start_time: string;
  end_time: string;
  room_id?: number | '';
  instructor_id?: number | '';
  effective_from?: string;
  effective_until?: string;
  status?: 'active' | 'inactive';
}

export type TahfizSessionStatus = 'planned' | 'ongoing' | 'done' | 'cancelled';

export interface TahfizSession {
  id: number;
  halaqah_id: number | null;
  schedule_id: number | null;
  activity_type: TahfizActivityType;
  date: string | null;
  start_time: string | null;
  end_time: string | null;
  room_id: number | null;
  instructor_id: number | null;
  topic: string | null;
  status: TahfizSessionStatus;
  notes: string | null;

  attendances_count: number;
  memorization_records_count: number;

  halaqah?: {
    id: number;
    name: string;
    code: string;
    instructor_id: number | null;
  } | null;
  schedule?: { id: number; activity_type: string; day_of_week: number } | null;
  room?: { id: number; name: string } | null;
  instructor?: { id: number; full_name: string } | null;
  attendances?: { id: number; participant_id: number; status: string; arrival_time: string | null }[];

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizSessionPayload {
  halaqah_id?: number | '';
  schedule_id?: number | '';
  activity_type: TahfizActivityType;
  date: string;
  start_time?: string;
  end_time?: string;
  room_id?: number | '';
  instructor_id?: number | '';
  topic?: string;
  status?: TahfizSessionStatus;
  notes?: string;
}

/* ============================ KEHADIRAN ============================ */

export type TahfizAttendanceStatus = 'hadir' | 'izin' | 'sakit' | 'alpa' | 'terlambat';

export interface TahfizAttendance {
  id: number;
  session_id: number | null;
  halaqah_id: number | null;
  participant_id: number;
  date: string | null;
  status: TahfizAttendanceStatus;
  code: string | null;
  is_present: boolean;
  arrival_time: string | null;
  permission_reason: string | null;
  notes: string | null;
  recorded_by: number | null;

  session?: { id: number; activity_type: string; date: string | null; topic: string | null } | null;
  halaqah?: { id: number; name: string; code: string } | null;
  participant?: { id: number; name: string; participant_type: TahfizParticipantType } | null;
  recorder?: { id: number; name: string } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizAttendancePayload {
  session_id?: number | '';
  halaqah_id?: number | '';
  participant_id: number | '';
  date: string;
  status: TahfizAttendanceStatus;
  arrival_time?: string;
  permission_reason?: string;
  notes?: string;
}

/* ============================ SETORAN HAFALAN ============================ */

export type TahfizMemorizationType = 'hafalan_baru' | 'ziyadah' | 'setoran' | 'tasmi';

export type TahfizMemorizationStatus =
  | 'submitted'
  | 'reviewed'
  | 'accepted'
  | 'accepted_with_revision'
  | 'repeated'
  | 'rejected';

export interface TahfizSurah {
  id: number;
  number?: number;
  name: string;
  arabic_name?: string | null;
  ayah_count?: number;
}

export interface TahfizMemorizationMistake {
  id: number;
  mistake_type_id: number | null;
  type_label: string | null;
  ayah: number | null;
  count: number | null;
  note: string | null;
  mistake_type?: {
    id: number;
    code: string;
    label: string;
    category: TahfizMistakeCategory | null;
  } | null;
}

export interface TahfizMemorizationRecord {
  id: number;
  enrollment_id: number | null;
  participant_id: number;
  halaqah_id: number | null;
  session_id: number | null;
  date: string | null;
  type: TahfizMemorizationType;
  type_label: string;
  surah_id: number;
  surah?: TahfizSurah;
  ayah_start: number;
  ayah_end: number;
  ayah_count: number;
  juz: number | null;
  page_start: number | null;
  page_end: number | null;
  page_count: number | null;
  line_count: number | null;
  duration_minutes: number | null;
  score: number | null;
  fluency: number | null;
  tajwid: number | null;
  makhraj: number | null;
  mistakes_count: number | null;
  mistakes?: TahfizMemorizationMistake[];
  status: TahfizMemorizationStatus;
  status_label: string;
  is_accepted: boolean;
  revision_due_date: string | null;
  teacher_note: string | null;
  participant_note: string | null;
  reviewed_at: string | null;

  participant?: { id: number; name: string } | null;
  halaqah?: { id: number; name: string; code: string } | null;

  recorded_by: number | null;
  reviewed_by: number | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizMemorizationMistakeInput {
  mistake_type_id?: number | '';
  type_label?: string;
  ayah?: number | '';
  count?: number | '';
  note?: string;
}

export interface TahfizMemorizationRecordPayload {
  enrollment_id?: number | '';
  participant_id: number | '';
  halaqah_id?: number | '';
  session_id?: number | '';
  date: string;
  type: TahfizMemorizationType;
  surah_id: number | '';
  ayah_start: number | '';
  ayah_end: number | '';
  juz?: number | '';
  page_start?: number | '';
  page_end?: number | '';
  line_count?: number | '';
  duration_minutes?: number | '';
  score?: number | '';
  fluency?: number | '';
  tajwid?: number | '';
  makhraj?: number | '';
  teacher_note?: string;
  participant_note?: string;
  mistakes?: TahfizMemorizationMistakeInput[];
}

export type TahfizReviewStatus = Exclude<TahfizMemorizationStatus, 'submitted'>;

export interface TahfizMemorizationReviewPayload {
  status: TahfizReviewStatus;
  score?: number | '';
  fluency?: number | '';
  tajwid?: number | '';
  makhraj?: number | '';
  revision_due_date?: string;
  teacher_note?: string;
}

/* ============================ MUROJAAH / TILAWAH / TAHSIN / TASMI ============================ */

export type TahfizRevisionMethod =
  | 'individual'
  | 'paired'
  | 'group'
  | 'tasmi'
  | 'teacher_listening';

export interface TahfizRevisionRecord {
  id: number;
  enrollment_id: number | null;
  participant_id: number;
  halaqah_id: number | null;
  session_id: number | null;
  date: string | null;
  surah_id: number | null;
  surah?: TahfizSurah | null;
  ayah_start: number | null;
  ayah_end: number | null;
  juz: number | null;
  page_start: number | null;
  page_end: number | null;
  method: TahfizRevisionMethod | null;
  method_label: string | null;
  duration_minutes: number | null;
  score: number | null;
  mistakes_count: number | null;
  teacher_note: string | null;
  participant?: { id: number; name: string } | null;
  halaqah?: { id: number; name: string; code: string } | null;
  recorded_by: number | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizRevisionRecordPayload {
  enrollment_id?: number | '';
  participant_id: number | '';
  halaqah_id?: number | '';
  session_id?: number | '';
  date: string;
  surah_id?: number | '';
  ayah_start?: number | '';
  ayah_end?: number | '';
  juz?: number | '';
  page_start?: number | '';
  page_end?: number | '';
  method?: TahfizRevisionMethod | '';
  duration_minutes?: number | '';
  score?: number | '';
  mistakes_count?: number | '';
  teacher_note?: string;
}

export interface TahfizTilawahRecord {
  id: number;
  enrollment_id: number | null;
  participant_id: number;
  date: string | null;
  surah_id: number | null;
  surah?: TahfizSurah | null;
  ayah_start: number | null;
  ayah_end: number | null;
  juz: number | null;
  page_count: number | null;
  duration_minutes: number | null;
  quality: number | null;
  note: string | null;
  participant?: { id: number; name: string } | null;
  recorded_by: number | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizTilawahRecordPayload {
  enrollment_id?: number | '';
  participant_id: number | '';
  date: string;
  surah_id?: number | '';
  ayah_start?: number | '';
  ayah_end?: number | '';
  juz?: number | '';
  page_count?: number | '';
  duration_minutes?: number | '';
  quality?: number | '';
  note?: string;
}

export interface TahfizTahsinScore {
  id: number;
  rubric_item_id: number | null;
  criterion: string | null;
  score: number | null;
  note: string | null;
}

export interface TahfizTahsinRecord {
  id: number;
  enrollment_id: number | null;
  participant_id: number;
  halaqah_id: number | null;
  date: string | null;
  rubric_id: number | null;
  instructor_id: number | null;
  surah_id: number | null;
  surah?: TahfizSurah | null;
  ayah_start: number | null;
  ayah_end: number | null;
  overall_score: number | null;
  notes: string | null;
  scores?: TahfizTahsinScore[];
  rubric?: { id: number; name: string; scope: string; max_score: number } | null;
  participant?: { id: number; name: string } | null;
  instructor?: { id: number; full_name: string } | null;
  recorded_by: number | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizTahsinScoreInput {
  rubric_item_id?: number | '';
  criterion?: string;
  score: number | '';
  note?: string;
}

export interface TahfizTahsinRecordPayload {
  enrollment_id?: number | '';
  participant_id: number | '';
  halaqah_id?: number | '';
  date: string;
  rubric_id?: number | '';
  instructor_id?: number | '';
  surah_id?: number | '';
  ayah_start?: number | '';
  ayah_end?: number | '';
  notes?: string;
  scores?: TahfizTahsinScoreInput[];
}

export type TahfizTasmiResult = 'lulus' | 'lulus_dengan_catatan' | 'ulang';

export interface TahfizTasmiRecord {
  id: number;
  enrollment_id: number | null;
  participant_id: number;
  date: string | null;
  juz_from: number;
  juz_to: number;
  juz_count: number;
  duration_minutes: number | null;
  mistakes_count: number | null;
  score: number | null;
  examiner_id: number | null;
  result: TahfizTasmiResult | null;
  result_label: string | null;
  is_passed: boolean;
  notes: string | null;
  participant?: { id: number; name: string } | null;
  examiner?: { id: number; full_name: string } | null;
  recorded_by: number | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizTasmiRecordPayload {
  enrollment_id?: number | '';
  participant_id: number | '';
  date: string;
  juz_from: number | '';
  juz_to: number | '';
  duration_minutes?: number | '';
  mistakes_count?: number | '';
  score?: number | '';
  examiner_id?: number | '';
  result?: TahfizTasmiResult | '';
  notes?: string;
}

/* ============================ TARGET ============================ */

export type TahfizTargetPeriod = 'daily' | 'weekly' | 'monthly' | 'semester' | 'program';

export interface TahfizTarget {
  id: number;
  scope: 'program' | 'halaqah';
  scope_label: string;
  program_id: number | null;
  halaqah_id: number | null;
  program?: { id: number; name: string; code: string } | null;
  halaqah?: { id: number; name: string; code: string } | null;
  surah_id: number | null;
  surah?: { id: number; name: string } | null;
  target_type: TahfizTargetUnit;
  target_type_label: string;
  value: number;
  period: TahfizTargetPeriod;
  period_label: string;
  is_active: boolean;
  effective_from: string | null;
  effective_until: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizTargetPayload {
  scope: 'program' | 'halaqah';
  program_id?: number | '';
  halaqah_id?: number | '';
  surah_id?: number | '';
  target_type: TahfizTargetUnit;
  value: number | '';
  period: TahfizTargetPeriod;
  is_active?: boolean;
  effective_from?: string;
  effective_until?: string;
}

export interface TahfizIndividualTarget {
  id: number;
  enrollment_id: number | null;
  participant_id: number;
  period: TahfizTargetPeriod;
  period_label: string;
  target_type: TahfizTargetUnit;
  surah_id: number | null;
  surah?: { id: number; name: string } | null;
  ayah_start: number | null;
  ayah_end: number | null;
  juz: number | null;
  page_start: number | null;
  page_end: number | null;
  value: number | null;
  unit: string | null;
  effective_from: string | null;
  effective_until: string | null;
  notes: string | null;
  is_active: boolean;
  participant?: { id: number; name: string } | null;
  created_by: number | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizIndividualTargetPayload {
  enrollment_id?: number | '';
  participant_id: number | '';
  period: TahfizTargetPeriod;
  target_type: TahfizTargetUnit;
  surah_id?: number | '';
  ayah_start?: number | '';
  ayah_end?: number | '';
  juz?: number | '';
  page_start?: number | '';
  page_end?: number | '';
  value?: number | '';
  unit?: string;
  effective_from?: string;
  effective_until?: string;
  notes?: string;
  is_active?: boolean;
}

/* ============================ UJIAN ============================ */

export type TahfizExamType =
  | 'ujian_hafalan'
  | 'sambung_ayat'
  | 'acak_ayat'
  | 'tasmi'
  | 'ujian_tahsin'
  | 'kenaikan_level';

export type TahfizExamStatus = 'draft' | 'scheduled' | 'ongoing' | 'done' | 'cancelled';
export type TahfizExamItemType = 'sambung_ayat' | 'acak_ayat' | 'hafalan' | 'tahsin';

export interface TahfizExam {
  id: number;
  code: string;
  name: string;
  program_id: number | null;
  halaqah_id: number | null;
  type: TahfizExamType;
  type_label: string;
  rubric_id: number | null;
  exam_date: string | null;
  start_time: string | null;
  duration_minutes: number | null;
  examiner_id: number | null;
  level_id: number | null;
  max_score: number;
  passing_score: number;
  description: string | null;
  status: TahfizExamStatus;
  status_label: string;
  participants_count: number | null;

  program?: { id: number; name: string; code: string } | null;
  halaqah?: { id: number; name: string; code: string } | null;
  rubric?: { id: number; name: string } | null;
  level?: { id: number; name: string; code: string | null } | null;
  examiner?: { id: number; full_name: string } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizExamPayload {
  code: string;
  name: string;
  program_id?: number | '';
  halaqah_id?: number | '';
  type: TahfizExamType;
  rubric_id?: number | '';
  exam_date: string;
  start_time?: string;
  duration_minutes?: number | '';
  examiner_id?: number | '';
  level_id?: number | '';
  max_score?: number | '';
  passing_score?: number | '';
  description?: string;
  status?: TahfizExamStatus;
}

export interface TahfizExamItem {
  id: number;
  exam_participant_id: number;
  item_type: TahfizExamItemType;
  surah_id: number | null;
  surah?: TahfizSurah | null;
  ayah_start: number | null;
  expected_ayah_end: number | null;
  actual_ayah_end: number | null;
  is_correct: boolean;
  score: number | null;
  errors: unknown[] | null;
  note: string | null;
}

export type TahfizExamParticipantStatus =
  | 'terdaftar'
  | 'hadir'
  | 'tidak_hadir'
  | 'menunggu_nilai'
  | 'dinilai';

export type TahfizExamResult = 'lulus' | 'tidak_lulus' | 'remedial';

export interface TahfizExamParticipant {
  id: number;
  exam_id: number;
  participant_id: number;
  enrollment_id: number | null;
  status: TahfizExamParticipantStatus;
  status_label: string;
  score: number | null;
  grade: string | null;
  result: TahfizExamResult | null;
  result_label: string | null;
  started_at: string | null;
  finished_at: string | null;
  feedback: string | null;
  items?: TahfizExamItem[];
  participant?: { id: number; name: string } | null;
  exam?: {
    id: number;
    code: string;
    name: string;
    type: TahfizExamType;
    max_score: number;
    passing_score: number;
  } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizExamGenerateItemsPayload {
  item_types: TahfizExamItemType[];
  count?: number;
}

export interface TahfizExamScoreItemPayload {
  actual_ayah_end?: number | '';
  score: number | '';
  errors?: unknown[];
  note?: string;
}

export interface TahfizExamResultPayload {
  score?: number | '';
  feedback?: string;
}

/* ============================ MUTABAAH HARIAN ============================ */

export interface TahfizMutabaahEntry {
  id: number;
  item_id: number | null;
  item_label: string | null;
  done: boolean;
  value: number | null;
  note: string | null;
  item?: {
    id: number;
    code: string;
    label: string;
    category: TahfizMutabaahCategory | null;
    target_value: number | null;
  } | null;
}

export interface TahfizMutabaah {
  id: number;
  participant_id: number;
  date: string | null;
  notes: string | null;
  entries?: TahfizMutabaahEntry[];
  participant?: { id: number; name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizMutabaahEntryInput {
  item_id?: number | '';
  item_label?: string;
  done?: boolean;
  value?: number | '';
  note?: string;
}

export interface TahfizMutabaahPayload {
  participant_id: number | '';
  date: string;
  notes?: string;
  entries: TahfizMutabaahEntryInput[];
}

/* ============================ SERTIFIKAT ============================ */

export type TahfizAchievementType =
  | 'selesai_program'
  | 'hafal_1_juz'
  | 'hafal_5_juz'
  | 'hafal_10_juz'
  | 'hafal_30_juz'
  | 'lulus_tasmi'
  | 'lulus_tahsin';

export interface TahfizCertificate {
  id: number;
  certificate_number: string;
  participant_id: number;
  enrollment_id: number | null;
  program_id: number | null;
  achievement_type: TahfizAchievementType;
  achievement_type_label: string;
  juz_count: number | null;
  title: string | null;
  description: string | null;
  issue_date: string | null;
  exam_id: number | null;
  examiner_id: number | null;
  issuer_id: number | null;
  signature_name: string | null;
  qr_token?: string;
  status: 'issued' | 'revoked';
  status_label: string;
  is_valid: boolean;
  revoked_at: string | null;
  revoke_reason: string | null;

  participant?: { id: number; name: string; participant_type: TahfizParticipantType } | null;
  program?: { id: number; name: string; code: string } | null;
  examiner?: { id: number; full_name: string } | null;
  issuer?: { id: number; name: string } | null;

  created_at?: string | null;
}

export interface TahfizCertificatePayload {
  participant_id: number | '';
  enrollment_id?: number | '';
  program_id?: number | '';
  achievement_type: TahfizAchievementType;
  juz_count?: number | '';
  title?: string;
  description?: string;
  issue_date: string;
  exam_id?: number | '';
  examiner_id?: number | '';
  signature_name?: string;
}

export interface TahfizCertificateVerifyResult {
  is_valid: boolean;
  certificate?: TahfizCertificate | null;
  message?: string;
}

/* ============================ INTERVENTION ============================ */

export type TahfizInterventionType =
  | 'tambahan_murojaah'
  | 'pengurangan_target'
  | 'sesi_khusus'
  | 'bimbingan_individual'
  | 'remedial_hafalan'
  | 'naik_level'
  | 'turun_level';

export type TahfizInterventionStatus = 'proposed' | 'in_progress' | 'done' | 'cancelled';

export interface TahfizIntervention {
  id: number;
  participant_id: number;
  enrollment_id: number | null;
  halaqah_id: number | null;
  type: TahfizInterventionType;
  type_label: string;
  reason: string;
  action: string | null;
  status: TahfizInterventionStatus;
  status_label: string;
  due_date: string | null;
  completed_at: string | null;
  result: string | null;
  assigned_by: number | null;
  is_open: boolean;
  is_overdue: boolean;
  participant?: { id: number; name: string } | null;
  halaqah?: { id: number; name: string } | null;
  assigned_by_user?: { id: number; name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TahfizInterventionPayload {
  participant_id: number | '';
  enrollment_id?: number | '';
  halaqah_id?: number | '';
  type: TahfizInterventionType;
  reason: string;
  action?: string;
  status?: TahfizInterventionStatus;
  due_date?: string;
  completed_at?: string;
  result?: string;
}

/* ============================ NOTIFIKASI ============================ */

export type TahfizNotificationAudience =
  | 'participant'
  | 'instructor'
  | 'musyrif'
  | 'parent'
  | 'admin';

export interface TahfizNotification {
  id: number;
  user_id: number | null;
  participant_id: number | null;
  audience: TahfizNotificationAudience;
  audience_label: string;
  type: string;
  title: string;
  body: string | null;
  data: Record<string, unknown> | null;
  read_at: string | null;
  is_read: boolean;
  participant?: { id: number; name: string } | null;
  created_at?: string | null;
}

/* ============================ PENDAFTARAN MANDIRI ============================ */

export type TahfizRegistrationStatus = 'pending' | 'approved' | 'rejected';

export interface TahfizRegistrationRequest {
  id: number;
  name: string;
  gender: TahfizGender | null;
  birth_date: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  program_id: number;
  experience_level: 'beginner' | 'intermediate' | 'advanced' | null;
  last_memorized: string | null;
  reading_ability: 'not_yet' | 'learning' | 'fluent' | 'tahsin_completed' | null;
  memorization_target: string | null;
  schedule_preference: string | null;
  status: TahfizRegistrationStatus;
  participant_id: number | null;
  review_note: string | null;
  reviewed_at: string | null;
  program?: { id: number; name: string; code: string } | null;
  participant?: { id: number; name: string } | null;
  reviewer?: { id: number; name: string } | null;
  created_at?: string | null;
}

export interface TahfizRegistrationPayload {
  name: string;
  gender?: TahfizGender | '';
  birth_date?: string;
  phone?: string;
  email?: string;
  address?: string;
  program_id: number | '';
  experience_level?: 'beginner' | 'intermediate' | 'advanced' | '';
  last_memorized?: string;
  reading_ability?: 'not_yet' | 'learning' | 'fluent' | 'tahsin_completed' | '';
  memorization_target?: string;
  schedule_preference?: string;
}

/* ============================ PROGRESS & PETA HAFALAN ============================ */

export type TahfizProgressStatus = 'belum' | 'hafal' | 'lancar' | 'mutqin' | 'belum_mutqin';

export interface TahfizSurahProgress {
  surah_id: number;
  number: number | null;
  name: string | null;
  arabic_name: string | null;
  juz: number | null;
  ayah_count: number | null;
  ayahs_memorized: number;
  ayahs_mutqin: number;
  memorized_percent: number;
  mutqin_percent: number;
  status: TahfizProgressStatus;
  status_label: string;
  avg_score: number | null;
  setoran_count: number;
  murojaah_count: number;
  last_setor_date: string | null;
  last_murojaah_date: string | null;
}

export interface TahfizJuzRollup {
  juz: number;
  surah_count: number;
  ayahs: number;
  ayahs_memorized: number;
  ayahs_mutqin: number;
  memorized_percent: number;
}

export interface TahfizMemorizationMap {
  participant_id: number;
  total_ayahs: number;
  total_pages: number;
  ayahs_memorized: number;
  ayahs_mutqin: number;
  pages_memorized: number;
  memorized_percent: number;
  mutqin_percent: number;
  surahs_memorized: number;
  juz_memorized: number;
  juz: TahfizJuzRollup[];
  surahs: TahfizSurahProgress[];
}

export interface TahfizTargetProgress {
  period: TahfizTargetPeriod;
  has_target: boolean;
  target_type: TahfizTargetUnit | null;
  target_value: number | null;
  achieved: number | null;
  percent: number | null;
  from?: string;
  to?: string;
}

/* ============================ DASHBOARD ============================ */

export interface TahfizProgressSummary {
  total_ayahs: number;
  total_pages: number;
  ayahs_memorized: number;
  ayahs_mutqin: number;
  memorized_percent: number;
  mutqin_percent: number;
  juz_completed: number;
  surahs_touched: number;
}

export interface TahfizAdminDashboard {
  participants: { active: number; total: number };
  enrollments: { breakdown: Record<string, number>; active: number };
  halaqahs: { active: number; total: number };
  memorization: {
    ayahs_memorized: number;
    ayahs_mutqin: number;
    juz_completed: number;
    mutqin_percent: number;
  };
  setoran: { this_week: number; this_month: number; pending_review: number };
  exams: { upcoming: number; done: number };
  interventions: { open: number; overdue: number };
  notifications_unread: number;
}

export interface TahfizTeachingDashboard {
  halaqahs: {
    id: number;
    name: string;
    code: string;
    active_members: number;
    capacity: number | null;
  }[];
  participants_count: number;
  today_sessions: TahfizSession[];
  pending_review: number;
  recent_setoran: TahfizMemorizationRecord[];
  progress: { avg_memorized_percent: number; avg_mutqin_percent: number };
  interventions_open: number;
}

export interface TahfizParticipantDashboard {
  participant: { id: number; name: string; participant_type: TahfizParticipantType } | null;
  progress: TahfizProgressSummary;
  target: TahfizTargetProgress;
  recent_setoran: TahfizMemorizationRecord[];
  pending_review: number;
  today_sessions: TahfizSession[];
  notifications_unread: number;
}

export interface TahfizParticipantSelfDashboard {
  is_participant: boolean;
  participant?: { id: number; name: string; participant_type: TahfizParticipantType };
  progress?: TahfizProgressSummary;
  target?: TahfizTargetProgress;
  recent_setoran?: TahfizMemorizationRecord[];
  pending_review?: number;
  today_sessions?: TahfizSession[];
  notifications_unread?: number;
}

export interface TahfizChildOption {
  id: number;
  name: string;
  participant_type: TahfizParticipantType;
}

export interface TahfizParentDashboard {
  view: 'self' | 'child';
  self?: TahfizParticipantDashboard | null;
  child?: TahfizParticipantDashboard;
  available_children: TahfizChildOption[];
  error?: string;
}

/* ============================ LAPORAN & RAPOR ============================ */

export interface TahfizSetoranStats {
  total: number;
  accepted: number;
  pending: number;
  rejected: number;
  avg_score: number | null;
  ayahs: number;
}

export interface TahfizReportPeriod {
  from: string | null;
  to: string | null;
}

export interface TahfizMistakePattern {
  key: string | number;
  label: string;
  count: number;
  occurrences: number;
}

export interface TahfizIndividualReport {
  participant: {
    id: number;
    name: string;
    participant_type: TahfizParticipantType;
    status: TahfizParticipantStatus;
  };
  enrollment: {
    id: number;
    code: string;
    status: TahfizEnrollmentStatus;
    enrolled_at: string | null;
    program: { id: number; name: string; code: string } | null;
    halaqah: { id: number; name: string; code: string } | null;
  } | null;
  period: TahfizReportPeriod;
  progress: TahfizMemorizationMap;
  setoran: TahfizSetoranStats;
  mistake_patterns: TahfizMistakePattern[];
  murojaah: { total: number; avg_score: number | null };
  tilawah: { total: number; avg_score: number | null };
  tasmi: { total: number; avg_score: number | null };
  tahsin: { total: number; avg_score: number | null };
  attendance: { total_sessions: number; present: number; percent: number };
  exams: TahfizExamParticipant[];
  interventions: TahfizIntervention[];
}

export interface TahfizHalaqahReport {
  halaqah: {
    id: number;
    name: string;
    code: string;
    capacity: number | null;
    status: TahfizHalaqahStatus;
    program: { id: number; name: string } | null;
    level: { id: number; name: string } | null;
    instructor_name: string | null;
    musyrif_name: string | null;
  };
  period: TahfizReportPeriod;
  members_count: number;
  setoran: TahfizSetoranStats;
  participants: {
    enrollment_id: number;
    participant_id: number;
    name: string | null;
    status: TahfizEnrollmentStatus;
    progress: {
      ayahs_memorized: number;
      ayahs_mutqin: number;
      memorized_percent: number;
      mutqin_percent: number;
    };
  }[];
}

export interface TahfizProgramReport {
  program: {
    id: number;
    name: string;
    code: string;
    status: TahfizProgramStatus;
    participant_type: TahfizParticipantType | null;
    target_type: string | null;
  };
  period: TahfizReportPeriod;
  counts: { enrollments: number; halaqahs: number; levels: number };
  enrollment_breakdown: Record<string, number>;
  halaqahs: {
    id: number;
    name: string;
    code: string;
    active_members: number;
    capacity: number | null;
  }[];
  setoran: TahfizSetoranStats;
  avg_progress: { avg_memorized_percent: number; avg_mutqin_percent: number };
}

export interface TahfizTeacherReport {
  teacher: { id: number; name: string };
  period: TahfizReportPeriod;
  halaqahs: {
    id: number;
    name: string;
    code: string;
    role: string;
    active_members: number;
  }[];
  participants_count: number;
  setoran_reviewed: number;
  setoran: TahfizSetoranStats;
  avg_progress: { avg_memorized_percent: number; avg_mutqin_percent: number };
}

export interface TahfizRapor {
  participant: {
    id: number;
    name: string;
    participant_type: TahfizParticipantType;
    status: TahfizParticipantStatus;
  };
  enrollment: TahfizIndividualReport['enrollment'];
  period: TahfizReportPeriod;
  hafalan: {
    ayahs_memorized: number;
    ayahs_mutqin: number;
    memorized_percent: number;
    mutqin_percent: number;
    juz_completed: number;
    surahs_memorized: number;
  };
  setoran: { total: number; accepted: number; avg_score: number | null };
  target: {
    value: number | null;
    period: TahfizTargetPeriod | null;
    achievement_percent: number | null;
    is_achieved: boolean;
  };
  attendance: { total_sessions: number; present: number; percent: number };
  mutabaah_days: number;
  murojaah: { total: number; avg_score: number | null };
  exams: TahfizExamParticipant[];
  interventions: TahfizIntervention[];
  notes: {
    predicate: 'sangat_baik' | 'baik' | 'cukup' | 'perlu_bimbingan';
    predicate_label: string;
    strengths: string[];
    improvements: string[];
  };
}

/** Respons laporan bisa berupa `{ exists: false }` bila entitas tidak ditemukan. */
export interface TahfizMissingReport {
  exists: false;
}