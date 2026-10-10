import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  Graduation,
  GraduationEvaluation,
  GraduationLookups,
  PromotionDecision,
  PromotionEvaluation,
  PromotionLookups,
  PromotionRule,
  PromotionRulePayload,
} from '../types';

export interface PromotionDecisionListParams {
  page?: number;
  per_page?: number;
  class_id?: number | '';
  academic_year_id?: number | '';
  status?: string;
  decision?: string;
  search?: string;
}

export interface PromotionRuleListParams {
  page?: number;
  per_page?: number;
  academic_year_id?: number | '';
  education_level_id?: number | '';
  is_active?: boolean;
}

export interface GraduationListParams {
  page?: number;
  per_page?: number;
  academic_year_id?: number | '';
  decision?: string;
  search?: string;
}

export interface PromotionBulkDecision {
  student_id: number;
  decision: 'naik' | 'tinggal';
  target_class_id?: number | null;
  semester_id?: number | null;
  reason?: string | null;
}

export interface GraduationBulkRow {
  student_id: number;
  decision: 'lulus' | 'tidak_lulus';
  school_class_id?: number | null;
  semester_id?: number | null;
  notes?: string | null;
}

export const promotionApi = {
  async lookups() {
    const response = await api.get<ApiResponse<PromotionLookups>>('/promotion-decisions/lookups');
    return response.data.data;
  },

  async rules(params: PromotionRuleListParams = {}) {
    const query: Record<string, unknown> = {};
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
    if (params.academic_year_id) query.academic_year_id = params.academic_year_id;
    if (params.education_level_id) query.education_level_id = params.education_level_id;
    if (typeof params.is_active === 'boolean') query.is_active = params.is_active ? 1 : 0;
    const response = await api.get<PaginatedResponse<PromotionRule>>('/promotion-rules', { params: query });
    return response.data;
  },

  async createRule(payload: PromotionRulePayload) {
    const response = await api.post<ApiResponse<PromotionRule>>('/promotion-rules', payload);
    return response.data.data;
  },

  async updateRule(id: number, payload: PromotionRulePayload) {
    const response = await api.put<ApiResponse<PromotionRule>>(`/promotion-rules/${id}`, payload);
    return response.data.data;
  },

  async removeRule(id: number) {
    await api.delete(`/promotion-rules/${id}`);
  },

  async decisions(params: PromotionDecisionListParams = {}) {
    const query: Record<string, unknown> = {};
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
    if (params.class_id) query.class_id = params.class_id;
    if (params.academic_year_id) query.academic_year_id = params.academic_year_id;
    if (params.status) query.status = params.status;
    if (params.decision) query.decision = params.decision;
    if (params.search) query.search = params.search;
    const response = await api.get<PaginatedResponse<PromotionDecision>>('/promotion-decisions', { params: query });
    return response.data;
  },

  async evaluate(payload: { class_id: number; semester_id?: number | null }) {
    const response = await api.post<ApiResponse<PromotionEvaluation>>('/promotion-decisions/evaluate', payload);
    return response.data.data;
  },

  async bulkDecisions(payload: { class_id: number; decisions: PromotionBulkDecision[] }) {
    const response = await api.post<ApiResponse<PromotionDecision[]>>('/promotion-decisions/bulk', payload);
    return response.data.data;
  },

  async decision(id: number) {
    const response = await api.get<ApiResponse<PromotionDecision>>(`/promotion-decisions/${id}`);
    return response.data.data;
  },

  async updateDecision(id: number, payload: { decision?: string | null; target_class_id?: number | null; reason?: string | null }) {
    const response = await api.patch<ApiResponse<PromotionDecision>>(`/promotion-decisions/${id}`, payload);
    return response.data.data;
  },

  async submitDecision(id: number) {
    const response = await api.post<ApiResponse<PromotionDecision>>(`/promotion-decisions/${id}/submit`);
    return response.data.data;
  },

  async approveDecision(id: number, payload: { approver_role?: string | null; note?: string | null } = {}) {
    const response = await api.post<ApiResponse<PromotionDecision>>(`/promotion-decisions/${id}/approve`, payload);
    return response.data.data;
  },

  async finalizeDecision(id: number) {
    const response = await api.post<ApiResponse<PromotionDecision>>(`/promotion-decisions/${id}/finalize`);
    return response.data.data;
  },

  async reopenDecision(id: number, reason: string) {
    const response = await api.post<ApiResponse<PromotionDecision>>(`/promotion-decisions/${id}/reopen`, { reason });
    return response.data.data;
  },

  async removeDecision(id: number) {
    await api.delete(`/promotion-decisions/${id}`);
  },

  async graduationLookups() {
    const response = await api.get<ApiResponse<GraduationLookups>>('/graduations/lookups');
    return response.data.data;
  },

  async graduations(params: GraduationListParams = {}) {
    const query: Record<string, unknown> = {};
    if (params.page) query.page = params.page;
    if (params.per_page) query.per_page = params.per_page;
    if (params.academic_year_id) query.academic_year_id = params.academic_year_id;
    if (params.decision) query.decision = params.decision;
    if (params.search) query.search = params.search;
    const response = await api.get<PaginatedResponse<Graduation>>('/graduations', { params: query });
    return response.data;
  },

  async evaluateGraduation(payload: { academic_year_id: number; education_level_id?: number | null; class_id?: number | null; semester_id?: number | null }) {
    const response = await api.post<ApiResponse<GraduationEvaluation>>('/graduations/evaluate', payload);
    return response.data.data;
  },

  async bulkGraduations(payload: { academic_year_id: number; graduations: GraduationBulkRow[] }) {
    const response = await api.post<ApiResponse<Graduation[]>>('/graduations/bulk', payload);
    return response.data.data;
  },

  async graduation(id: number) {
    const response = await api.get<ApiResponse<Graduation>>(`/graduations/${id}`);
    return response.data.data;
  },

  async updateGraduation(id: number, payload: { decision?: string; certificate_path?: string | null; notes?: string | null }) {
    const response = await api.patch<ApiResponse<Graduation>>(`/graduations/${id}`, payload);
    return response.data.data;
  },

  async approveGraduation(id: number) {
    const response = await api.post<ApiResponse<Graduation>>(`/graduations/${id}/approve`);
    return response.data.data;
  },

  async removeGraduation(id: number) {
    await api.delete(`/graduations/${id}`);
  },
};
