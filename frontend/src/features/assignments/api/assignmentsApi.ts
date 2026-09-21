import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  Assignment,
  AssignmentPayload,
  AssignmentQueryParams,
  AssignmentRecap,
  AssignmentSubmission,
  GradePayload,
  StudentAssignmentRow,
  SubmissionListMeta,
  SubmissionPayload,
  SubmissionRow,
} from '@/types/assignments';

function buildParams(params: AssignmentQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.filter) out.filter = params.filter;
  if (params.from) out.from = params.from;
  if (params.to) out.to = params.to;
  return out;
}

/** Tugas & pengumpulan */
export const assignmentsApi = {
  async list(params: AssignmentQueryParams = {}) {
    const res = await api.get<PaginatedResponse<Assignment>>('/assignments', {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<Assignment>>(`/assignments/${id}`);
    return res.data.data;
  },

  async create(payload: AssignmentPayload) {
    const res = await api.post<ApiResponse<Assignment>>('/assignments', payload);
    return res.data.data;
  },

  async update(id: number, payload: AssignmentPayload) {
    const res = await api.put<ApiResponse<Assignment>>(`/assignments/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/assignments/${id}`);
  },

  async publish(id: number) {
    const res = await api.post<ApiResponse<Assignment>>(`/assignments/${id}/publish`);
    return res.data.data;
  },

  async close(id: number) {
    const res = await api.post<ApiResponse<Assignment>>(`/assignments/${id}/close`);
    return res.data.data;
  },

  async recap(classId: number | '', semesterId: number | '') {
    const res = await api.get<ApiResponse<AssignmentRecap>>('/assignments/recap', {
      params: { class_id: classId, semester_id: semesterId },
    });
    return res.data.data;
  },

  async submissions(assignmentId: number) {
    const res = await api.get<ApiResponse<SubmissionRow[]> & { meta: SubmissionListMeta }>(
      `/assignments/${assignmentId}/submissions`,
    );
    return { items: res.data.data, meta: res.data.meta };
  },

  async submit(assignmentId: number, payload: SubmissionPayload) {
    const res = await api.post<ApiResponse<AssignmentSubmission>>(
      `/assignments/${assignmentId}/submissions`,
      payload,
    );
    return res.data.data;
  },

  async updateSubmission(assignmentId: number, submissionId: number, payload: SubmissionPayload) {
    const res = await api.put<ApiResponse<AssignmentSubmission>>(
      `/assignments/${assignmentId}/submissions/${submissionId}`,
      payload,
    );
    return res.data.data;
  },

  async grade(assignmentId: number, submissionId: number, payload: GradePayload) {
    const res = await api.post<ApiResponse<AssignmentSubmission>>(
      `/assignments/${assignmentId}/submissions/${submissionId}/grade`,
      payload,
    );
    return res.data.data;
  },

  async removeSubmission(assignmentId: number, submissionId: number) {
    await api.delete(`/assignments/${assignmentId}/submissions/${submissionId}`);
  },

  async studentAssignments(studentId: number, semesterId: number | '') {
    const res = await api.get<ApiResponse<StudentAssignmentRow[]>>(
      `/students/${studentId}/assignments`,
      { params: { semester_id: semesterId || undefined } },
    );
    return res.data.data;
  },
};
