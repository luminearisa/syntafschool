export interface AssessmentRow {
  id: number;
  class_id: number;
  subject_id: number;
  semester_id: number;
  teacher_id: number | null;
  assessment_type_id: number;
  name: string;
  description: string | null;
  max_score: number;
  weight: number | null;
  date: string | null;
  status: string;
  is_locked: boolean;
  school_class?: { id: number; name: string; code: string } | null;
  subject?: { id: number; code: string; name: string } | null;
  semester?: { id: number; name: string } | null;
  teacher?: { id: number; nip: string | null; full_name: string } | null;
  type?: { id: number; code: string; name: string; category: string } | null;
  scores_count: number;
  graded_count: number;
  remedials_count: number;
}

export interface AssessmentPayload {
  class_id: number | '';
  subject_id: number | '';
  semester_id: number | '';
  assessment_type_id: number | '';
  teacher_id?: number | '';
  name: string;
  description?: string;
  max_score: number | '';
  weight?: number | '';
  date?: string;
}

export interface AssessmentLookups {
  classes: { id: number; name: string; code: string; academic_year_id: number }[];
  semesters: { id: number; name: string; academic_year_id: number; start_date: string; end_date: string; academic_year?: { id: number; name: string } | null }[];
  types: { id: number; code: string; name: string; category: string }[];
  teachers: { id: number; nip: string | null; full_name: string }[];
}

export interface AssessmentSheetItem {
  student_id: number;
  nis: string;
  full_name: string;
  score_id: number | null;
  score: number | null;
  grade: string | null;
  notes: string | null;
  remedial_id: number | null;
  remedial_score_after: number | null;
  graded: boolean;
}

export interface AssessmentSheet {
  assessment: AssessmentRow;
  max_score: number;
  passing_grade: number | null;
  is_locked: boolean;
  total: number;
  graded: number;
  pending: number;
  items: AssessmentSheetItem[];
}

export interface BulkScoreEntry {
  student_id: number;
  score: number | null;
  notes?: string | null;
}
