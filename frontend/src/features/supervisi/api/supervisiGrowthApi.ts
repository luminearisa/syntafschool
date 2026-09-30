import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

// ---------------------------------------------------------------------------
// RTL (Rencana Tindak Lanjut)
// ---------------------------------------------------------------------------

export interface ActionItemRow {
  id: number;
  indicator_id?: number | null;
  statement: string;
  support_needed?: string | null;
  due_date?: string | null;
  owner?: { id: number; full_name: string } | null;
  status: 'PENDING' | 'IN_PROGRESS' | 'DONE' | 'VERIFIED' | 'DROPPED';
  progress_percent: number;
  evidence_path?: string | null;
  result_note?: string | null;
  completed_at?: string | null;
  is_overdue: boolean;
  is_due_soon: boolean;
  progress: Array<{
    id: number;
    reported_at?: string | null;
    progress_percent: number;
    note?: string | null;
    attachment_path?: string | null;
  }>;
}

export interface ActionPlanRow {
  id: number;
  title: string;
  note?: string | null;
  status: 'DRAFT' | 'ONGOING' | 'DONE' | 'VERIFIED' | 'OVERDUE' | 'DROPPED';
  period_start?: string | null;
  period_end?: string | null;
  verified_at?: string | null;
  verified_by?: number | null;
  supervisee?: { id: number; full_name: string; position?: string | null } | null;
  visit?: { id: number; code: string } | null;
  progress_percent: number;
  items: ActionItemRow[];
}

export interface ActionPlanDetail {
  plan: ActionPlanRow;
  abilities: { view_all: boolean; report: boolean; manage: boolean; verify: boolean };
}

export interface ActionPlanBoard {
  buckets: {
    overdue: number;
    due_soon: number;
    ongoing: number;
    waiting_verification: number;
    verified: number;
    draft: number;
  };
  rows: ActionPlanRow[];
}

// ---------------------------------------------------------------------------
// Penilaian kinerja
// ---------------------------------------------------------------------------

export interface AppraisalIndicatorRow {
  id: number;
  section: string;
  code?: string | null;
  statement: string;
  guidance?: string | null;
  input_type: string;
  is_scored: boolean;
  is_required: boolean;
  allow_na: boolean;
  weight: number;
  max_score: number;
  score_min?: number | null;
  score_max?: number | null;
  options: Array<{ id: number; label: string; score: number; tone: string }>;
}

export interface AppraisalRow {
  id: number;
  appraisal_type: string;
  period_type: string;
  period_start?: string | null;
  period_end?: string | null;
  status: string;
  supervisee?: { id: number; full_name: string; position?: string | null } | null;
  assessor?: { id: number; full_name: string } | null;
  raw_score?: number | null;
  max_score?: number | null;
  final_score?: number | null;
  grade_band?: string | null;
  grade_label?: string | null;
  credit_percent?: number | null;
  attendance_absence_days?: number | null;
  attendance_correction_percent?: number | null;
  result_rating?: string | null;
  behavior_rating?: string | null;
  final_predicate?: string | null;
  supervision_weight?: number | null;
  supervision_contribution?: number | null;
  snapshot?: Record<string, unknown> | null;
  published_at?: string | null;
  scores: Array<{
    indicator_id: number;
    code?: string | null;
    statement?: string | null;
    score?: number | null;
    comment?: string | null;
  }>;
}

export interface AppraisalDetail {
  appraisal: AppraisalRow;
  instrument: {
    id: number;
    code?: string | null;
    name?: string | null;
    version: number;
    scoring_mode: string;
    max_score?: number | null;
    grade_scale?: { code: string; name: string; scale_type: string; bands: Array<Record<string, unknown>> } | null;
  } | null;
  indicators: AppraisalIndicatorRow[];
  sources: Array<{
    id: number;
    source: string;
    weight: number;
    score?: number | null;
    comment?: string | null;
    submitted_at?: string | null;
  }>;
  source_weights: Record<string, number>;
  abilities: {
    view_all: boolean;
    score: boolean;
    calculate: boolean;
    publish: boolean;
    reopen: boolean;
    pip: boolean;
    acknowledge: boolean;
  };
}

export interface AppraisalReference {
  instruments: Record<string, { code: string; divisor: number; label: string }>;
  working_days: number;
  attendance_formula: string;
  predicates: Record<string, { label: string; credit_percent: number; triggers_pip: boolean }>;
  quadrant_matrix: Record<string, Record<string, string>>;
  rating_thresholds: { above: number; below: number };
  source_weights: Record<string, number>;
  notes: string[];
}

// ---------------------------------------------------------------------------
// Pengembangan, coaching, PIP
// ---------------------------------------------------------------------------

export interface DevelopmentRow {
  id: number;
  kind: string;
  title: string;
  provider?: string | null;
  hours?: number | null;
  started_at?: string | null;
  finished_at?: string | null;
  cost?: number | null;
  certificate_no?: string | null;
  status: string;
  supervisee?: { id: number; full_name: string; position?: string | null } | null;
}

export interface CoachingRow {
  id: number;
  session_at?: string | null;
  focus?: string | null;
  commitments: string[];
  follow_up_at?: string | null;
  status: string;
  note?: string | null;
  supervisor?: { id: number; full_name: string } | null;
  supervisee?: { id: number; full_name: string; position?: string | null } | null;
}

export interface PipRow {
  id: number;
  status: string;
  outcome?: string | null;
  reason?: string | null;
  expected_standard?: string | null;
  consequences_note?: string | null;
  support_provided?: string | null;
  start_date?: string | null;
  final_review_date?: string | null;
  review_cadence?: string | null;
  acknowledged_at?: string | null;
  supervisee?: { id: number; full_name: string; position?: string | null } | null;
  appraisal?: { id: number; appraisal_type: string; final_score?: number | null; grade_label?: string | null } | null;
  items: Array<{
    id: number;
    statement: string;
    measure?: string | null;
    target_value?: number | null;
    target_unit?: string | null;
    due_date?: string | null;
    status: string;
    result_note?: string | null;
  }>;
  reviews: Array<{
    id: number;
    reviewed_at?: string | null;
    progress_note?: string | null;
    next_step?: string | null;
    status?: string | null;
  }>;
  abilities: { manage: boolean; acknowledge: boolean };
}

/** Klien API tindak lanjut, penilaian kinerja, dan pengembangan pegawai. */
export const supervisiGrowthApi = {
  // RTL ---------------------------------------------------------------------
  async actionPlans(params: Record<string, unknown> = {}) {
    const response = await api.get<ApiResponse<ActionPlanBoard>>('/supervisi/action-plans', { params });
    return response.data.data;
  },

  async actionPlanDetail(planId: number) {
    const response = await api.get<ApiResponse<ActionPlanDetail>>(`/supervisi/action-plans/${planId}`);
    return response.data.data;
  },

  async createActionPlan(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<ActionPlanDetail>>('/supervisi/action-plans', payload);
    return response.data.data;
  },

  async createActionPlanFromVisit(visitId: number, payload: Record<string, unknown> = {}) {
    const response = await api.post<ApiResponse<ActionPlanDetail>>(`/supervisi/visits/${visitId}/action-plan`, payload);
    return response.data.data;
  },

  async addActionItem(planId: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<ActionPlanDetail>>(`/supervisi/action-plans/${planId}/items`, payload);
    return response.data.data;
  },

  async reportProgress(itemId: number, payload: { progress_percent: number; note?: string; attachment_path?: string }) {
    const response = await api.post<ApiResponse<ActionPlanDetail>>(`/supervisi/action-plans/items/${itemId}/progress`, payload);
    return response.data.data;
  },

  async verifyActionPlan(planId: number, note?: string) {
    const response = await api.post<ApiResponse<ActionPlanDetail>>(`/supervisi/action-plans/${planId}/verify`, { note });
    return response.data.data;
  },

  // Penilaian kinerja -------------------------------------------------------
  async appraisals(params: Record<string, unknown> = {}) {
    const response = await api.get<
      ApiResponse<{
        rows: AppraisalRow[];
        summary: Record<string, unknown>;
        types: Record<string, { code: string; divisor: number; label: string }>;
      }>
    >('/supervisi/appraisals', { params });

    return response.data.data;
  },

  async appraisalReference() {
    const response = await api.get<ApiResponse<AppraisalReference>>('/supervisi/appraisals/reference');
    return response.data.data;
  },

  async appraisalDetail(appraisalId: number) {
    const response = await api.get<ApiResponse<AppraisalDetail>>(`/supervisi/appraisals/${appraisalId}`);
    return response.data.data;
  },

  async createAppraisal(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<AppraisalDetail>>('/supervisi/appraisals', payload);
    return response.data.data;
  },

  async saveAppraisalScores(appraisalId: number, scores: Array<Record<string, unknown>>) {
    const response = await api.put<ApiResponse<AppraisalDetail>>(`/supervisi/appraisals/${appraisalId}/scores`, { scores });
    return response.data.data;
  },

  async calculateAppraisal(appraisalId: number, payload: Record<string, unknown> = {}) {
    const response = await api.post<ApiResponse<AppraisalDetail>>(`/supervisi/appraisals/${appraisalId}/calculate`, payload);
    return response.data.data;
  },

  async saveAppraisalSources(appraisalId: number, sources: Array<Record<string, unknown>>) {
    const response = await api.put<ApiResponse<AppraisalDetail>>(`/supervisi/appraisals/${appraisalId}/sources`, { sources });
    return response.data.data;
  },

  async publishAppraisal(appraisalId: number) {
    const response = await api.post<ApiResponse<AppraisalDetail>>(`/supervisi/appraisals/${appraisalId}/publish`);
    return response.data.data;
  },

  async reopenAppraisal(appraisalId: number, reason: string) {
    const response = await api.post<ApiResponse<AppraisalDetail>>(`/supervisi/appraisals/${appraisalId}/reopen`, { reason });
    return response.data.data;
  },

  // Pengembangan & coaching ------------------------------------------------
  async developments(params: Record<string, unknown> = {}) {
    const response = await api.get<
      ApiResponse<{ rows: DevelopmentRow[]; summary: Record<string, unknown> }>
    >('/supervisi/developments', { params });

    return response.data.data;
  },

  async createDevelopment(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<{ row: DevelopmentRow | null }>>('/supervisi/developments', payload);
    return response.data.data;
  },

  async coachings(params: Record<string, unknown> = {}) {
    const response = await api.get<
      ApiResponse<{ rows: CoachingRow[]; open: number; due_follow_ups: number }>
    >('/supervisi/coachings', { params });

    return response.data.data;
  },

  async createCoaching(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<{ id: number }>>('/supervisi/coachings', payload);
    return response.data.data;
  },

  async closeCoaching(sessionId: number, note: string) {
    await api.post(`/supervisi/coachings/${sessionId}/close`, { note });
  },

  // PIP --------------------------------------------------------------------
  async pips(params: Record<string, unknown> = {}) {
    const response = await api.get<ApiResponse<{ rows: PipRow[]; outcomes: string[] }>>('/supervisi/pips', { params });
    return response.data.data;
  },

  async createPip(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<PipRow>>('/supervisi/pips', payload);
    return response.data.data;
  },

  async pipDetail(pipId: number) {
    const response = await api.get<ApiResponse<PipRow>>(`/supervisi/pips/${pipId}`);
    return response.data.data;
  },

  async reviewPip(pipId: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<PipRow>>(`/supervisi/pips/${pipId}/reviews`, payload);
    return response.data.data;
  },

  async updatePipItem(itemId: number, payload: Record<string, unknown>) {
    const response = await api.patch<ApiResponse<PipRow>>(`/supervisi/pips/items/${itemId}`, payload);
    return response.data.data;
  },

  async concludePip(pipId: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<PipRow>>(`/supervisi/pips/${pipId}/conclude`, payload);
    return response.data.data;
  },

  async acknowledgePip(pipId: number) {
    const response = await api.post<ApiResponse<PipRow>>(`/supervisi/pips/${pipId}/acknowledge`);
    return response.data.data;
  },
};
