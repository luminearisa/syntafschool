import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  ApplyCurriculumResult,
  ClassCurriculum,
  CurriculumStructure,
  CurriculumStructurePayload,
  Program,
  ProgramPayload,
  SubjectGroup,
  SubjectGroupPayload,
  SubjectPrerequisite,
  SubjectPrerequisitePayload,
} from '@/types/curriculum';

function buildFilterParams(params: {
  search?: string;
  all?: boolean;
  per_page?: number;
  filter?: Record<string, string | number | boolean>;
}) {
  const query: Record<string, unknown> = {};
  if (params.search) query.search = params.search;
  if (params.all) query.all = 1;
  if (params.per_page) query.per_page = params.per_page;
  if (params.filter && Object.keys(params.filter).length > 0) query.filter = params.filter;
  return query;
}

/* ===================== Kelompok mata pelajaran ===================== */

export const subjectGroupsApi = {
  async list(params: { search?: string; all?: boolean; per_page?: number } = {}) {
    const res = await api.get<PaginatedResponse<SubjectGroup>>('/subject-groups', {
      params: buildFilterParams(params),
    });
    return res.data;
  },

  async create(payload: SubjectGroupPayload) {
    const res = await api.post<ApiResponse<SubjectGroup>>('/subject-groups', payload);
    return res.data.data;
  },

  async update(id: number, payload: SubjectGroupPayload) {
    const res = await api.put<ApiResponse<SubjectGroup>>(`/subject-groups/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/subject-groups/${id}`);
  },
};

/* ========================= Program/peminatan ======================= */

export const programsApi = {
  async list(
    params: { search?: string; all?: boolean; per_page?: number; education_level_id?: number | '' } = {},
  ) {
    const query = buildFilterParams(params);
    if (params.education_level_id) {
      query.filter = { ...(query.filter as object), education_level_id: params.education_level_id };
    }

    const res = await api.get<PaginatedResponse<Program>>('/programs', { params: query });
    return res.data;
  },

  async create(payload: ProgramPayload) {
    const res = await api.post<ApiResponse<Program>>('/programs', payload);
    return res.data.data;
  },

  async update(id: number, payload: ProgramPayload) {
    const res = await api.put<ApiResponse<Program>>(`/programs/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/programs/${id}`);
  },
};

/* ========================= Prasyarat mapel ========================= */

export const subjectPrerequisitesApi = {
  async list(params: { search?: string; all?: boolean; per_page?: number; subject_id?: number | '' } = {}) {
    const query = buildFilterParams(params);
    if (params.subject_id) {
      query.filter = { subject_id: params.subject_id };
    }

    const res = await api.get<PaginatedResponse<SubjectPrerequisite>>('/subject-prerequisites', {
      params: query,
    });
    return res.data;
  },

  async create(payload: SubjectPrerequisitePayload) {
    const res = await api.post<ApiResponse<SubjectPrerequisite>>('/subject-prerequisites', payload);
    return res.data.data;
  },

  async update(id: number, payload: SubjectPrerequisitePayload) {
    const res = await api.put<ApiResponse<SubjectPrerequisite>>(`/subject-prerequisites/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/subject-prerequisites/${id}`);
  },
};

/* ========================= Struktur kurikulum ====================== */

export interface StructureQuery {
  curriculum_id?: number | '';
  education_level_id?: number | '';
  academic_year_id?: number | '';
  is_active?: boolean;
  search?: string;
  all?: boolean;
  per_page?: number;
}

export const curriculumStructuresApi = {
  async list(params: StructureQuery = {}) {
    const filter: Record<string, string | number | boolean> = {};
    if (params.curriculum_id) filter.curriculum_id = params.curriculum_id;
    if (params.education_level_id) filter.education_level_id = params.education_level_id;
    if (params.academic_year_id) filter.academic_year_id = params.academic_year_id;
    if (params.is_active !== undefined) filter.is_active = params.is_active;

    const res = await api.get<PaginatedResponse<CurriculumStructure>>('/curriculum-structures', {
      params: buildFilterParams({ ...params, filter }),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<CurriculumStructure>>(`/curriculum-structures/${id}`);
    return res.data.data;
  },

  async byCurriculum(curriculumId: number) {
    const res = await api.get<ApiResponse<CurriculumStructure[]>>(
      `/curricula/${curriculumId}/structures`,
    );
    return res.data.data;
  },

  async create(payload: CurriculumStructurePayload) {
    const res = await api.post<ApiResponse<CurriculumStructure>>('/curriculum-structures', payload);
    return res.data.data;
  },

  async update(id: number, payload: CurriculumStructurePayload) {
    const res = await api.put<ApiResponse<CurriculumStructure>>(`/curriculum-structures/${id}`, payload);
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/curriculum-structures/${id}`);
  },

  async duplicate(id: number, payload: { version: number; name?: string }) {
    const res = await api.post<ApiResponse<CurriculumStructure>>(
      `/curriculum-structures/${id}/duplicate`,
      payload,
    );
    return res.data.data;
  },
};

/* ==================== Kurikulum yang berlaku di kelas ============== */

export const classCurriculumApi = {
  async get(classId: number, curriculumId: number | '' = '') {
    const res = await api.get<ApiResponse<ClassCurriculum>>(`/classes/${classId}/curriculum`, {
      params: curriculumId ? { curriculum_id: curriculumId } : undefined,
    });
    return res.data.data;
  },

  async apply(classId: number, structureId: number) {
    const res = await api.post<ApiResponse<ApplyCurriculumResult>>(
      `/classes/${classId}/apply-curriculum`,
      { curriculum_structure_id: structureId },
    );
    return res.data;
  },
};
