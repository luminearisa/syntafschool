import { api } from '@/lib/axios';
import type {
  ApiResponse,
  Guardian,
  GuardianPayload,
  GuardianQueryParams,
  PaginatedResponse,
} from '@/types';

function buildParams(params: GuardianQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.unlinked) out.unlinked = 1;
  if (params.student_id) out.student_id = params.student_id;

  const filter: Record<string, string | number> = {};
  if (params.occupation_id) filter.occupation_id = params.occupation_id;
  if (Object.keys(filter).length > 0) out.filter = filter;

  return out;
}

export const guardiansApi = {
  async list(params: GuardianQueryParams = {}) {
    const res = await api.get<PaginatedResponse<Guardian>>('/guardians', {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<Guardian>>(`/guardians/${id}`);
    return res.data.data;
  },

  async create(payload: GuardianPayload) {
    const res = await api.post<ApiResponse<Guardian>>('/guardians', payload);
    return res.data.data;
  },

  async update(id: number, payload: GuardianPayload) {
    const res = await api.put<ApiResponse<Guardian>>(`/guardians/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/guardians/${id}`);
  },
};
