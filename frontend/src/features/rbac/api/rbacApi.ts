import { api } from '@/lib/axios';
import type {
  ApiResponse,
  PaginatedResponse,
  PermissionCollection,
  Role,
  RolePayload,
  User,
  UserPayload,
} from '@/types';

/** Parameter query daftar pengguna */
export interface UserQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  role?: string;
  is_active?: boolean;
}

function buildUserParams(params: UserQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.role) out.role = params.role;
  if (typeof params.is_active === 'boolean') {
    out.filter = { is_active: params.is_active ? 1 : 0 };
  }
  return out;
}

export const rbacApi = {
  /* ------------------------------ Pengguna ------------------------------ */
  async listUsers(params: UserQueryParams = {}) {
    const res = await api.get<PaginatedResponse<User>>('/users', {
      params: buildUserParams(params),
    });
    return res.data;
  },

  async showUser(id: number) {
    const res = await api.get<ApiResponse<User>>(`/users/${id}`);
    return res.data.data;
  },

  async createUser(payload: UserPayload) {
    const res = await api.post<ApiResponse<User>>('/users', payload);
    return res.data.data;
  },

  async updateUser(id: number, payload: UserPayload) {
    const res = await api.put<ApiResponse<User>>(`/users/${id}`, payload);
    return res.data.data;
  },

  async removeUser(id: number) {
    await api.delete(`/users/${id}`);
  },

  async toggleUserActive(id: number) {
    const res = await api.patch<ApiResponse<User>>(`/users/${id}/toggle-active`);
    return res.data.data;
  },

  /* -------------------------------- Role -------------------------------- */
  async listRoles(params: { page?: number; per_page?: number; search?: string } = {}) {
    const res = await api.get<PaginatedResponse<Role>>('/roles', { params });
    return res.data;
  },

  /** Ambil semua role (untuk dropdown) */
  async allRoles(): Promise<Role[]> {
    const res = await api.get<PaginatedResponse<Role>>('/roles', {
      params: { all: 1 },
    });
    return res.data.data;
  },

  async createRole(payload: RolePayload) {
    const res = await api.post<ApiResponse<Role>>('/roles', payload);
    return res.data.data;
  },

  async updateRole(id: number, payload: RolePayload) {
    const res = await api.put<ApiResponse<Role>>(`/roles/${id}`, payload);
    return res.data.data;
  },

  async removeRole(id: number) {
    await api.delete(`/roles/${id}`);
  },

  /* ----------------------------- Permission ----------------------------- */
  async listPermissions(): Promise<PermissionCollection> {
    const res = await api.get<ApiResponse<PermissionCollection>>('/permissions');
    return res.data.data;
  },
};
