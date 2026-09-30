import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

// ---------------------------------------------------------------------------
// Analitik
// ---------------------------------------------------------------------------

export interface AnalyticsRow {
  window: { from: string; to: string; label: string };
  coverage: {
    total_employees: number;
    supervised: number;
    not_supervised: number;
    percent: number | null;
    teacher_total: number;
    teacher_supervised: number;
    staff_total: number;
    staff_supervised: number;
    unsupervised: Array<{ id: number; full_name: string; position?: string | null; is_teacher: boolean }>;
  };
  visits: {
    total: number;
    finalized: number;
    finalized_on_time_percent: number | null;
    avg_days_to_finalize: number | null;
    by_status: Record<string, number>;
    by_kind: Record<string, number>;
    planned_target: number | null;
    plan_realization_percent: number | null;
    with_dispute: number;
    reopened: number;
  };
  scores: {
    count: number;
    average: number | null;
    median: number | null;
    lowest: number | null;
    highest: number | null;
    distribution: Record<string, { count: number; average: number }>;
  };
  sections: Array<{ section: string; visits: number; average_percent: number; lowest_percent: number }>;
  weak_indicators: Array<{
    indicator_id: number;
    code?: string | null;
    statement: string;
    section?: string | null;
    max_score: number;
    answers: number;
    average_score: number;
    average_percent: number | null;
    lowest_score: number;
    is_weak: boolean;
  }>;
  trend: Array<{ period: string; visits: number; average: number | null }>;
  follow_up: {
    items_total: number;
    items_done: number;
    items_verified: number;
    items_overdue: number;
    completion_percent: number | null;
    verified_percent: number | null;
    on_time_percent: number | null;
    avg_days_to_finish: number | null;
    plans_by_status: Record<string, number>;
  };
  appraisals: {
    total: number;
    published: number;
    average: number | null;
    needs_pip: number;
    needs_pip_rows: Array<{
      id: number;
      appraisal_type: string;
      supervisee?: string | null;
      position?: string | null;
      final_score?: number | null;
      grade_label?: string | null;
    }>;
    pip_total: number;
    pip_active: number;
    pip_closed: number;
    pip_outcomes: Record<string, number>;
    pip_acknowledged_percent: number | null;
    by_predicate: Record<string, { count: number; average: number }>;
  };
  growth: {
    total_hours: number;
    entries: number;
    certified: number;
    cost: number;
    hours_by_kind: Record<string, { count: number; hours: number }>;
    hours_per_employee: number | null;
    coaching_sessions: number;
    coaching_open: number;
    coaching_follow_up_due: number;
  };
  supervisors: Array<{
    supervisor_employee_id: number;
    full_name?: string | null;
    visits: number;
    average_score: number;
    lowest_score: number;
    highest_score: number;
    deviation_from_school: number | null;
    leniency_index: number | null;
    needs_calibration: boolean;
  }>;
}

// ---------------------------------------------------------------------------
// Laporan & dokumen
// ---------------------------------------------------------------------------

export interface ReportCatalog {
  reports: Array<{ key: string; title: string }>;
  formats: string[];
  default_window: { from: string; to: string };
}

export interface ReportPreview {
  report: { key: string; title: string; window: { from: string; to: string } };
  headers: string[];
  rows: Array<Array<string | number | null>>;
  summary: Record<string, unknown>;
}

export interface ReportHistoryRow {
  id: number;
  title: string;
  report_key: string;
  format: string;
  rows_count: number | null;
  verification_code: string | null;
  verification_url: string | null;
  checksum: string | null;
  status: string;
  generated_at: string | null;
  generated_by?: string | null;
}

export interface DocumentVerification {
  valid: boolean;
  code: string;
  title?: string;
  report_key?: string;
  format?: string;
  rows_count?: number | null;
  generated_at?: string | null;
  checksum?: string;
  checksum_matches?: boolean;
  file_available?: boolean;
  status?: string;
  message: string;
}

// ---------------------------------------------------------------------------
// Kalibrasi penilai
// ---------------------------------------------------------------------------

export interface CalibrationSessionRow {
  id: number;
  title: string;
  status: string;
  held_at: string | null;
  threshold: number;
  program?: string | null;
  conclusion?: string | null;
  finalized_at?: string | null;
}

export interface CalibrationIndicator {
  label: string;
  indicator_id?: number | null;
  section?: string | null;
  max_score?: number | null;
  scores: Array<{ supervisor_employee_id: number; score: number | null; comment?: string | null }>;
  raters: number;
  average: number | null;
  spread: number | null;
  exceeds_threshold: boolean;
}

export interface CalibrationDetail {
  session: {
    id: number;
    title: string;
    status: string;
    held_at: string | null;
    threshold: number;
    note?: string | null;
    conclusion?: string | null;
    finalized_at?: string | null;
    program?: string | null;
    instrument_version_id?: number | null;
  };
  indicators: CalibrationIndicator[];
  statistics: {
    indicators: number;
    raters: number;
    average_spread: number | null;
    max_spread: number | null;
    exceeding: number;
  };
  abilities: { manage: boolean; finalize: boolean };
}

export interface DivergenceRow {
  indicator_id: number;
  statement: string;
  supervisors: Array<{ supervisor_employee_id: number; full_name?: string | null; average: number; answers: number }>;
  spread: number;
  school_average: number;
}

// ---------------------------------------------------------------------------
// Evaluasi CIPP
// ---------------------------------------------------------------------------

export interface CippRow {
  id: number;
  program?: string | null;
  program_id: number;
  scope?: string | null;
  period?: string | null;
  scores: { context: number | null; input: number | null; process: number | null; product: number | null };
  average: number | null;
  finding?: string | null;
  recommendation?: string | null;
  finalized_at?: string | null;
}

export interface CippDetail {
  evaluation: {
    id: number;
    program?: string | null;
    period?: string | null;
    model: string;
    context?: Record<string, unknown> | null;
    input?: Record<string, unknown> | null;
    process?: Record<string, unknown> | null;
    product?: Record<string, unknown> | null;
    stakeholders?: string[] | null;
    scores: { context: number | null; input: number | null; process: number | null; product: number | null };
    average: number | null;
    finding?: string | null;
    recommendation?: string | null;
    finalized_at?: string | null;
    created_at?: string | null;
  };
  evidence: Record<string, unknown>;
  guide: Record<string, string>;
  abilities: { update: boolean; finalize: boolean };
}

/** Klien API analitik, laporan, kalibrasi penilai, dan evaluasi CIPP. */
export const supervisiInsightApi = {
  async analytics(params: { from?: string; to?: string } = {}) {
    const response = await api.get<ApiResponse<AnalyticsRow>>('/supervisi/analytics', { params });
    return response.data.data;
  },

  async reportCatalog() {
    const response = await api.get<ApiResponse<ReportCatalog>>('/supervisi/reports/catalog');
    return response.data.data;
  },

  async reportPreview(payload: { report_key: string; from?: string; to?: string }) {
    const response = await api.post<ApiResponse<ReportPreview>>('/supervisi/reports/preview', payload);
    return response.data.data;
  },

  async generateReport(payload: { report_key: string; format: string; from?: string; to?: string }) {
    const response = await api.post<ApiResponse<{ id: number; verification_code: string; download_url: string }>>(
      '/supervisi/reports',
      payload,
    );
    return response.data.data;
  },

  async reportHistory() {
    const response = await api.get<ApiResponse<{ rows: ReportHistoryRow[] }>>('/supervisi/reports/history');
    return response.data.data.rows;
  },

  /** Verifikasi publik: sengaja tanpa token agar salinan cetak dapat diperiksa. */
  async verifyDocument(code: string) {
    const response = await api.get<ApiResponse<DocumentVerification>>(`/supervisi/reports/verify/${code}`);
    return response.data.data;
  },

  async downloadReport(reportId: number) {
    const response = await api.get(`/supervisi/reports/${reportId}/download`, { responseType: 'blob' });
    return response;
  },

  async calibrations(params: { status?: string; per_page?: number } = {}) {
    const response = await api.get<ApiResponse<{ rows: CalibrationSessionRow[]; meta: Record<string, number>; pending: number }>>(
      '/supervisi/calibrations',
      { params },
    );
    return response.data.data;
  },

  async calibrationDetail(sessionId: number) {
    const response = await api.get<ApiResponse<CalibrationDetail>>(`/supervisi/calibrations/${sessionId}`);
    return response.data.data;
  },

  async createCalibration(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<CalibrationDetail>>('/supervisi/calibrations', payload);
    return response.data.data;
  },

  async saveCalibrationScores(sessionId: number, scores: Array<Record<string, unknown>>) {
    const response = await api.put<ApiResponse<CalibrationDetail>>(`/supervisi/calibrations/${sessionId}/scores`, { scores });
    return response.data.data;
  },

  async finalizeCalibration(sessionId: number, conclusion: string) {
    const response = await api.post<ApiResponse<CalibrationDetail>>(`/supervisi/calibrations/${sessionId}/finalize`, { conclusion });
    return response.data.data;
  },

  async calibrationDivergence(limit = 10) {
    const response = await api.get<ApiResponse<{ rows: DivergenceRow[] }>>('/supervisi/calibrations/divergence', { params: { limit } });
    return response.data.data.rows;
  },

  async evaluations(params: { program_id?: number; period?: string } = {}) {
    const response = await api.get<
      ApiResponse<{ rows: CippRow[]; summary: Record<string, unknown>; dimensions: string[] }>
    >('/supervisi/evaluations', { params });
    return response.data.data;
  },

  async evaluationDetail(evaluationId: number) {
    const response = await api.get<ApiResponse<CippDetail>>(`/supervisi/evaluations/${evaluationId}`);
    return response.data.data;
  },

  async createEvaluation(programId: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<CippDetail>>(`/supervisi/programs/${programId}/evaluations`, payload);
    return response.data.data;
  },

  async saveEvaluation(evaluationId: number, payload: Record<string, unknown>) {
    const response = await api.patch<ApiResponse<CippDetail>>(`/supervisi/evaluations/${evaluationId}`, payload);
    return response.data.data;
  },

  async finalizeEvaluation(evaluationId: number) {
    const response = await api.post<ApiResponse<CippDetail>>(`/supervisi/evaluations/${evaluationId}/finalize`);
    return response.data.data;
  },
};
