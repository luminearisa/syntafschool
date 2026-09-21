import { api } from '@/lib/axios';
import type {
  ApiResponse,
  MasterQueryParams,
  MasterRecord,
  PaginatedResponse,
} from '@/types';

/** Bangun objek params axios dari MasterQueryParams */
function buildParams(params: MasterQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.sort_by) out.sort_by = params.sort_by;
  if (params.sort_dir) out.sort_dir = params.sort_dir;
  if (params.all) out.all = 1;
  if (params.include) out.include = params.include;
  if (params.filter) out.filter = params.filter;
  return out;
}

export const masterDataApi = {
  /** Ambil daftar resource (paginated atau all) */
  async list(resource: string, params: MasterQueryParams = {}) {
    const res = await api.get<PaginatedResponse<MasterRecord>>(
      `/master/${resource}`,
      { params: buildParams(params) },
    );
    return res.data;
  },

  /** Ambil semua data (untuk dropdown) */
  async all(
    resource: string,
    filter?: Record<string, string | number>,
  ): Promise<MasterRecord[]> {
    const res = await api.get<PaginatedResponse<MasterRecord>>(
      `/master/${resource}`,
      {
        params: {
          all: 1,
          ...(filter && Object.keys(filter).length > 0 ? { filter } : {}),
        },
      },
    );
    return res.data.data;
  },

  async show(resource: string, id: number | string) {
    const res = await api.get<ApiResponse<MasterRecord>>(
      `/master/${resource}/${id}`,
    );
    return res.data.data;
  },

  async create(resource: string, data: Record<string, unknown>) {
    const res = await api.post<ApiResponse<MasterRecord>>(
      `/master/${resource}`,
      data,
    );
    return res.data.data;
  },

  async update(
    resource: string,
    id: number | string,
    data: Record<string, unknown>,
  ) {
    const res = await api.put<ApiResponse<MasterRecord>>(
      `/master/${resource}/${id}`,
      data,
    );
    return res.data.data;
  },

  async remove(resource: string, id: number | string) {
    await api.delete(`/master/${resource}/${id}`);
  },
};
