import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  BoardingAchievement,
  BoardingAchievementOptions,
  BoardingAchievementSummary,
  BoardingAchievementPayload,
  BoardingActivity,
  BoardingActivityPayload,
  BoardingActivitySchedule,
  BoardingAttendance,
  BoardingAttendancePayload,
  BoardingAttendanceMonitoring,
  BoardingAttendanceRecap,
  BoardingAttendanceStatistics,
  BoardingAttendanceRoster,
  BoardingAttendanceType,
  BoardingAttendanceTypePayload,
  BoardingAuditEntry,
  BoardingBed,
  BoardingBedBulkPayload,
  BoardingBedPayload,
  BoardingBuilding,
  BoardingBuildingPayload,
  BoardingDisciplinaryAction,
  BoardingDisciplinaryActionPayload,
  BoardingDormitory,
  BoardingDormitoryPayload,
  BoardingFloor,
  BoardingFloorPayload,
  BoardingGroup,
  BoardingItemDeposit,
  BoardingItemDepositItem,
  BoardingItemDepositPayload,
  BoardingGroupMember,
  BoardingGroupPayload,
  BoardingHealthPayload,
  BoardingHealthRecord,
  BoardingHomecoming,
  BoardingHomecomingPayload,
  BoardingIsolatePayload,
  BoardingListParams,
  BoardingMusyrif,
  BoardingMusyrifPayload,
  BoardingPermission,
  BoardingPermissionPayload,
  BoardingPlacement,
  BoardingPlacementBatchPayload,
  BoardingPlacementPayload,
  BoardingPointsSummary,
  BoardingPortalChild,
  BoardingPortalSummary,
  BoardingReport,
  BoardingReportKind,
  BoardingRoom,
  BoardingRoomOccupancy,
  BoardingRoomPayload,
  BoardingSchedulePayload,
  BoardingSettings,
  BoardingTransferPayload,
  BoardingViolation,
  BoardingViolationPayload,
  BoardingViolationType,
  BoardingViolationTypePayload,
  BoardingVisitor,
  BoardingVisitorPayload,
  BoardingWeeklyDay,
} from '@/types/boarding';

/**
 * Boarding memakai parameter query DATAR (`?student_id=5&status=open`),
 * bukan `filter[...]` seperti Tahfiz — service backend membaca
 * `$request->query()` apa adanya.
 */
function buildParams(params: BoardingListParams = {}): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  for (const [key, value] of Object.entries(params.filter ?? {})) {
    if (value !== undefined && value !== null && value !== '') {
      out[key] = typeof value === 'boolean' ? (value ? 1 : 0) : value;
    }
  }
  return out;
}

function stripEmpty<T extends object>(payload: T): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(payload)) {
    if (value === '' || value === null || value === undefined) continue;
    out[key] = value;
  }
  return out;
}

/** CRUD standar; list mengembalikan { data, meta? } apa adanya. */
function crud<T, Payload extends object>(base: string) {
  return {
    async list(params: BoardingListParams = {}) {
      const res = await api.get<PaginatedResponse<T>>(base, { params: buildParams(params) });
      return { rows: res.data.data ?? [], meta: res.data.meta };
    },
    async show(id: number) {
      const res = await api.get<ApiResponse<T>>(`${base}/${id}`);
      return res.data.data;
    },
    async create(payload: Payload) {
      const res = await api.post<ApiResponse<T>>(base, stripEmpty(payload));
      return res.data.data;
    },
    async update(id: number, payload: Partial<Payload>) {
      const res = await api.put<ApiResponse<T>>(`${base}/${id}`, stripEmpty(payload));
      return res.data.data;
    },
    async remove(id: number) {
      await api.delete(`${base}/${id}`);
    },
  };
}

async function post<T>(url: string, body?: object): Promise<T> {
  const res = await api.post<ApiResponse<T>>(url, body ? stripEmpty(body) : undefined);
  return res.data.data;
}

async function get<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const res = await api.get<ApiResponse<T>>(url, { params });
  return res.data.data;
}

/* ============================ STRUKTUR ============================ */

export const dormitoriesApi = crud<BoardingDormitory, BoardingDormitoryPayload>('/boarding/dormitories');
export const buildingsApi = crud<BoardingBuilding, BoardingBuildingPayload>('/boarding/buildings');
export const floorsApi = crud<BoardingFloor, BoardingFloorPayload>('/boarding/floors');
export const roomsApi = {
  ...crud<BoardingRoom, BoardingRoomPayload>('/boarding/rooms'),
  available: (params: Record<string, unknown> = {}) =>
    get<BoardingRoom[]>('/boarding/rooms/available', params),
  occupants: (roomId: number) =>
    get<BoardingRoomOccupancy>(`/boarding/rooms/${roomId}/occupants`),
};
export const bedsApi = {
  ...crud<BoardingBed, BoardingBedPayload>('/boarding/beds'),
  available: (params: Record<string, unknown> = {}) =>
    get<BoardingBed[]>('/boarding/beds/available', params),
  async bulk(payload: BoardingBedBulkPayload) {
    return post<BoardingBed[]>('/boarding/beds/bulk', payload);
  },
};

/* ============================ PENEMPATAN ============================ */

export const placementsApi = {
  ...crud<BoardingPlacement, BoardingPlacementPayload>('/boarding/placements'),
  createMany: (payload: BoardingPlacementBatchPayload) =>
    post<BoardingPlacement[]>('/boarding/placements/batch', payload),
  occupancy: (roomId: number) =>
    get<BoardingRoomOccupancy>('/boarding/placements/occupancy', { room_id: roomId }),
  transfer: (id: number, payload: BoardingTransferPayload) =>
    post<BoardingPlacement>(`/boarding/placements/${id}/transfer`, payload),
  end: (id: number, payload: { end_date?: string; end_reason?: string; notes?: string } = {}) =>
    post<BoardingPlacement>(`/boarding/placements/${id}/end`, payload),
  history: async (studentId: number) => {
    const res = await api.get<ApiResponse<BoardingPlacement[]>>(
      `/boarding/students/${studentId}/placements`,
    );
    return res.data.data ?? [];
  },
  current: (studentId: number) =>
    get<BoardingPlacement | null>(`/boarding/students/${studentId}/placement`),
};

/* ============================ PENGASUH & KELOMPOK ============================ */

export const musyrifsApi = {
  ...crud<BoardingMusyrif, BoardingMusyrifPayload>('/boarding/musyrifs'),
  byEmployee: (employeeId: number) =>
    get<BoardingMusyrif[]>(`/boarding/employees/${employeeId}/musyrif-assignments`),
};

export const groupsApi = {
  ...crud<BoardingGroup, BoardingGroupPayload>('/boarding/groups'),
  members: async (groupId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingGroupMember>>(
      `/boarding/groups/${groupId}/members`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
  attachMember: (groupId: number, payload: { student_id: number; joined_date?: string }) =>
    post<BoardingGroupMember>(`/boarding/groups/${groupId}/members`, payload),
  detachMember: async (groupId: number, memberId: number) => {
    await api.delete(`/boarding/groups/${groupId}/members/${memberId}`);
  },
};

/* ============================ KEGIATAN & JADWAL ============================ */

export const attendanceTypesApi = crud<BoardingAttendanceType, BoardingAttendanceTypePayload>(
  '/boarding/attendance-types',
);
export const activitiesApi = crud<BoardingActivity, BoardingActivityPayload>('/boarding/activities');
export const schedulesApi = {
  ...crud<BoardingActivitySchedule, BoardingSchedulePayload>('/boarding/schedules'),
  weekly: (params: Record<string, unknown> = {}) =>
    get<BoardingWeeklyDay[]>('/boarding/schedules/weekly', params),
};

/* ============================ ABSENSI ============================ */

export const attendancesApi = {
  ...crud<BoardingAttendance, BoardingAttendancePayload>('/boarding/attendances'),
  roster: (params: Record<string, unknown>) =>
    get<BoardingAttendanceRoster>('/boarding/attendances/roster', params),
  recap: (params: Record<string, unknown> = {}) =>
    get<BoardingAttendanceRecap>('/boarding/attendances/recap', params),
  async bulk(payload: {
    activity_id: number;
    date?: string;
    session?: string;
    attendance_type_id?: number;
    group_id?: number;
    room_id?: number;
    dormitory_id?: number;
    records: Array<{ student_id: number; attendance_type_id: number | ''; note?: string }>;
  }) {
    return post<{
      created: number;
      updated: number;
      skipped: number;
      total: number;
      records: BoardingAttendance[];
    }>('/boarding/attendances/bulk', payload);
  },
  scan: (payload: { uid?: string; code?: string; activity_id?: number; date?: string; session?: string; attendance_type_id?: number; device?: string }) =>
    api.post('/boarding/attendances/scan', stripEmpty(payload)).then((res) => res.data.data),
  monitoring: (params: Record<string, unknown> = {}) =>
    get<BoardingAttendanceMonitoring>('/boarding/attendances/monitoring', params),
  statistics: (params: Record<string, unknown> = {}) =>
    get<BoardingAttendanceStatistics>('/boarding/attendances/statistics', params),
  revise: (id: number, payload: { attendance_type_id: number; note?: string }) =>
    api.put<ApiResponse<BoardingAttendance>>(`/boarding/attendances/${id}/revise`, payload).then(
      (res) => res.data.data,
    ),
  history: async (studentId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingAttendance>>(
      `/boarding/students/${studentId}/attendances`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
};

/* ============================ IZIN KELUAR ============================ */

export const permissionsApi = {
  ...crud<BoardingPermission, BoardingPermissionPayload>('/boarding/permissions'),
  submit: (id: number) => post<BoardingPermission>(`/boarding/permissions/${id}/submit`),
  approve: (id: number, note?: string) =>
    post<BoardingPermission>(`/boarding/permissions/${id}/approve`, note ? { note } : undefined),
  reject: (id: number, note?: string) =>
    post<BoardingPermission>(`/boarding/permissions/${id}/reject`, note ? { note } : undefined),
  checkOut: (id: number) => post<BoardingPermission>(`/boarding/permissions/${id}/check-out`),
  checkIn: (id: number, payload?: { note?: string; return_at?: string }) =>
    post<BoardingPermission>(`/boarding/permissions/${id}/check-in`, payload),
  cancel: (id: number, reason?: string) =>
    post<BoardingPermission>(`/boarding/permissions/${id}/cancel`, reason ? { reason } : undefined),
};

/* ============================ KEPULANGAN & KUNJUNGAN ============================ */

export const homecomingsApi = {
  ...crud<BoardingHomecoming, BoardingHomecomingPayload>('/boarding/homecomings'),
  submit: (id: number) => post<BoardingHomecoming>(`/boarding/homecomings/${id}/submit`),
  approve: (id: number, note?: string) =>
    post<BoardingHomecoming>(`/boarding/homecomings/${id}/approve`, note ? { note } : undefined),
  reject: (id: number, note?: string) =>
    post<BoardingHomecoming>(`/boarding/homecomings/${id}/reject`, note ? { note } : undefined),
  checkOut: (id: number) => post<BoardingHomecoming>(`/boarding/homecomings/${id}/check-out`),
  checkIn: (id: number, payload?: { actual_return_date?: string; returned_with?: string; notes?: string }) =>
    post<BoardingHomecoming>(`/boarding/homecomings/${id}/check-in`, payload),
  cancel: (id: number, reason?: string) =>
    post<BoardingHomecoming>(`/boarding/homecomings/${id}/cancel`, reason ? { reason } : undefined),
};

export const visitorsApi = {
  ...crud<BoardingVisitor, BoardingVisitorPayload>('/boarding/visitors'),
  present: () => get<BoardingVisitor[]>('/boarding/visitors/present'),
  checkIn: (id: number) => post<BoardingVisitor>(`/boarding/visitors/${id}/check-in`),
  checkOut: (id: number, payload?: { check_out_at?: string; notes?: string }) =>
    post<BoardingVisitor>(`/boarding/visitors/${id}/check-out`, payload),
  cancel: (id: number, note?: string) =>
    post<BoardingVisitor>(`/boarding/visitors/${id}/cancel`, note ? { note } : undefined),
};

/* ============================ KEDISIPLINAN ============================ */

export const violationTypesApi = crud<BoardingViolationType, BoardingViolationTypePayload>(
  '/boarding/violation-types',
);

export const violationsApi = {
  ...crud<BoardingViolation, BoardingViolationPayload>('/boarding/violations'),
  points: (studentId: number, recent = 10) =>
    get<BoardingPointsSummary>(`/boarding/students/${studentId}/points`, { recent }),
};

export const disciplinaryActionsApi = {
  ...crud<BoardingDisciplinaryAction, BoardingDisciplinaryActionPayload>(
    '/boarding/disciplinary-actions',
  ),
  status: (id: number, payload: { status: string; notes?: string }) =>
    post<BoardingDisciplinaryAction>(`/boarding/disciplinary-actions/${id}/status`, payload),
};

/* ============================ PRESTASI & KESEHATAN ============================ */

export const achievementsApi = {
  ...crud<BoardingAchievement, BoardingAchievementPayload>('/boarding/achievements'),
  options: () => get<BoardingAchievementOptions>('/boarding/achievements/options'),
  summary: (params: Record<string, unknown> = {}) =>
    get<BoardingAchievementSummary>('/boarding/achievements/summary', params),
};

export const healthApi = {
  ...crud<BoardingHealthRecord, BoardingHealthPayload>('/boarding/health-records'),
  updateStatus: (id: number, payload: { status: string; action?: string; notes?: string }) =>
    post<BoardingHealthRecord>(`/boarding/health-records/${id}/status`, payload),
  isolations: () => get<BoardingPlacement[]>('/boarding/health-records/isolations'),
  isolate: (payload: BoardingIsolatePayload) => post<BoardingPlacement>('/boarding/isolation', payload),
  releaseIsolation: (
    placementId: number,
    payload: { room_id?: number; bed_id?: number; reason?: string } = {},
  ) => post<BoardingPlacement>(`/boarding/isolation/${placementId}/release`, payload),
};

/* ============================ MONITORING, DASHBOARD, LAPORAN ============================ */

export const monitoringApi = {
  overview: (params: Record<string, unknown> = {}) =>
    get<import('@/types/boarding').BoardingMonitoringOverview>('/boarding/monitoring', params),
  options: () => get<Record<string, string>>('/boarding/monitoring/options'),
  student: (studentId: number) =>
    get<import('@/types/boarding').BoardingStudentSummary>(`/boarding/monitoring/students/${studentId}`),
  bulk: (studentIds: number[]) =>
    get<{
      statuses: Record<string, string>;
      summaries: Record<string, import('@/types/boarding').BoardingStudentSummary>;
    }>('/boarding/monitoring/bulk', { student_ids: studentIds.join(',') }),
};

export const dashboardApi = {
  stats: () => get<import('@/types/boarding').BoardingDashboard>('/boarding/dashboard'),
};

export const reportsApi = {
  kinds: () => get<BoardingReportKind[]>('/boarding/reports'),
  show: (kind: string, params: Record<string, unknown> = {}) =>
    get<BoardingReport>(`/boarding/reports/${kind}`, params),
  /** Unduh CSV lewat axios agar header Authorization ikut terkirim. */
  async exportCsv(kind: string, params: Record<string, unknown> = {}) {
    const res = await api.get<Blob>(`/boarding/reports/${kind}/export`, {
      params,
      responseType: 'blob',
    });
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = `boarding-${kind}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  },
};

export const auditApi = {
  list: async (params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingAuditEntry>>('/boarding/audit-logs', {
      params: buildParams(params),
    });
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
};

export const settingsApi = {
  show: () => get<BoardingSettings>('/boarding/settings'),
  async update(values: Partial<BoardingSettings>) {
    const res = await api.patch<ApiResponse<BoardingSettings>>('/boarding/settings', {
      settings: values,
    });
    return res.data.data;
  },
};

/* ============================ PORTAL ORANG TUA ============================ */

export const portalApi = {
  children: () => get<BoardingPortalChild[]>('/boarding/portal/children'),
  summary: (childId: number) =>
    get<BoardingPortalSummary>(`/boarding/portal/children/${childId}/summary`),
  points: (childId: number) =>
    get<BoardingPointsSummary>(`/boarding/portal/children/${childId}/points`),
  violations: async (childId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingViolation>>(
      `/boarding/portal/children/${childId}/violations`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
  achievements: async (childId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingAchievement>>(
      `/boarding/portal/children/${childId}/achievements`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
  health: async (childId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingHealthRecord>>(
      `/boarding/portal/children/${childId}/health`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
  attendances: async (childId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingAttendance>>(
      `/boarding/portal/children/${childId}/attendances`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
  permissions: async (childId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingPermission>>(
      `/boarding/portal/children/${childId}/permissions`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
  homecomings: async (childId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingHomecoming>>(
      `/boarding/portal/children/${childId}/homecomings`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
  visitors: async (childId: number, params: BoardingListParams = {}) => {
    const res = await api.get<PaginatedResponse<BoardingVisitor>>(
      `/boarding/portal/children/${childId}/visitors`,
      { params: buildParams(params) },
    );
    return { rows: res.data.data ?? [], meta: res.data.meta };
  },
};

/* ============================ TITIPAN BARANG ============================ */

export const itemDepositsApi = {
  ...crud<BoardingItemDeposit, BoardingItemDepositPayload>('/boarding/item-deposits'),
  finish: (id: number) => post<BoardingItemDeposit>(`/boarding/item-deposits/${id}/finish`),
  addItem: (id: number, payload: { item_type?: string; item_name: string; notes?: string }) =>
    post<BoardingItemDepositItem>(`/boarding/item-deposits/${id}/items`, payload),
  updateItem: (id: number, itemId: number, payload: { item_type?: string; item_name?: string; notes?: string }) =>
    api.put<ApiResponse<BoardingItemDepositItem>>(`/boarding/item-deposits/${id}/items/${itemId}`, stripEmpty(payload)).then((res) => res.data.data),
  removeItem: async (id: number, itemId: number) => {
    await api.delete(`/boarding/item-deposits/${id}/items/${itemId}`);
  },
  borrowItem: (id: number, itemId: number, notes?: string) =>
    post<BoardingItemDepositItem>(`/boarding/item-deposits/${id}/items/${itemId}/borrow`, notes ? { notes } : undefined),
  returnItem: (id: number, itemId: number, notes?: string) =>
    post<BoardingItemDepositItem>(`/boarding/item-deposits/${id}/items/${itemId}/return`, notes ? { notes } : undefined),
};

export { buildParams as buildBoardingParams, stripEmpty as stripBoardingEmpty };
