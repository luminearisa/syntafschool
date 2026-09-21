/** Tipe modul Kurikulum Engine (A5). */

export interface SubjectGroup {
  id: number;
  code: string;
  name: string;
  description: string | null;
  sort_order: number;
  is_active: boolean;
  items_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface SubjectGroupPayload {
  code: string;
  name: string;
  description: string;
  sort_order: number | '';
  is_active: boolean;
}

export interface Program {
  id: number;
  code: string;
  name: string;
  education_level_id: number | null;
  description: string | null;
  is_active: boolean;
  education_level?: {
    id: number;
    name: string;
    jenjang: string;
    level_number: number;
  } | null;
  students_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface ProgramPayload {
  code: string;
  name: string;
  education_level_id: number | '';
  description: string;
  is_active: boolean;
}

export interface SubjectRef {
  id: number;
  code: string;
  name: string;
  category?: string | null;
  credit?: string | number | null;
}

export interface SubjectPrerequisite {
  id: number;
  subject_id: number;
  prerequisite_subject_id: number;
  notes: string | null;
  subject?: SubjectRef | null;
  prerequisite?: SubjectRef | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface SubjectPrerequisitePayload {
  subject_id: number | '';
  prerequisite_subject_id: number | '';
  notes: string;
}

export type SemesterNumber = 1 | 2;

/** Satu baris item di dalam form struktur (nilai bisa masih kosong). */
export interface CurriculumStructureItemInput {
  subject_id: number | '';
  subject_group_id: number | '' | null;
  semester: SemesterNumber;
  weekly_hours: number | '';
  is_required: boolean;
}

export interface CurriculumStructureItem {
  id: number;
  curriculum_structure_id: number;
  subject_id: number;
  subject_group_id: number | null;
  semester: number;
  weekly_hours: number;
  is_required: boolean;
  sort_order: number;
  notes: string | null;
  subject?: SubjectRef | null;
  subject_group?: { id: number; code: string; name: string } | null;
}

export interface CurriculumStructure {
  id: number;
  curriculum_id: number;
  education_level_id: number;
  academic_year_id: number | null;
  version: number;
  name: string;
  is_active: boolean;
  notes: string | null;
  items_count?: number;
  total_weekly_hours?: number;
  items?: CurriculumStructureItem[];
  curriculum?: { id: number; code: string; name: string } | null;
  education_level?: {
    id: number;
    name: string;
    jenjang: string;
    level_number: number;
  } | null;
  academic_year?: { id: number; name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface CurriculumStructurePayload {
  curriculum_id: number | '';
  education_level_id: number | '';
  academic_year_id: number | '';
  version: number | '';
  name: string;
  is_active: boolean;
  notes: string;
  items: CurriculumStructureItemInput[];
}

export interface ApplyCurriculumResult {
  created: number;
  skipped: number;
  total: number;
}

/** Mapel yang seharusnya diajarkan menurut struktur. */
export interface ExpectedSubject {
  subject_id: number;
  code: string | null;
  name: string | null;
  semester: number;
  weekly_hours: number;
  is_required: boolean;
  subject_group: { id: number; code: string; name: string } | null;
}

export interface AppliedClassSubject {
  class_subject_id: number;
  subject_id: number;
  code: string | null;
  name: string | null;
  employee_id: number | null;
  from_structure: boolean;
}

export interface ClassCurriculum {
  class: {
    id: number;
    name: string;
    code: string;
    education_level_id: number | null;
    academic_year_id: number;
  };
  structure: CurriculumStructure | null;
  subjects: ExpectedSubject[];
  applied: AppliedClassSubject[];
  missing_subject_ids: number[];
}
