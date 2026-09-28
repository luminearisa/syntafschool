export type ReportCardStatus = 'draft' | 'in_progress' | 'ready_for_review' | 'approved' | 'published' | string;

export interface ReportCardItem {
  id: number;
  subject_id: number;
  subject: { id: number; code: string; name: string } | null;
  score: number | null;
  grade: string | null;
  predicate: string | null;
  description: string | null;
  teacher_notes: string | null;
  position: number;
}

export interface ReportCard {
  id: number;
  student_id: number;
  class_id: number;
  semester_id: number;
  academic_year_id: number;
  type: 'tengah_semester' | 'semester' | 'akhir' | string;
  type_label: string;
  status: ReportCardStatus;
  status_label: string;
  average_score: number | null;
  rank: number | null;
  attendance_summary: {
    present?: number;
    late?: number;
    sick?: number;
    permitted?: number;
    absent?: number;
    percentage?: number;
    effective_days?: number | null;
    recorded_days?: number;
    basis?: string;
  };
  notes: string | null;
  homeroom_note: string | null;
  published_at: string | null;
  student?: { id: number; nis: string | null; full_name: string } | null;
  school_class?: { id: number; name: string; code: string } | null;
  semester?: { id: number; name: string } | null;
  academic_year?: { id: number; name: string } | null;
  items?: ReportCardItem[];
  items_count?: number;
}

export interface ReportCardLookups {
  classes: { id: number; name: string; code: string; academic_year_id: number }[];
  semesters: { id: number; name: string; academic_year_id: number; academic_year?: { id: number; name: string } | null }[];
}
