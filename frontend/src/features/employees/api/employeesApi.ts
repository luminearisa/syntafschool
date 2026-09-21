import { api } from '@/lib/axios';
import type {
  ApiResponse,
  Employee,
  EmployeeAccountPayload,
  EmployeePayload,
  EmployeeQueryParams,
  PaginatedResponse,
  User,
} from '@/types';

function buildParams(params: EmployeeQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.teachers_only) out.teachers_only = 1;

  const filter: Record<string, string | number> = {};
  if (params.employee_type) filter.employee_type = params.employee_type;
  if (params.employment_status) filter.employment_status = params.employment_status;
  if (typeof params.is_active === 'boolean') {
    filter.is_active = params.is_active ? 1 : 0;
  }
  if (Object.keys(filter).length > 0) out.filter = filter;

  return out;
}

export const employeesApi = {
  async list(params: EmployeeQueryParams = {}) {
    const res = await api.get<PaginatedResponse<Employee>>('/employees', {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<Employee>>(`/employees/${id}`);
    return res.data.data;
  },

  async create(payload: EmployeePayload) {
    const res = await api.post<ApiResponse<Employee>>('/employees', payload);
    return res.data.data;
  },

  async update(id: number, payload: EmployeePayload) {
    const res = await api.put<ApiResponse<Employee>>(`/employees/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/employees/${id}`);
  },

  async toggleActive(id: number) {
    const res = await api.patch<ApiResponse<Employee>>(`/employees/${id}/toggle-active`);
    return res.data.data;
  },

  async createAccount(id: number, payload: EmployeeAccountPayload) {
    const res = await api.post<ApiResponse<User>>(`/employees/${id}/account`, payload);
    return res.data.data;
  },
};
