import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  AttendanceCorrection,
  AttendanceCorrectionPayload,
  AttendanceCorrectionQueryParams,
  AttendanceSheet,
  AttendanceSheetParams,
  BulkAttendancePayload,
  BulkAttendanceResult,
  ClassRecap,
  EmployeeAttendance,
  EmployeeAttendancePayload,
  EmployeeRecap,
  StudentAttendance,
  StudentAttendancePayload,
  StudentAttendanceQueryParams,
  StudentRecap,
} from '@/types/attendance';

function buildParams(params: StudentAttendanceQueryParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.from) out.from = params.from;
  if (params.to) out.to = params.to;
  if (params.filter) out.filter = params.filter;
  return out;
}

/** Bersihkan id kosong (`''`) agar tidak dikirim sebagai string kosong. */
function idOrNull(value: number | '' | undefined): number | null {
  return value === '' || value === undefined ? null : Number(value);
}

/** Presensi siswa */
export const studentAttendancesApi = {
  async list(params: StudentAttendanceQueryParams = {}) {
    const res = await api.get<PaginatedResponse<StudentAttendance>>('/student-attendances', {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<StudentAttendance>>(`/student-attendances/${id}`);
    return res.data.data;
  },

  /** Daftar siswa satu kelas + status presensinya pada satu tanggal. */
  async sheet(params: AttendanceSheetParams) {
    const query: Record<string, unknown> = {
      class_id: params.class_id,
      semester_id: params.semester_id,
      date: params.date,
    };
    if (params.schedule_id) query.schedule_id = params.schedule_id;

    const res = await api.get<ApiResponse<AttendanceSheet>>('/student-attendances/sheet', {
      params: query,
    });
    return res.data.data;
  },

  /** Simpan massal satu kelas; bersifat upsert (aman ditekan dua kali). */
  async bulk(payload: BulkAttendancePayload) {
    const body: Record<string, unknown> = {
      class_id: payload.class_id,
      semester_id: payload.semester_id,
      date: payload.date,
      entries: payload.entries,
    };
    const scheduleId = idOrNull(payload.schedule_id);
    if (scheduleId) body.schedule_id = scheduleId;
    const subjectId = idOrNull(payload.subject_id);
    if (subjectId) body.subject_id = subjectId;

    const res = await api.post<ApiResponse<BulkAttendanceResult>>(
      '/student-attendances/bulk',
      body,
    );
    return res.data;
  },

  async create(payload: StudentAttendancePayload) {
    const res = await api.post<ApiResponse<StudentAttendance>>('/student-attendances', payload);
    return res.data.data;
  },

  async update(id: number, payload: StudentAttendancePayload) {
    const res = await api.put<ApiResponse<StudentAttendance>>(
      `/student-attendances/${id}`,
      payload,
    );
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/student-attendances/${id}`);
  },
};

/** Rekap presensi */
export const attendanceRecapApi = {
  async student(studentId: number, semesterId?: number | '') {
    const params: Record<string, unknown> = {};
    if (semesterId) params.semester_id = semesterId;

    const res = await api.get<ApiResponse<StudentRecap>>(
      `/attendance-recap/student/${studentId}`,
      { params },
    );
    return res.data.data;
  },

  async class(classId: number, semesterId?: number | '') {
    const params: Record<string, unknown> = {};
    if (semesterId) params.semester_id = semesterId;

    const res = await api.get<ApiResponse<ClassRecap>>(`/attendance-recap/class/${classId}`, {
      params,
    });
    return res.data.data;
  },
};

/** Presensi pegawai (guru & staf) */
export const employeeAttendancesApi = {
  async list(
    params: {
      page?: number;
      per_page?: number;
      search?: string;
      all?: boolean;
      from?: string;
      to?: string;
      filter?: Record<string, string | number | boolean>;
    } = {},
  ) {
    const res = await api.get<PaginatedResponse<EmployeeAttendance>>('/employee-attendances', {
      params: buildParams(params),
    });
    return res.data;
  },

  /** Upsert: satu pegawai satu baris per tanggal. */
  async create(payload: EmployeeAttendancePayload) {
    const res = await api.post<ApiResponse<EmployeeAttendance>>(
      '/employee-attendances',
      payload,
    );
    return res.data.data;
  },

  async update(id: number, payload: EmployeeAttendancePayload) {
    const res = await api.put<ApiResponse<EmployeeAttendance>>(
      `/employee-attendances/${id}`,
      payload,
    );
    return res.data.data;
  },

  async remove(id: number) {
    await api.delete(`/employee-attendances/${id}`);
  },

  async recap(params: { from: string; to: string; employee_id?: number | '' }) {
    const query: Record<string, unknown> = { from: params.from, to: params.to };
    if (params.employee_id) query.employee_id = params.employee_id;

    const res = await api.get<ApiResponse<EmployeeRecap>>('/employee-attendances/recap', {
      params: query,
    });
    return res.data.data;
  },
};

/** Koreksi presensi — pengajuan, persetujuan, penolakan */
export const attendanceCorrectionsApi = {
  async list(params: AttendanceCorrectionQueryParams = {}) {
    const res = await api.get<PaginatedResponse<AttendanceCorrection>>('/attendance-corrections', {
      params: buildParams(params),
    });
    return res.data;
  },

  async show(id: number) {
    const res = await api.get<ApiResponse<AttendanceCorrection>>(`/attendance-corrections/${id}`);
    return res.data.data;
  },

  async create(payload: AttendanceCorrectionPayload) {
    const res = await api.post<ApiResponse<AttendanceCorrection>>(
      '/attendance-corrections',
      payload,
    );
    return res.data.data;
  },

  /** Menyetujui koreksi sekaligus menerapkan status baru ke presensi. */
  async approve(id: number) {
    const res = await api.post<ApiResponse<AttendanceCorrection>>(
      `/attendance-corrections/${id}/approve`,
    );
    return res.data.data;
  },

  async reject(id: number, reason: string) {
    const res = await api.post<ApiResponse<AttendanceCorrection>>(
      `/attendance-corrections/${id}/reject`,
      { reason },
    );
    return res.data.data;
  },
};
