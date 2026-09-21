/**
 * Tipe untuk modul A11b — Tugas (Assignment) & Pengumpulan.
 *
 * Dipisah dari `@/types` agar pengerjaan paralel tidak menyunting file bersama.
 */

export type AssignmentType = 'tugas' | 'kuis' | 'projek' | 'praktik' | 'portofolio';

export type AssignmentStatus = 'draft' | 'published' | 'closed';

export type SubmissionStatus = 'pending' | 'submitted' | 'late' | 'graded' | 'returned';

export interface Assignment {
  id: number;
  class_id: number;
  subject_id: number;
  teacher_id: number;
  semester_id: number;
  title: string;
  description: string;
  instructions: string | null;
  type: AssignmentType;
  max_score: number;
  weight: number;
  assigned_date: string;
  due_date: string;
  allow_late_submission: boolean;
  attachment_path: string | null;
  status: AssignmentStatus;
  published_at: string | null;
  created_by: number | null;

  /** Progres pengumpulan (dihitung backend). */
  submission_count: number;
  graded_count: number;
  is_overdue: boolean;

  school_class?: { id: number; name: string; code: string } | null;
  subject?: { id: number; code: string; name: string } | null;
  teacher?: { id: number; nip: string | null; full_name: string } | null;
  semester?: { id: number; name: string } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface AssignmentPayload {
  class_id: number | '';
  subject_id: number | '';
  teacher_id: number | '';
  semester_id: number | '';
  title: string;
  description: string;
  instructions: string;
  type: AssignmentType;
  max_score: number | '';
  weight: number | '';
  assigned_date: string;
  due_date: string;
  allow_late_submission: boolean;
  status?: AssignmentStatus;
}

export interface AssignmentQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  filter?: Record<string, string | number>;
  from?: string;
  to?: string;
}

export interface AssignmentSubmission {
  id: number;
  assignment_id: number;
  student_id: number;
  submitted_at: string | null;
  content: string | null;
  attachment_path: string | null;
  score: number | null;
  feedback: string | null;
  graded_by: number | null;
  graded_at: string | null;
  status: SubmissionStatus;
  is_late: boolean;
  student?: { id: number; nis: string; full_name: string } | null;
  grader?: { id: number; name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

/** Satu baris daftar pengumpulan — termasuk siswa yang belum mengumpulkan. */
export interface SubmissionRow {
  student_id: number | null;
  nis: string | null;
  full_name: string | null;
  submission_id: number | null;
  status: SubmissionStatus;
  submitted_at: string | null;
  score: number | null;
  feedback: string | null;
  graded_at: string | null;
  has_submitted: boolean;
}

export interface SubmissionListMeta {
  total_students: number;
  submitted: number;
  graded: number;
  pending: number;
}

export interface AssignmentRecapItem {
  id: number;
  title: string;
  type: AssignmentType;
  status: AssignmentStatus;
  due_date: string;
  submitted: number;
  graded: number;
  pending: number;
}

export interface AssignmentRecap {
  class_id: number;
  semester_id: number;
  total_assignments: number;
  published_assignments: number;
  total_students: number;
  expected_submissions: number;
  submitted: number;
  pending: number;
  graded: number;
  items: AssignmentRecapItem[];
}

/** Baris tugas milik satu siswa beserta status pengumpulannya. */
export interface StudentAssignmentRow {
  assignment: Assignment;
  submission: AssignmentSubmission | null;
  status: SubmissionStatus;
}

export interface SubmissionPayload {
  student_id?: number | '';
  submitted_at?: string;
  content?: string;
  attachment_path?: string;
}

export interface GradePayload {
  score: number | '';
  feedback: string;
}
