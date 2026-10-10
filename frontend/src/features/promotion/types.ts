export interface PromotionLookups {
  classes: Array<{
    id: number;
    name: string;
    code: string;
    academic_year_id: number;
    education_level_id: number | null;
    education_level?: { id: number; name: string; level_number: number } | null;
  }>;
  academic_years: Array<{ id: number; name: string; is_active: boolean }>;
  education_levels: Array<{ id: number; name: string; jenjang?: string | null; level_number: number }>;
  semesters: Array<{
    id: number;
    academic_year_id: number;
    name: string;
    is_active: boolean;
    academic_year?: { id: number; name: string } | null;
  }>;
  statuses: Record<string, string>;
  required_approvers: string[];
}

export interface PromotionSnapshotSubject {
  subject_id: number;
  code: string | null;
  name: string | null;
  final_score: number | null;
  passed: boolean;
}

export interface PromotionSnapshot {
  captured_at: string;
  academic_year_id: number;
  class_id: number;
  rule_id: number;
  rule: {
    min_average_score: number;
    min_attendance_percent: number;
    max_unpassed_subjects: number;
    min_passing_grade: number;
    violation_point_limit: number | null;
  };
  average_score: number | null;
  subject_count: number;
  unpassed_subjects: number;
  subjects: PromotionSnapshotSubject[];
  attendance_percent: number;
  attendance_present: number;
  attendance_basis_days: number;
  violation_points: number;
  criteria: {
    average_score: boolean;
    attendance: boolean;
    unpassed_subjects: boolean;
    violation_points: boolean;
  };
  passed_all: boolean;
}

export interface PromotionApproval {
  id: number;
  promotion_decision_id: number;
  approver_role: string;
  approver_role_label: string;
  status: string;
  approved_by: number | null;
  approved_at: string | null;
  note: string | null;
  approver?: { id: number; name: string } | null;
}

export interface PromotionDecision {
  id: number;
  student_id: number;
  class_id: number;
  target_class_id: number | null;
  academic_year_id: number;
  decision: string | null;
  decision_label: string | null;
  status: string;
  status_label: string;
  snapshot: PromotionSnapshot | Record<string, never>;
  reason: string | null;
  decided_by: number | null;
  decided_at: string | null;
  student?: { id: number; nis: string | null; full_name: string } | null;
  school_class?: { id: number; name: string; code: string } | null;
  target_class?: { id: number; name: string; code: string } | null;
  academic_year?: { id: number; name: string } | null;
  approvals?: PromotionApproval[];
  created_at: string;
  updated_at: string;
}

export interface PromotionEvaluationRow {
  student: { id: number; nis: string | null; full_name: string };
  snapshot: PromotionSnapshot;
  suggested_decision: 'naik' | 'tinggal';
  existing_decision_id: number | null;
  existing_status: string | null;
}

export interface PromotionEvaluation {
  class: {
    id: number;
    name: string;
    code: string;
    academic_year_id: number;
    education_level_id: number | null;
  };
  academic_year_id: number;
  semester_id: number | null;
  rule: PromotionRule | null;
  student_count: number;
  naik_count: number;
  tinggal_count: number;
  students: PromotionEvaluationRow[];
}

export interface PromotionRule {
  id: number;
  name: string;
  education_level_id: number | null;
  academic_year_id: number;
  min_average_score: number;
  min_attendance_percent: number;
  max_unpassed_subjects: number;
  min_passing_grade: number;
  violation_point_limit: number | null;
  is_active: boolean;
  education_level?: { id: number; name: string; level_number: number } | null;
  academic_year?: { id: number; name: string } | null;
}

export interface PromotionRulePayload {
  name: string;
  education_level_id: number | null;
  academic_year_id: number;
  min_average_score: number;
  min_attendance_percent: number;
  max_unpassed_subjects: number;
  min_passing_grade: number;
  violation_point_limit: number | null;
  is_active: boolean;
}

export interface GraduationLookups {
  academic_years: Array<{ id: number; name: string; is_active: boolean }>;
  education_levels: Array<{ id: number; name: string; jenjang?: string | null; level_number: number }>;
  classes: Array<{ id: number; name: string; code: string; academic_year_id: number; education_level_id: number | null }>;
  semesters: Array<{
    id: number;
    academic_year_id: number;
    name: string;
    is_active: boolean;
    academic_year?: { id: number; name: string } | null;
  }>;
  decisions: Record<string, string>;
}

export interface Graduation {
  id: number;
  student_id: number;
  academic_year_id: number;
  graduation_number: string | null;
  decision: string;
  decision_label: string;
  average_score: number | null;
  criteria_summary: PromotionSnapshot | Record<string, never>;
  certificate_path: string | null;
  notes: string | null;
  approved_by: number | null;
  approved_at: string | null;
  student?: { id: number; nis: string | null; full_name: string } | null;
  academic_year?: { id: number; name: string } | null;
}

export interface GraduationEvaluationRow {
  student: { id: number; nis: string | null; full_name: string };
  class: { id: number; name: string };
  snapshot: PromotionSnapshot;
  average_score: number | null;
  suggested_decision: 'lulus' | 'tidak_lulus';
  existing_graduation_id: number | null;
}

export interface GraduationEvaluation {
  academic_year_id: number;
  education_level_id: number | null;
  semester_id: number | null;
  class_count: number;
  student_count: number;
  lulus_count: number;
  tidak_lulus_count: number;
  students: GraduationEvaluationRow[];
}
