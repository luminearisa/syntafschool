/**
 * Tipe untuk modul A11a — Jurnal Mengajar & RPP / Modul Ajar.
 *
 * Dipisah dari `@/types` agar pengerjaan paralel tidak menyunting file bersama.
 */

export type LessonPlanStatus = 'draft' | 'submitted' | 'approved' | 'rejected';

export type JournalStatus = 'planned' | 'ongoing' | 'completed' | 'cancelled';

/** Label status RPP untuk tampilan. */
export const LESSON_PLAN_STATUS_LABELS: Record<LessonPlanStatus, string> = {
  draft: 'Draft',
  submitted: 'Diajukan',
  approved: 'Disetujui',
  rejected: 'Ditolak',
};

/** Label status jurnal untuk tampilan. */
export const JOURNAL_STATUS_LABELS: Record<JournalStatus, string> = {
  planned: 'Direncanakan',
  ongoing: 'Berlangsung',
  completed: 'Selesai',
  cancelled: 'Dibatalkan',
};

export interface LessonPlan {
  id: number;
  teacher_id: number | null;
  subject_id: number;
  education_level_id: number | null;
  class_id: number | null;
  semester_id: number;
  code: string;
  title: string;
  learning_objectives: string[];
  activities: string[];
  assessment_plan: string | null;
  media: string | null;
  references: string | null;
  duration_jp: number;
  status: LessonPlanStatus;
  approved_by: number | null;
  approved_at: string | null;
  rejection_reason: string | null;
  attachment_path: string | null;
  notes: string | null;
  created_by: number | null;
  /** Isi RPP hanya boleh diubah saat draft/ditolak. */
  is_editable: boolean;

  teacher?: { id: number; nip: string | null; full_name: string } | null;
  subject?: { id: number; code: string; name: string } | null;
  education_level?: { id: number; name: string } | null;
  school_class?: { id: number; name: string; code: string } | null;
  semester?: { id: number; name: string } | null;
  approver?: { id: number; name: string } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface LessonPlanPayload {
  teacher_id: number | '';
  subject_id: number | '';
  education_level_id: number | '';
  class_id: number | '';
  semester_id: number | '';
  code: string;
  title: string;
  learning_objectives: string[];
  activities: string[];
  assessment_plan: string;
  media: string;
  references: string;
  duration_jp: number | '';
  notes: string;
}

export interface LessonPlanQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  filter?: Record<string, string | number | boolean>;
}

export interface TeachingJournal {
  id: number;
  schedule_id: number | null;
  class_id: number;
  subject_id: number;
  teacher_id: number;
  semester_id: number;
  time_slot_id: number | null;
  date: string;
  topic: string;
  material: string | null;
  activities: string | null;
  notes: string | null;
  student_present_count: number;
  realized_jp: number;
  status: JournalStatus;
  attachment_path: string | null;
  created_by: number | null;
  is_completed: boolean;

  school_class?: { id: number; name: string; code: string } | null;
  subject?: { id: number; code: string; name: string } | null;
  teacher?: { id: number; nip: string | null; full_name: string } | null;
  semester?: { id: number; name: string } | null;
  time_slot?: {
    id: number;
    slot_number: number;
    label: string | null;
    start_time: string;
    end_time: string;
    type: string;
  } | null;
  schedule?: { id: number; start_time: string; end_time: string; day_id: number } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface TeachingJournalPayload {
  schedule_id: number | '';
  class_id: number | '';
  subject_id: number | '';
  teacher_id: number | '';
  semester_id: number | '';
  time_slot_id: number | '';
  date: string;
  topic: string;
  material: string;
  activities: string;
  notes: string;
  student_present_count: number | '';
  realized_jp: number | '';
}

export interface CompleteJournalPayload {
  realized_jp: number | '';
  student_present_count: number | '';
  activities: string;
  notes: string;
}

export interface TeachingJournalQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  from?: string;
  to?: string;
  filter?: Record<string, string | number | boolean>;
}

/** Satu jam mengajar pada jadwal hari ini. */
export interface TeachingTodayItem {
  schedule_id: number;
  class_id: number;
  subject_id: number;
  time_slot_id: number | null;
  start_time: string | null;
  end_time: string | null;
  school_class: { id: number; name: string; code: string } | null;
  subject: { id: number; code: string; name: string } | null;
  time_slot: { id: number; label: string | null } | null;
  has_journal: boolean;
  journal: TeachingJournal | null;
}

export interface TeachingToday {
  date: string;
  day_id: number;
  teacher_id: number;
  semester_id: number;
  total: number;
  journaled: number;
  pending: number;
  items: TeachingTodayItem[];
}

export interface TeachingRecapItem {
  teacher_id: number;
  teacher: { id: number; nip: string | null; full_name: string };
  scheduled_jp: number;
  realized_jp: number;
  journal_count: number;
  completed_count: number;
}

export interface TeachingRecap {
  semester_id: number;
  items: TeachingRecapItem[];
  totals: {
    teachers: number;
    scheduled_jp: number;
    realized_jp: number;
    journal_count: number;
    completed_count: number;
  };
}
