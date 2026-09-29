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

// ---------------------------------------------------------------------------
// Fase 2 — pelaksanaan kegiatan supervisi
// ---------------------------------------------------------------------------

export interface VisitEmployeeRef {
  id: number;
  full_name: string;
  nip?: string | null;
  position?: string | null;
  employee_type?: string | null;
}

export interface VisitListRow {
  id: number;
  code: string;
  activity_kind: string;
  activity_kind_label: string;
  approach: string;
  approach_label: string;
  status: string;
  status_label: string;
  is_finalized: boolean;
  is_disputed: boolean;
  supervisor?: VisitEmployeeRef | null;
  supervisee?: VisitEmployeeRef | null;
  instrument?: { id: number; name: string; version: number } | null;
  lesson_topic?: string | null;
  observed_at?: string | null;
  duration_minutes?: number | null;
  final_score?: number | null;
  max_score?: number | null;
  grade_band?: string | null;
  grade_label?: string | null;
  grade_tone?: string | null;
  answers_count: number;
  findings_count: number;
  needs_acknowledgment: boolean;
  finalized_at?: string | null;
}

export interface VisitIndicatorOptionRow {
  id: number;
  label: string;
  descriptor?: string | null;
  score: number;
  tone: string;
  is_default: boolean;
}

export interface VisitIndicatorRow {
  id: number;
  code?: string | null;
  statement: string;
  guidance?: string | null;
  evidence_hint?: string | null;
  input_type: string;
  is_scored: boolean;
  is_required: boolean;
  allow_na: boolean;
  is_critical: boolean;
  weight: number;
  min_comment_below?: number | null;
  score_min?: number | null;
  score_max?: number | null;
  score_step?: number | null;
  score_unit?: string | null;
  score_yes?: number | null;
  score_no?: number | null;
  score_partial?: number | null;
  direction: string;
  max_score: number;
  options: VisitIndicatorOptionRow[];
}

export interface VisitSectionRow {
  id: number;
  code?: string | null;
  title: string;
  description?: string | null;
  weight: number;
  sort_order: number;
  indicators: VisitIndicatorRow[];
}

export interface VisitAnswerRow {
  indicator_id: number;
  option_id?: number | null;
  score?: number | null;
  value_number?: number | null;
  value_text?: string | null;
  value_long_text?: string | null;
  value_date?: string | null;
  value_json?: { option_ids?: number[] } | null;
  is_na: boolean;
  comment?: string | null;
  evidence_note?: string | null;
  computed_score?: number | null;
  answered_at?: string | null;
}

export interface VisitSummary {
  raw: number | null;
  max: number | null;
  final: number | null;
  sections: Array<{ section_id: number; raw: number; max: number; percent: number | null; weight: number }>;
  answered: number;
  scored_total: number;
  excluded: number;
  missing_required: number[];
}

export interface VisitConferenceRow {
  kind: string;
  held_at?: string | null;
  location?: string | null;
  participants?: Array<{ name: string; role?: string | null }> | null;
  answers?: Array<{ question: string; answer?: string | null }> | null;
  agreements?: string[] | null;
  reflection?: string | null;
  next_focus?: string | null;
}

export interface VisitFindingRow {
  id?: number;
  component: string;
  priority: string;
  indicator_id?: number | null;
  strength?: string | null;
  weakness?: string | null;
  problem?: string | null;
  cause?: string | null;
  recommendation?: string | null;
}

export interface VisitEvidenceRow {
  id: number;
  visit_answer_id?: number | null;
  kind: string;
  kind_label: string;
  url?: string | null;
  path?: string | null;
  caption?: string | null;
  visibility: string;
  created_at?: string | null;
}

export interface VisitDisputeRow {
  id: number;
  status: string;
  reason: string;
  proposed_scores?: unknown;
  attachments?: unknown;
  decision_note?: string | null;
  decided_at?: string | null;
  created_at?: string | null;
}

export interface VisitHistoryRow {
  revision: number;
  action: string;
  reason?: string | null;
  before?: Record<string, unknown> | null;
  after?: Record<string, unknown> | null;
  at?: string | null;
  actor?: string | null;
}

export interface VisitAbilities {
  execute: boolean;
  finalize: boolean;
  acknowledge: boolean;
  resolve_dispute: boolean;
  reopen: boolean;
  view_all: boolean;
}

export interface VisitDetail {
  visit: VisitListRow & {
    pre_conference_at?: string | null;
    post_conference_at?: string | null;
    summary?: string | null;
    strengths?: string | null;
    improvements?: string | null;
    revision: number;
    snapshot_checksum?: string | null;
    frozen_at?: string | null;
  };
  instrument: {
    id: number;
    code: string;
    name: string;
    description?: string | null;
    category: string;
    category_label: string;
    target_type: string;
    target_type_label: string;
    version: number;
    scoring_mode: string;
    scoring_mode_label: string;
    max_score?: number | null;
    requires_action_plan: boolean;
    grade_scale?: { code: string; name: string; scale_type: string; bands: Array<Record<string, unknown>> } | null;
  } | null;
  sections: VisitSectionRow[];
  answers: VisitAnswerRow[];
  summary: VisitSummary | null;
  band: Record<string, unknown> | null;
  checklist: string[];
  conferences: Record<string, VisitConferenceRow>;
  findings: VisitFindingRow[];
  evidence: VisitEvidenceRow[];
  acknowledgment: {
    employee_id: number;
    acknowledged_at?: string | null;
    signature_hash?: string | null;
    note?: string | null;
    is_pending: boolean;
  } | null;
  disputes: VisitDisputeRow[];
  history: VisitHistoryRow[];
  abilities: VisitAbilities;
}

export interface VisitPreview {
  summary: VisitSummary | null;
  band: Record<string, unknown> | null;
  indicator_scores: Array<{ indicator_id: number; is_na: boolean; computed_score: number | null; max_score: number }>;
}

export interface DraftAnswer {
  indicator_id: number;
  option_id?: number | null;
  option_ids?: number[];
  value_number?: number | null;
  value_text?: string | null;
  value_long_text?: string | null;
  value_date?: string | null;
  is_na: boolean;
  comment?: string | null;
  evidence_note?: string | null;
}
