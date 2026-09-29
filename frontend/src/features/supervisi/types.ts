/** Tipe modul Supervisi (Fase 1: pengaturan supervisor & instrumen). */

export interface SupervisorKindOption {
  value: string;
  label: string;
  may_finalize: boolean;
  may_appraise: boolean;
}

export interface Supervisor {
  id: number;
  employee_id: number;
  full_name?: string | null;
  nip?: string | null;
  position?: string | null;
  employee_type?: string | null;
  kind: string;
  kind_label: string;
  is_active: boolean;
  can_finalize: boolean;
  can_appraise: boolean;
  max_active_cases?: number | null;
  active_from?: string | null;
  active_to?: string | null;
  scope_type?: string | null;
  scope_id?: number | null;
  subject_ids?: number[] | null;
  categories?: string[] | null;
  certification_no?: string | null;
  notes?: string | null;
}

export interface SupervisorCandidate {
  employee_id: number;
  full_name: string;
  nip?: string | null;
  position?: string | null;
  employee_type?: string | null;
  suggested_kind: string;
  suggested_can_finalize: boolean;
}

export interface CoverageResponse {
  rows: Array<{
    assignment_id: number;
    program?: string | null;
    supervisor?: string | null;
    supervisee?: string | null;
    employee_type?: string | null;
    role: string;
    approach: string;
    min_visits: number;
  }>;
  warnings: string[];
  summary: { supervisors_active: number; assignments_active: number; supervisees: number };
}

export interface InputTypeOption {
  value: string;
  label: string;
  is_scored: boolean;
  uses_options: boolean;
  hint: string;
}

export interface BuilderMeta {
  categories: Array<{ value: string; label: string; is_appraisal: boolean }>;
  target_types: Array<{ value: string; label: string }>;
  input_types: InputTypeOption[];
  scoring_modes: Array<{ value: string; label: string; yields_percent: boolean }>;
  directions: Array<{ value: string; label: string }>;
  grade_scales: Array<{ id: number; code: string; name: string; scale_type: string; bands: Array<Record<string, unknown>>; is_default: boolean }>;
  scale_presets: Array<{ code: string; label: string; options: Array<{ label: string; score: number; tone: string }> }>;
  recommended_supervisors: Record<string, string[]>;
}

export interface InstrumentVersion {
  id: number;
  instrument_id: number;
  version: number;
  status: string;
  status_label: string;
  scoring_mode: string;
  scoring_mode_label: string;
  scale_type: string;
  grade_scale_id?: number | null;
  max_score?: number | null;
  requires_action_plan: boolean;
  allows_multi_source: boolean;
  guidance?: string | null;
  change_note?: string | null;
  is_editable: boolean;
  final_scale?: string;
  checksum?: string | null;
  published_at?: string | null;
}

export interface Instrument {
  id: number;
  code: string;
  name: string;
  category: string;
  category_label: string;
  target_type: string;
  target_type_label: string;
  description?: string | null;
  is_system: boolean;
  is_active: boolean;
  versions_count: number;
  current_version?: InstrumentVersion | null;
  recommended_supervisor_kinds?: string[];
}

export interface IndicatorOption {
  id?: number;
  label: string;
  descriptor?: string | null;
  score: number;
  tone: string;
  is_default?: boolean;
  sort_order?: number;
}

export interface DraftIndicator {
  id?: number;
  code?: string | null;
  statement: string;
  guidance?: string | null;
  input_type: string;
  scale_code?: string | null;
  score_min?: number | null;
  score_max?: number | null;
  score_step?: number | null;
  score_decimals?: number;
  score_unit?: string | null;
  direction?: string;
  score_yes?: number | null;
  score_no?: number | null;
  score_partial?: number | null;
  is_scored?: boolean;
  is_required?: boolean;
  allow_na?: boolean;
  is_critical?: boolean;
  weight?: number;
  min_comment_below?: number | null;
  evidence_hint?: string | null;
  max_score?: number;
  options: IndicatorOption[];
}

export interface DraftSection {
  id?: number;
  code?: string | null;
  title: string;
  description?: string | null;
  weight: number;
  sort_order?: number;
  indicators: DraftIndicator[];
}

export interface VersionDetail extends InstrumentVersion {
  instrument?: Instrument;
  structure: DraftSection[];
  checklist: string[];
}
