/**
 * Tipe Phase 3 — Gradebook, Assessment, Rubric, Outcome & Competency.
 *
 * Semua bentuk data mengikuti kontrak API `/api/v1/lms/*` Phase 3 sehingga
 * komponen tidak perlu tahu detail backend.
 */

export interface GradeScaleEntry {
  grade: string;
  min_score: number;
  max_score: number;
  predicate: string | null;
  gpa?: number | null;
}

export interface GradeScale {
  id: number;
  code?: string | null;
  name: string;
  scope: 'SCHOOL' | 'COURSE' | 'ACADEMIC_YEAR';
  scope_label?: string;
  is_default?: boolean;
  is_active?: boolean;
  entries: GradeScaleEntry[];
}

export interface GradeWeightSummary {
  weight_policy: 'STRICT' | 'NORMALIZE';
  weight_policy_label: string;
  category_weight_total: number;
  has_categories: boolean;
  uncategorised_item_count: number;
  uncategorised_weight: number;
  is_valid: boolean;
  requires_exact_total: boolean;
  message: string;
}

export interface GradeCategory {
  id: number;
  parent_id: number | null;
  name: string;
  description: string | null;
  weight: number;
  aggregation_method: string | null;
  missing_grade_policy: string | null;
  drop_lowest: number;
  is_extra_credit: boolean;
  include_in_final: boolean;
  sort_order: number;
  item_count?: number;
}

export type GradeItemSourceValue = 'ASSIGNMENT' | 'QUIZ' | 'FORUM' | 'LESSON' | 'MANUAL' | 'EXTERNAL';

export interface GradeItem {
  id: number;
  gradebook_id: number;
  category_id: number | null;
  category_name: string | null;
  activity_id: number | null;
  activity_title?: string | null;
  source: GradeItemSourceValue;
  source_label: string;
  source_tone: string;
  name: string;
  description: string | null;
  maximum_grade: number;
  passing_grade: number | null;
  weight: number;
  grade_type: string;
  visibility: 'VISIBLE' | 'HIDDEN' | 'RELEASED_ONLY';
  visibility_label: string;
  is_extra_credit: boolean;
  include_in_final: boolean;
  rubric_id: number | null;
  rubric_name?: string | null;
  sort_order: number;
  stats?: GradeItemStatistics;
}

export interface GradeItemStatistics {
  submitted_count: number;
  graded_count: number;
  exempt_count: number;
  pending_count: number;
  missing_count: number;
  enrolled_count: number;
  average: number | null;
  median: number | null;
  highest: number | null;
  lowest: number | null;
  pass_rate: number | null;
  completion_rate: number | null;
  distribution: GradeDistributionBucket[];
}

export interface GradeDistributionBucket {
  label: string;
  min: number;
  max: number | null;
  count: number;
  percent: number;
}

export interface Gradebook {
  id: number;
  course_id: number;
  course_title?: string | null;
  name: string;
  description: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'LOCKED' | 'FROZEN' | 'ARCHIVED';
  status_label: string;
  status_tone: string;
  is_frozen: boolean;
  is_editable: boolean;
  grading_method: 'RAW' | 'PERCENTAGE' | 'LETTER' | 'POINTS';
  grading_method_label: string;
  aggregation_method: string;
  aggregation_method_label: string;
  missing_grade_policy: 'MISSING_AS_ZERO' | 'EXCLUDE' | 'PENDING';
  missing_grade_policy_label: string;
  weight_policy: 'STRICT' | 'NORMALIZE';
  extra_credit_policy: 'CAP' | 'UNCAPPED' | 'IGNORE';
  maximum_grade: number;
  passing_grade: number;
  is_parent_visible: boolean;
  is_ranking_visible: boolean;
  config_hash: string;
  weights: GradeWeightSummary;
  scale: { id: number; name: string; scope: string; entries: GradeScaleEntry[] } | null;
  categories: GradeCategory[];
}

export interface GradebookResponse {
  gradebook: Gradebook;
  items: GradeItem[];
  sources: { value: GradeItemSourceValue; label: string; tone: string; icon: string; has_evidence: boolean }[];
  scales: GradeScale[];
}

export interface AssessmentResult {
  id: number;
  user_id: number;
  grade_item_id: number;
  raw_score: number | null;
  final_score: number | null;
  maximum_score: number;
  normalized: number | null;
  percent: number | null;
  status: 'PENDING' | 'GRADED' | 'RETURNED' | 'RELEASED' | 'LOCKED' | 'EXEMPT';
  status_label: string;
  status_tone: string;
  is_exempt: boolean;
  exemption_reason: string | null;
  feedback: string | null;
  feedback_private: string | null;
  feedback_meta: { what_went_well?: string; what_to_improve?: string } | null;
  graded_at: string | null;
  released_at: string | null;
}

export interface GradeSheetRow {
  user_id: number;
  name: string | null;
  student_id: number | null;
  results: {
    grade_item_id: number;
    final_score: number | null;
    normalized: number | null;
    status: string;
    is_exempt: boolean;
    is_missing: boolean;
    feedback: string | null;
  }[];
  final: {
    normalized: number | null;
    final_score: number | null;
    letter: string | null;
    passed: boolean | null;
    is_incomplete: boolean;
  };
  release: GradeRelease | null;
}

export interface GradeSheetResponse {
  gradebook: Gradebook;
  items: GradeItem[];
  rows: GradeSheetRow[];
  statistics: GradeCourseStatistics;
  filters: Record<string, unknown>;
}

export interface GradeCourseStatistics {
  student_count: number;
  graded_count: number;
  missing_count: number;
  incomplete_count: number;
  average: number | null;
  median: number | null;
  highest: number | null;
  lowest: number | null;
  passing_grade: number;
  passed_count: number;
  failed_count: number;
  pass_rate: number | null;
  fail_rate: number | null;
  released_count: number;
  distribution: GradeDistributionBucket[];
  items: (GradeItemStatistics & { grade_item_id: number; name: string; source: string; source_label: string; category_name: string | null; weight: number })[];
}

export interface GradeRelease {
  user_id: number;
  status: 'DRAFT' | 'RELEASED' | 'REVOKED' | 'LOCKED';
  status_label: string;
  status_tone: string;
  final_score: number | null;
  normalized_score: number | null;
  letter: string | null;
  predicate: string | null;
  passed: boolean | null;
  components: GradeBreakdownComponent[];
  config_hash: string | null;
  released_at: string | null;
  is_visible: boolean;
  is_stale: boolean;
}

export interface GradeBreakdownComponent {
  name: string;
  weight: number | null;
  score: number | null;
  contribution: number | null;
  aggregation: string;
  item_count: number;
}

/** Satu komponen nilai pada tampilan siswa/wali (§32, §33). */
export interface StudentGradeItem {
  grade_item_id: number;
  name: string;
  source: GradeItemSourceValue;
  source_label: string;
  category_id: number | null;
  category_name: string | null;
  weight: number;
  maximum_grade: number;
  score: number | null;
  percent: number | null;
  status: string;
  status_label: string;
  is_missing: boolean;
  is_exempt: boolean;
  exemption_reason: string | null;
  feedback: string | null;
  feedback_meta: { what_went_well?: string; what_to_improve?: string } | null;
  graded_at: string | null;
  passed: boolean | null;
}

export interface StudentCourseGrade {
  course_id: number;
  course_title: string | null;
  course_thumbnail: string | null;
  gradebook_id: number;
  is_released: boolean;
  release: GradeRelease | null;
  display: { value: number | null; text: string; letter: string | null; percentage: string | null; points: string | null };
  current_grade: number | null;
  letter: string | null;
  predicate: string | null;
  passed: boolean | null;
  is_incomplete: boolean;
  missing_count: number;
  items: StudentGradeItem[];
  categories: {
    id: number | null;
    name: string;
    weight: number;
    value: number | null;
    aggregation: string;
    item_count: number;
    missing_count: number;
    is_extra_credit: boolean;
  }[];
  breakdown: GradeBreakdownComponent[];
  feedback: { grade_item_id: number; name: string; score: number | null; feedback: string | null; graded_at: string | null }[];
  reviews: GradeReview[];
}

export interface MyGradesResponse {
  user_id: number;
  is_self: boolean;
  courses: StudentCourseGrade[];
  summary: {
    course_count: number;
    graded_course_count: number;
    average: number | null;
    passed_count: number;
    release_pending_count: number;
  };
}

export interface ChildGradesResponse {
  child_user_id: number;
  courses: {
    course_id: number;
    course_title: string | null;
    current_grade: number | null;
    letter: string | null;
    predicate: string | null;
    passed: boolean | null;
    released_at: string | null;
    components: GradeBreakdownComponent[];
    recent_results: {
      grade_item_id: number;
      name: string;
      source: string;
      source_label: string;
      score: number | null;
      maximum: number;
      percent: number | null;
      feedback: string | null;
      graded_at: string | null;
    }[];
  }[];
  average: number | null;
  upcoming: Record<string, unknown>[];
  reviews: GradeReview[];
  can_view_feedback: boolean;
}

export interface LearningOutcome {
  id: number;
  course_id: number;
  code: string | null;
  statement: string;
  description: string | null;
  category: string | null;
  is_required: boolean;
  sort_order: number;
  status: string;
  thresholds: { developing: number; achieved: number; mastered: number };
  activity_ids: number[];
}

export interface OutcomeAchievementRow {
  learning_outcome_id: number;
  code: string | null;
  statement: string;
  status: 'NOT_ASSESSED' | 'NOT_YET' | 'DEVELOPING' | 'ACHIEVED' | 'MASTERED';
  status_label: string;
  status_tone: string;
  score: number | null;
  evidence_count: number;
  thresholds: { developing: number; achieved: number; mastered: number };
  activities: { activity_id: number; title: string | null }[];
  course_id: number;
}

/** Capaian outcome tingkat kelas (§48) — bentuk agregat, bukan per siswa. */
export interface OutcomeCourseAchievement {
  learning_outcome_id: number;
  code: string | null;
  statement: string;
  mapped_activities: number;
  student_count: number;
  assessed_count: number;
  achieved_count: number;
  achievement_rate: number | null;
  breakdown: Record<string, number>;
}

export interface CompetencyRow {
  competency_id: number;
  code: string | null;
  name: string | null;
  description: string | null;
  is_required?: boolean;
  weight?: number;
  framework?: string | null;
  activity_ids?: number[];
  status?: 'NOT_STARTED' | 'DEVELOPING' | 'COMPETENT' | 'MASTERED';
  status_label?: string;
  status_tone?: string;
  note?: string | null;
  assessed_at?: string | null;
  evidence?: CompetencyEvidence[];
}

export interface CompetencyEvidence {
  id: number;
  evidence_type: 'ACTIVITY' | 'ASSESSMENT' | 'RUBRIC' | 'MANUAL' | 'EXTERNAL';
  evidence_type_label: string;
  evidence_type_tone: string;
  title: string | null;
  score: number | null;
  note: string | null;
  activity_id: number | null;
  recorded_at: string | null;
}

export interface RubricLevel {
  id: number;
  name: string;
  description: string | null;
  score: number;
  sort_order: number;
}

export interface RubricCriterion {
  id: number;
  name: string;
  description: string | null;
  weight: number;
  maximum_score: number;
  sort_order: number;
  levels: RubricLevel[];
}

export interface Rubric {
  id: number;
  course_id: number | null;
  name: string;
  description: string | null;
  grading_method: 'POINTS' | 'WEIGHTED' | 'PERCENTAGE';
  grading_method_label: string;
  maximum_score: number;
  computed_maximum: number;
  status: 'DRAFT' | 'ACTIVE' | 'ARCHIVED';
  status_label: string;
  status_tone: string;
  is_template: boolean;
  is_shared: boolean;
  criteria: RubricCriterion[];
}

export interface RubricAssessment {
  id: number;
  rubric_id: number;
  rubric_name: string | null;
  status: 'DRAFT' | 'COMPLETED' | 'RELEASED';
  status_label: string;
  total_score: number;
  maximum_score: number;
  normalized_score: number | null;
  feedback: string | null;
  is_released: boolean;
  criteria: {
    criterion_id: number;
    criterion: string;
    weight: number;
    maximum: number;
    score: number | null;
    level: string | null;
    comment: string | null;
  }[];
}

export interface GradeReview {
  id: number;
  gradebook_id: number;
  grade_item_id: number | null;
  grade_item_name: string | null;
  user_id: number;
  status: 'PENDING' | 'UNDER_REVIEW' | 'APPROVED' | 'REJECTED' | 'CLOSED';
  status_label: string;
  status_tone: string;
  reason: string;
  current_score: number | null;
  requested_score: number | null;
  decided_score: number | null;
  decision: string | null;
  teacher_note: string | null;
  student_note: string | null;
  is_parent_visible: boolean;
  decided_at: string | null;
  created_at: string | null;
  events: {
    action: string;
    from_status: string | null;
    to_status: string | null;
    note: string | null;
    meta: Record<string, unknown> | null;
    created_at: string | null;
  }[];
}

export interface AcademicIntegrationStatus {
  standalone: boolean;
  provider: string;
  provider_label: string;
  available: boolean;
  push_enabled: boolean;
  message: string;
}

export interface GradeSyncRow {
  user_id: number;
  status: 'NOT_SYNCED' | 'SYNCING' | 'SYNCED' | 'SYNC_FAILED' | 'SKIPPED';
  status_label: string;
  status_tone: string;
  provider: string | null;
  external_type: string | null;
  external_id: string | null;
  external_ref: string | null;
  attempts: number;
  error_message: string | null;
  payload_hash: string | null;
  is_locked: boolean;
  synced_at: string | null;
  last_attempt_at: string | null;
}

export interface GradebookReleasesResponse {
  releases: Record<string, GradeRelease>;
  sync: {
    integration: AcademicIntegrationStatus;
    total: number;
    synced: number;
    failed: number;
    skipped: number;
    not_synced: number;
    locked: number;
    last_synced_at: string | null;
    rows: GradeSyncRow[];
  };
  integration: AcademicIntegrationStatus;
}

export interface StudentPerformanceResponse {
  user_id: number;
  courses: {
    course_id: number;
    course_title: string | null;
    current_grade: number | null;
    letter: string | null;
    is_released: boolean;
    completion: number | null;
    completed_items: number | null;
    required_items: number | null;
    assignments: { total: number; graded: number; average: number | null };
    quizzes: { total: number; graded: number; average: number | null };
    missing_count: number;
  }[];
  average: number | null;
  outcomes: (OutcomeAchievementRow & { course_title: string | null })[];
  competencies: (CompetencyRow & { course_title: string | null })[];
  trend: { direction: 'improving' | 'declining' | 'stable' | 'insufficient_data'; delta: number | null; previous?: number; current?: number };
}
