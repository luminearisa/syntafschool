import { api } from '@/lib/axios';
import type { PaginatedResponse } from '@/types';

export interface LookupItem {
  id: number;
  [key: string]: unknown;
}

/** Daftar siswa (ringkas) untuk pemilihan rombel */
export const studentLookupApi = {
  async list(search = '') {
    const res = await api.get<PaginatedResponse<LookupItem>>('/students', {
      params: { all: 1, search: search || undefined },
    });
    return res.data.data;
  },
};

/** Daftar pegawai (ringkas) untuk pemilihan wali kelas */
export const employeeLookupApi = {
  async list(search = '', teachersOnly = false) {
    const res = await api.get<PaginatedResponse<LookupItem>>('/employees', {
      params: { all: 1, search: search || undefined, teachers_only: teachersOnly ? 1 : undefined },
    });
    return res.data.data;
  },
};
