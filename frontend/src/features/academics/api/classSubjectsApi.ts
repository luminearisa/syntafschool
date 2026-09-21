import { api } from '@/lib/axios';
import type {
  ApiResponse,
  ClassSubject,
  ClassSubjectCopyResult,
  ClassSubjectInput,
} from '@/types';

/** Mapel per kelas — struktur pengajaran sebuah rombel */
export const classSubjectsApi = {
  async list(classId: number) {
    const res = await api.get<ApiResponse<ClassSubject[]>>(
      `/classes/${classId}/subjects`,
    );
    return res.data.data;
  },

  async add(classId: number, subjects: ClassSubjectInput[]) {
    const res = await api.post<ApiResponse<ClassSubjectCopyResult>>(
      `/classes/${classId}/subjects`,
      { subjects },
    );
    return res.data;
  },

  async setTeacher(classId: number, classSubjectId: number, employeeId: number | null) {
    const res = await api.put<ApiResponse<ClassSubject>>(
      `/classes/${classId}/subjects/${classSubjectId}`,
      { employee_id: employeeId },
    );
    return res.data.data;
  },

  async remove(classId: number, classSubjectId: number) {
    await api.delete(`/classes/${classId}/subjects/${classSubjectId}`);
  },

  async copyFrom(classId: number, sourceClassId: number) {
    const res = await api.post<ApiResponse<ClassSubjectCopyResult>>(
      `/classes/${classId}/subjects/copy`,
      { source_class_id: sourceClassId },
    );
    return res.data;
  },
};
