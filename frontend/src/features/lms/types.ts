/** Tipe modul LMS — cerminan API `/api/v1/lms/*`. */

export type ActorRoleValue = 'ADMIN' | 'TEACHER' | 'STUDENT' | 'PARENT' | 'GUEST';
export type CourseTypeValue = 'ACADEMIC' | 'NON_ACADEMIC' | 'TRAINING' | 'WORKSHOP' | 'GENERAL';
export type CourseStatusValue = 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';
export type CourseVisibilityValue = 'PUBLIC' | 'PRIVATE' | 'INSTITUTION' | 'ENROLLED_ONLY';
export type CourseLevelValue = 'BEGINNER' | 'INTERMEDIATE' | 'ADVANCED' | 'ALL_LEVELS';
export type ItemTypeValue = 'RESOURCE' | 'ACTIVITY';
export type ResourceTypeValue = 'PAGE' | 'FILE' | 'VIDEO' | 'AUDIO' | 'URL' | 'BOOK' | 'FOLDER' | 'TEXT';
export type ActivityTypeValue = 'ASSIGNMENT' | 'QUIZ' | 'FORUM' | 'LESSON' | 'SURVEY' | 'CHOICE' | 'WORKSHOP';
export type CompletionTypeValue = 'NONE' | 'MANUAL' | 'VIEW' | 'PASS' | 'SUBMIT' | 'COMPLETE_ACTIVITY';
export type EnrollmentRoleValue = 'TEACHER' | 'STUDENT' | 'ASSISTANT';
export type EnrollmentStatusValue = 'PENDING' | 'ACTIVE' | 'SUSPENDED' | 'COMPLETED' | 'CANCELLED';
export type EnrollmentMethodValue = 'MANUAL' | 'ACADEMIC' | 'SELF' | 'INVITATION' | 'COHORT' | 'GROUP' | 'IMPORT';
export type CourseScopeValue = 'MANAGED' | 'ENROLLED' | 'PUBLIC' | 'ALL';

export interface EnumOption<T extends string = string> {
  value: T;
  label: string;
  icon?: string | null;
  graded?: boolean | null;
}

export interface LmsActor {
  id: number;
  name: string;
  email: string | null;
  avatar_path: string | null;
  role: ActorRoleValue;
  role_label: string;
  roles: string[];
  permissions: string[];
  is_super_admin: boolean;
  mode: 'integrated' | 'standalone';
  employee_id: number | null;
  student_id: number | null;
}

export interface LmsCategory {
  id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  description: string | null;
  image: string | null;
  sort_order: number;
  status: 'ACTIVE' | 'INACTIVE';
  visibility: CourseVisibilityValue;
  depth: number;
  /** `courses_count` = jumlah course langsung; `course_count` dipertahankan
   *  untuk kompatibilitas respons lama. */
  courses_count?: number;
  course_count?: number;
  courses?: LmsCourse[];
  children?: LmsCategory[];
}

export interface LmsCategoryOption {
  id: number;
  name: string;
  label: string;
  depth: number;
  parent_id: number | null;
}

export interface LmsCourseTeacher {
  id: number;
  user_id: number | null;
  employee_id: number | null;
  name: string | null;
  role?: EnrollmentRoleValue;
  is_primary: boolean;
}

export interface LmsCourse {
  id: number;
  category_id: number | null;
  category?: { id: number; name: string; slug: string } | null;
  academic_course_id: number | null;
  academic_year_id: number | null;
  semester_id: number | null;
  program_id: number | null;
  class_id: number | null;
  teacher_id: number | null;
  code: string;
  name: string;
  slug: string;
  short_description: string | null;
  description?: string | null;
  learning_objectives?: string | null;
  thumbnail: string | null;
  cover_image: string | null;
  course_type: CourseTypeValue;
  course_type_label?: string;
  level: CourseLevelValue;
  level_label?: string;
  language: string;
  start_date: string | null;
  end_date: string | null;
  status: CourseStatusValue;
  status_label?: string;
  visibility: CourseVisibilityValue;
  visibility_label?: string;
  enrollment_method: EnrollmentMethodValue;
  is_featured: boolean;
  max_students: number | null;
  estimated_minutes: number | null;
  published_at: string | null;
  archived_at: string | null;
  teachers?: LmsCourseTeacher[];
  counts?: { students?: number; sections?: number; items?: number };
  statistics?: CourseStatistics | null;
  created_at?: string;
  updated_at?: string;
}

export interface CourseStatistics {
  students: number;
  active_students: number;
  completed_students: number;
  completion_rate: number;
  average_progress: number;
  teachers: number;
  sections: number;
  items: number;
  countable_items: number;
  activities: number;
  resources: number;
}

export interface LmsResourceContent {
  id: number;
  learning_item_id: number;
  content: string | null;
  file_name: string | null;
  file_mime: string | null;
  file_size: number | null;
  has_file: boolean;
  download_url: string | null;
  external_url: string | null;
  open_in: 'NEW_TAB' | 'SAME_TAB' | 'EMBED';
  media_source: 'UPLOAD' | 'EXTERNAL' | 'EMBED' | null;
  video_provider: string | null;
  embed_url: string | null;
  duration_seconds: number | null;
  settings: Record<string, unknown> | null;
}

export interface LmsBookChapter {
  id: number;
  title: string;
  content: string | null;
  sort_order: number;
}

export interface LmsLearningItem {
  id: number;
  course_id: number;
  section_id: number;
  parent_id: number | null;
  title: string;
  description: string | null;
  item_type: ItemTypeValue;
  resource_type: ResourceTypeValue | null;
  resource_type_label?: string;
  activity_type: ActivityTypeValue | null;
  activity_type_label?: string;
  icon?: string;
  sort_order: number;
  visibility: 'VISIBLE' | 'HIDDEN';
  is_required: boolean;
  completion_type: CompletionTypeValue;
  completion_type_label?: string;
  completion_min_grade: number | null;
  is_preview: boolean;
  availability: Record<string, unknown>;
  estimated_minutes: number | null;
  external_activity_id: number | null;
  resource?: LmsResourceContent | null;
  chapters?: LmsBookChapter[];
  children?: LmsLearningItem[];
  children_count?: number;
}

export interface LmsSection {
  id: number;
  course_id: number;
  title: string;
  description: string | null;
  summary: string | null;
  sort_order: number;
  visibility: 'VISIBLE' | 'HIDDEN';
  available_from: string | null;
  available_until: string | null;
  availability: Record<string, unknown> | null;
  completion_rule: string;
  items?: LmsLearningItem[];
  items_count?: number;
}

/** Section pada kerangka belajar: sudah dilengkapi status akses & progress. */
export interface CourseStructureSection {
  id: number;
  title: string;
  description: string | null;
  summary: string | null;
  sort_order: number;
  visibility: 'VISIBLE' | 'HIDDEN';
  available_from: string | null;
  available_until: string | null;
  locked: boolean;
  lock_reason: string | null;
  items: CourseStructureItem[];
}

export interface CourseStructureItem {
  id: number;
  section_id: number;
  title: string;
  description: string | null;
  item_type: ItemTypeValue;
  resource_type: ResourceTypeValue | null;
  activity_type: ActivityTypeValue | null;
  icon: string;
  is_required: boolean;
  completion_type: CompletionTypeValue;
  is_preview: boolean;
  estimated_minutes: number | null;
  sort_order: number;
  completed: boolean;
  locked: boolean;
  lock_reason: string | null;
  availability: Record<string, unknown>;
}

export interface LmsEnrollment {
  id: number;
  course_id: number;
  user_id: number;
  student_id: number | null;
  teacher_id: number | null;
  role: EnrollmentRoleValue;
  role_label?: string;
  status: EnrollmentStatusValue;
  status_label?: string;
  enrollment_method: EnrollmentMethodValue;
  enrollment_method_label?: string;
  enrolled_at: string | null;
  start_at: string | null;
  end_at: string | null;
  suspended_at: string | null;
  completed_at: string | null;
  last_accessed_at: string | null;
  progress_percent: number;
  metadata: Record<string, unknown> | null;
  user?: { id: number; name: string; email: string | null; avatar_path: string | null };
  progress?: LmsProgress | null;
  course?: LmsCourse;
}

export interface LmsProgress {
  id: number;
  enrollment_id: number;
  course_id: number;
  total_items: number;
  required_items: number;
  completed_items: number;
  progress_percent: number;
  last_item_id: number | null;
  next_item_id: number | null;
  started_at: string | null;
  completed_at: string | null;
  computed_at: string | null;
}

export interface EnrollmentSummary {
  total: number;
  students: number;
  teachers: number;
  active: number;
  pending: number;
  suspended: number;
  completed: number;
  average_progress: number;
}

export interface CourseProgressSummary {
  enrolled: boolean;
  enrollment_id?: number;
  enrollment_status?: EnrollmentStatusValue;
  progress_percent: number;
  completed_items: number;
  required_items: number;
  total_items: number;
  next_item_id: number | null;
  last_item_id?: number | null;
  completed_at?: string | null;
  next_item?: { id: number; title: string } | null;
  sections: Array<{
    section_id: number;
    title: string;
    completed_items: number;
    required_items: number;
    progress_percent: number;
  }>;
}

export interface CourseAccessInfo {
  allowed: boolean;
  reason: string;
  message: string | null;
  blocked_by_item_id: number | null;
}

export interface CourseDetailPayload {
  course: LmsCourse;
  statistics: CourseStatistics | null;
  access: CourseAccessInfo;
  enrollment: { id: number; role: EnrollmentRoleValue; status: EnrollmentStatusValue; progress_percent: number } | null;
  capabilities: { manage: boolean; edit_content: boolean; grade: boolean; learn: boolean; read_only?: boolean };
  structure: CourseStructureSection[];
  progress: CourseProgressSummary;
  announcements: LmsAnnouncement[];
  groups: LmsGroup[];
  academic: Record<string, unknown> | null;
  resume: ResumePayload;
}

export interface ResumePayload {
  enrolled: boolean;
  enrollment_id?: number;
  next_item_id: number | null;
  last_item_id?: number | null;
  progress_percent: number;
  completed_items?: number;
  required_items?: number;
}

export interface LmsAnnouncement {
  id: number;
  course_id: number;
  title: string;
  content: string;
  excerpt?: string;
  pinned: boolean;
  published_at: string | null;
  is_published?: boolean;
  course_name?: string | null;
  created_by?: number | null;
  created_at?: string;
}

export interface LmsGroup {
  id: number;
  course_id: number;
  name: string;
  description: string | null;
  capacity: number | null;
  sort_order: number;
  members?: Array<{ id: number; enrollment_id: number; user_id: number }>;
  members_count?: number;
}

export interface LmsBookmark {
  id: number;
  course_id: number;
  learning_item_id: number | null;
  note: string | null;
  created_at: string;
  course?: { id: number; name: string; slug: string; code: string; thumbnail: string | null; status: CourseStatusValue; visibility: CourseVisibilityValue } | null;
  item?: { id: number; title: string; item_type: ItemTypeValue; resource_type: ResourceTypeValue | null; activity_type: ActivityTypeValue | null; section_name: string | null } | null;
}

export interface LmsCalendarEvent {
  type: string;
  title: string;
  date: string;
  time?: string;
  course_id: number;
  course_name: string | null;
  item_id?: number;
  announcement_id?: number;
  completed?: boolean;
  icon?: string;
}

export interface LmsAgenda {
  range: { from: string; until: string };
  today: LmsCalendarEvent[];
  this_week: LmsCalendarEvent[];
  upcoming: LmsCalendarEvent[];
}

export interface ContinueLearningCard {
  enrollment_id: number;
  course_id: number;
  name: string;
  code: string;
  thumbnail: string | null;
  progress_percent: number;
  completed_items: number;
  required_items: number;
  last_accessed_at: string | null;
  next_item: { id: number; title: string; resource_type: ResourceTypeValue | null; activity_type: ActivityTypeValue | null } | null;
}

export interface StudentDashboard {
  role: 'STUDENT';
  greeting: string;
  learner: Record<string, unknown> | null;
  stats: { courses: number; completed_courses: number; completed_items: number; average_progress: number };
  continue_learning: ContinueLearningCard[];
  my_courses: Array<ContinueLearningCard & { status: EnrollmentStatusValue; level: CourseLevelValue }>;
  upcoming: { today: LmsCalendarEvent[]; this_week: LmsCalendarEvent[]; assignments: AcademicAssignment[] };
  recent_activity: Array<{ type: 'viewed' | 'completed'; item_id: number | null; title: string | null; course_id: number; course_name: string | null; at: string | null }>;
  announcements: LmsAnnouncement[];
  bookmarks: number;
  calendar: LmsAgenda;
}

export interface TeacherDashboard {
  role: 'TEACHER';
  greeting: string;
  teacher: { id: number; name: string } | null;
  stats: {
    courses: number;
    published_courses: number;
    draft_courses: number;
    active_students: number;
    pending_grading: number;
    graded: number;
    completions_today: number;
    items_needing_setup: number;
  };
  my_courses: ManagementCard[];
  pending_grading: AcademicSubmission[];
  recent_submissions: AcademicSubmission[];
  upcoming_deadlines: AcademicAssignment[];
  course_activity: Array<{ item_title: string | null; course_name: string | null; course_id: number; user_id: number; at: string | null }>;
  announcements: LmsAnnouncement[];
  academic_coverage: AcademicCoverage[];
}

export interface AcademicAssignment {
  id: number;
  title: string;
  type?: string;
  subject?: string | null;
  class_name?: string | null;
  due_date: string | null;
  days_left: number | null;
  source?: string;
}

export interface AcademicSubmission {
  id: number;
  assignment_id: number;
  title: string | null;
  student_id: number | null;
  student_name: string | null;
  status: string;
  score: number | null;
  submitted_at: string | null;
  graded_at: string | null;
  due_date: string | null;
  source?: string;
}

export interface AcademicCoverage {
  academic_course_id: number;
  academic_course_name: string | null;
  academic_course_code: string | null;
  class_id: number | null;
  class_name: string | null;
  academic_year_id: number | null;
  lms_course_id: number | null;
  lms_course_status: CourseStatusValue | null;
  has_lms_course: boolean;
}

export interface ManagementCard {
  id: number;
  name: string;
  code: string;
  slug: string;
  thumbnail: string | null;
  status: CourseStatusValue;
  visibility: CourseVisibilityValue;
  category: string | null;
  students_count: number;
  sections_count: number;
  items_count: number;
  average_progress: number;
  updated_at: string | null;
}

export interface ChildSummary {
  student: {
    id: number;
    user_id: number | null;
    name: string;
    code: string | null;
    class_name: string | null;
    program_name: string | null;
    photo_path: string | null;
  };
  courses: number;
  completed_courses: number;
  average_progress: number;
  upcoming_assignments: AcademicAssignment[];
}

export interface ParentDashboard {
  role: 'PARENT';
  greeting: string;
  stats: { children: number; courses: number };
  children: ChildSummary[];
  policy: { can_view_grades: boolean; can_view_feedback: boolean };
}

export interface ChildOverview {
  child: ChildSummary['student'];
  stats: { courses: number; completed_courses: number; average_progress: number; upcoming_deadlines: number };
  courses: Array<{
    enrollment_id: number;
    course_id: number;
    name: string | null;
    code: string | null;
    thumbnail: string | null;
    status: EnrollmentStatusValue;
    progress_percent: number;
    completed_items: number;
    required_items: number;
    last_accessed_at: string | null;
    next_item_id: number | null;
  }>;
  upcoming: AcademicAssignment[];
  grades: { count: number; average: number | null; recent: Array<{ id: number; assessment: string | null; subject: string | null; score: number; max_score: number; grade: string | null; date: string | null }>; hidden?: boolean };
  announcements: LmsAnnouncement[];
  certificates: unknown[];
  recent_activity: StudentDashboard['recent_activity'];
}

export interface AdminDashboard {
  role: 'ADMIN';
  greeting: string;
  stats: {
    total_courses: number;
    active_courses: number;
    draft_courses: number;
    archived_courses: number;
    students: number;
    teachers: number;
    enrollments: number;
    completion_rate: number;
    pending_content: number;
    active_learning_7d: number;
    categories: number;
  };
  enrollment_trend: Array<{ label: string; month: string; total: number }>;
  completion_trend: Array<{ label: string; month: string; total: number }>;
  activity_trend: Array<{ date: string; completions: number; views: number }>;
  most_active_courses: Array<{ course_id: number; name: string | null; learners: number; average_progress: number }>;
  inactive_courses: ManagementCard[];
  recent_courses: ManagementCard[];
  integration: IntegrationHealth;
  calendar: LmsAgenda;
}

export interface IntegrationHealth {
  mode: 'integrated' | 'standalone';
  standalone: boolean;
  label: string;
  academic_source: string;
  self_enrollment: boolean;
  guest_preview: boolean;
  courses_linked?: number;
  courses_unlinked?: number;
  courses_draft?: number;
  classes_available?: number;
  academic_years_available?: number;
}

export type LmsDashboard = StudentDashboard | TeacherDashboard | ParentDashboard | AdminDashboard;

export interface LmsLookups {
  mode: 'integrated' | 'standalone';
  categories: LmsCategoryOption[];
  academic_years: Array<{ id: number; name: string; start_date: string | null; end_date: string | null; is_active: boolean }>;
  semesters: Array<{ id: number; name: string; academic_year_id: number; start_date: string | null; end_date: string | null; is_active: boolean }>;
  programs: Array<{ id: number; name: string; code: string | null }>;
  classes: Array<{ id: number; name: string; code: string | null; academic_year_id: number | null; academic_year: string | null; education_level: string | null }>;
  subjects: Array<{ id: number; code: string; name: string; credit: number | null; category: string | null }>;
  teachers: Array<{ id: number; user_id: number | null; name: string; email: string | null; nip: string | null }>;
  enums: {
    course_type: EnumOption<CourseTypeValue>[];
    status: EnumOption<CourseStatusValue>[];
    visibility: EnumOption<CourseVisibilityValue>[];
    level: EnumOption<CourseLevelValue>[];
    enrollment_method: EnumOption<EnrollmentMethodValue>[];
    enrollment_role: EnumOption<EnrollmentRoleValue>[];
    enrollment_status: EnumOption<EnrollmentStatusValue>[];
    item_type: EnumOption<ItemTypeValue>[];
    resource_type: EnumOption<ResourceTypeValue>[];
    activity_type: EnumOption<ActivityTypeValue>[];
    completion_type: EnumOption<CompletionTypeValue>[];
  };
}

export interface CourseReadiness {
  ready: boolean;
  checks: Array<{ key: string; label: string; ok: boolean; required: boolean }>;
  stats: { sections: number; items: number; activities: number; resources: number; students: number };
}

export interface CourseFormPayload {
  name: string;
  category_id?: number | null;
  academic_course_id?: number | null;
  academic_year_id?: number | null;
  semester_id?: number | null;
  program_id?: number | null;
  class_id?: number | null;
  teacher_id?: number | null;
  teachers?: Array<{ teacher_id: number; is_primary?: boolean }>;
  code?: string | null;
  short_description?: string | null;
  description?: string | null;
  learning_objectives?: string | null;
  thumbnail?: string | null;
  cover_image?: string | null;
  course_type?: CourseTypeValue;
  level?: CourseLevelValue;
  language?: string;
  start_date?: string | null;
  end_date?: string | null;
  visibility?: CourseVisibilityValue;
  is_featured?: boolean;
  max_students?: number | null;
  estimated_minutes?: number | null;
  sync_academic_enrollments?: boolean;
}

export interface LearningItemPayload {
  title: string;
  item_type: ItemTypeValue;
  resource_type?: ResourceTypeValue | null;
  activity_type?: ActivityTypeValue | null;
  description?: string | null;
  content?: string | null;
  external_url?: string | null;
  open_in?: 'NEW_TAB' | 'SAME_TAB' | 'EMBED';
  video_provider?: string | null;
  visibility?: 'VISIBLE' | 'HIDDEN';
  is_required?: boolean;
  completion_type?: CompletionTypeValue;
  completion_min_grade?: number | null;
  is_preview?: boolean;
  estimated_minutes?: number | null;
  require_previous_item?: boolean;
  required_grade?: number | null;
  required_item_ids?: number[];
  chapters?: Array<{ id?: number; title: string; content?: string | null }>;
}

/** Hasil pencarian course: irisan ringkas antara kartu manajemen & katalog. */
export interface CourseSearchResult {
  id: number;
  name: string;
  slug: string;
  code: string;
  short_description: string | null;
  thumbnail: string | null;
  level: CourseLevelValue | null;
  course_type: CourseTypeValue | null;
  status: CourseStatusValue | null;
  visibility: CourseVisibilityValue | null;
  category: string | null;
  teachers: string[];
  sections_count: number;
  items_count: number;
  students_count: number;
}

export interface LmsSearchResults {
  term: string;
  courses: CourseSearchResult[];
  items: Array<{ id: number; title: string; item_type: ItemTypeValue; resource_type: ResourceTypeValue | null; activity_type: ActivityTypeValue | null; course_id: number; course_name: string | null; section_name: string | null }>;
  teachers: Array<{ id: number; user_id: number | null; name: string; email: string | null; nip: string | null }>;
  categories: Array<{ id: number; name: string; slug: string; image: string | null; courses_count: number }>;
}
