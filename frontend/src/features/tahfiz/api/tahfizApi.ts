import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  TahfizAdminDashboard,
  TahfizAttendance,
  TahfizAttendancePayload,
  TahfizCertificate,
  TahfizCertificatePayload,
  TahfizCertificateVerifyResult,
  TahfizEnrollment,
  TahfizEnrollmentPayload,
  TahfizEnrollmentTransitionPayload,
  TahfizExam,
  TahfizExamGenerateItemsPayload,
  TahfizExamItem,
  TahfizExamParticipant,
  TahfizExamPayload,
  TahfizExamResultPayload,
  TahfizExamScoreItemPayload,
  TahfizHalaqah,
  TahfizHalaqahPayload,
  TahfizHalaqahReport,
  TahfizIndividualReport,
  TahfizIndividualTarget,
  TahfizIndividualTargetPayload,
  TahfizInstructor,
  TahfizInstructorPayload,
  TahfizIntervention,
  TahfizInterventionPayload,
  TahfizLevel,
  TahfizLevelPayload,
  TahfizListParams,
  TahfizMemorizationMap,
  TahfizMemorizationRecord,
  TahfizMemorizationRecordPayload,
  TahfizMemorizationReviewPayload,
  TahfizMistakeType,
  TahfizMistakeTypePayload,
  TahfizMutabaah,
  TahfizMutabaahItem,
  TahfizMutabaahItemPayload,
  TahfizMutabaahPayload,
  TahfizNotification,
  TahfizParentDashboard,
  TahfizParticipant,
  TahfizParticipantDashboard,
  TahfizParticipantPayload,
  TahfizParticipantSelfDashboard,
  TahfizProgram,
  TahfizProgramPayload,
  TahfizProgramReport,
  TahfizRapor,
  TahfizRegistrationPayload,
  TahfizRegistrationRequest,
  TahfizRevisionRecord,
  TahfizRevisionRecordPayload,
  TahfizRubric,
  TahfizRubricPayload,
  TahfizSchedule,
  TahfizSchedulePayload,
  TahfizSession,
  TahfizSessionPayload,
  TahfizSurah,
  TahfizTarget,
  TahfizTargetPayload,
  TahfizTargetProgress,
  TahfizTeachingDashboard,
  TahfizTahsinRecord,
  TahfizTahsinRecordPayload,
  TahfizTasmiRecord,
  TahfizTasmiRecordPayload,
  TahfizTeacherReport,
  TahfizTilawahRecord,
  TahfizTilawahRecordPayload,
} from '@/types/tahfiz';

function buildParams(params: TahfizListParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.page) out.page = params.page;
  if (params.per_page) out.per_page = params.per_page;
  if (params.search) out.search = params.search;
  if (params.all) out.all = 1;
  if (params.filter && Object.keys(params.filter).length > 0) out.filter = params.filter;
  return out;
}

/**
 * Buang field opsional yang masih berupa string kosong.
 *
 * Form memakai `''` sebagai "belum diisi"; kalau dikirim apa adanya, aturan
 * `integer`/`date_format` bisa menolaknya walau field itu opsional.
 */
function stripEmpty<T extends object>(payload: T): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(payload)) {
    if (value === '') continue;
    out[key] = value;
  }
  return out;
}

/** Operasi CRUD standar untuk satu resource Tahfiz. */
function crud<T, Payload extends object>(base: string) {
  return {
    async list(params: TahfizListParams = {}) {
      const res = await api.get<PaginatedResponse<T>>(base, {
        params: buildParams(params),
      });
      return res.data;
    },

    async show(id: number) {
      const res = await api.get<ApiResponse<T>>(`${base}/${id}`);
      return res.data.data;
    },

    async create(payload: Payload) {
      const res = await api.post<ApiResponse<T>>(base, stripEmpty(payload));
      return res.data.data;
    },

    async update(id: number, payload: Payload) {
      const res = await api.put<ApiResponse<T>>(`${base}/${id}`, stripEmpty(payload));
      return res.data.data;
    },

    async remove(id: number) {
      await api.delete(`${base}/${id}`);
    },
  };
}

/* ============================ CONFIG ============================ */

export const surahsApi = {
  async list(params: { juz?: number | '' } = {}) {
    const query: Record<string, unknown> = {};
    if (params.juz) query.filter = { juz: params.juz };
    const res = await api.get<ApiResponse<TahfizSurah[]>>('/tahfiz/surahs', { params: query });
    return res.data.data;
  },
};

export const programsApi = crud<TahfizProgram, TahfizProgramPayload>('/tahfiz/programs');
export const levelsApi = crud<TahfizLevel, TahfizLevelPayload>('/tahfiz/levels');
export const rubricsApi = crud<TahfizRubric, TahfizRubricPayload>('/tahfiz/rubrics');
export const mistakeTypesApi = crud<TahfizMistakeType, TahfizMistakeTypePayload>(
  '/tahfiz/mistake-types',
);
export const mutabaahItemsApi = crud<TahfizMutabaahItem, TahfizMutabaahItemPayload>(
  '/tahfiz/mutabaah-items',
);
export const instructorsApi = crud<TahfizInstructor, TahfizInstructorPayload>(
  '/tahfiz/instructors',
);

/* ============================ PESERTA & ENROLLMENT ============================ */

export const participantsApi = {
  ...crud<TahfizParticipant, TahfizParticipantPayload>('/tahfiz/participants'),

  async progress(participantId: number) {
    const res = await api.get<ApiResponse<TahfizMemorizationMap>>(
      `/tahfiz/participants/${participantId}/progress`,
    );
    return res.data.data;
  },

  async map(participantId: number) {
    const res = await api.get<ApiResponse<TahfizMemorizationMap>>(
      `/tahfiz/participants/${participantId}/progress/map`,
    );
    return res.data.data;
  },

  async targetProgress(participantId: number, period?: string) {
    const res = await api.get<ApiResponse<TahfizTargetProgress>>(
      `/tahfiz/participants/${participantId}/progress/target`,
      { params: period ? { period } : {} },
    );
    return res.data.data;
  },

  async interventions(participantId: number) {
    const res = await api.get<ApiResponse<TahfizIntervention[]>>(
      `/tahfiz/participants/${participantId}/interventions`,
    );
    return res.data.data;
  },
};

export const enrollmentsApi = {
  ...crud<TahfizEnrollment, TahfizEnrollmentPayload>('/tahfiz/enrollments'),

  async transition(id: number, payload: TahfizEnrollmentTransitionPayload) {
    const res = await api.post<ApiResponse<TahfizEnrollment>>(
      `/tahfiz/enrollments/${id}/transition`,
      stripEmpty(payload),
    );
    return res.data;
  },

  async approve(id: number) {
    const res = await api.post<ApiResponse<TahfizEnrollment>>(
      `/tahfiz/enrollments/${id}/approve`,
    );
    return res.data;
  },
};

export const halaqahsApi = {
  ...crud<TahfizHalaqah, TahfizHalaqahPayload>('/tahfiz/halaqahs'),
};

/* ============================ JADWAL, SESI, KEHADIRAN ============================ */

export const schedulesApi = crud<TahfizSchedule, TahfizSchedulePayload>('/tahfiz/schedules');

export const sessionsApi = {
  ...crud<TahfizSession, TahfizSessionPayload>('/tahfiz/sessions'),

  async transition(id: number, status: string) {
    const res = await api.post<ApiResponse<TahfizSession>>(
      `/tahfiz/sessions/${id}/transition`,
      { status },
    );
    return res.data;
  },

  async generate(payload: { halaqah_id: number | ''; from: string; to: string }) {
    const res = await api.post<ApiResponse<{ created: number; skipped: number }>>(
      '/tahfiz/sessions/generate',
      stripEmpty(payload),
    );
    return res.data;
  },
};

export const attendancesApi = {
  ...crud<TahfizAttendance, TahfizAttendancePayload>('/tahfiz/attendances'),

  async bulk(payload: {
    session_id?: number | '';
    halaqah_id?: number | '';
    date: string;
    attendances: { participant_id: number; status: string; permission_reason?: string; notes?: string }[];
  }) {
    const res = await api.post<ApiResponse<TahfizAttendance[]>>(
      '/tahfiz/attendances/bulk',
      stripEmpty(payload),
    );
    return res.data;
  },
};

/* ============================ SETORAN & AKTIVITAS ============================ */

export const memorizationRecordsApi = {
  ...crud<TahfizMemorizationRecord, TahfizMemorizationRecordPayload>(
    '/tahfiz/memorization-records',
  ),

  async review(id: number, payload: TahfizMemorizationReviewPayload) {
    const res = await api.post<ApiResponse<TahfizMemorizationRecord>>(
      `/tahfiz/memorization-records/${id}/review`,
      stripEmpty(payload),
    );
    return res.data;
  },
};

export const revisionRecordsApi = crud<TahfizRevisionRecord, TahfizRevisionRecordPayload>(
  '/tahfiz/revision-records',
);
export const tilawahRecordsApi = crud<TahfizTilawahRecord, TahfizTilawahRecordPayload>(
  '/tahfiz/tilawah-records',
);
export const tahsinRecordsApi = crud<TahfizTahsinRecord, TahfizTahsinRecordPayload>(
  '/tahfiz/tahsin-records',
);
export const tasmiRecordsApi = crud<TahfizTasmiRecord, TahfizTasmiRecordPayload>(
  '/tahfiz/tasmi-records',
);

/* ============================ TARGET ============================ */

export const targetsApi = crud<TahfizTarget, TahfizTargetPayload>('/tahfiz/targets');
export const individualTargetsApi = crud<TahfizIndividualTarget, TahfizIndividualTargetPayload>(
  '/tahfiz/individual-targets',
);

/* ============================ MUTABAAH ============================ */

export const mutabaahApi = crud<TahfizMutabaah, TahfizMutabaahPayload>('/tahfiz/mutabaah');

/* ============================ UJIAN ============================ */

export const examsApi = {
  ...crud<TahfizExam, TahfizExamPayload>('/tahfiz/exams'),

  async transition(id: number, status: string) {
    const res = await api.post<ApiResponse<TahfizExam>>(`/tahfiz/exams/${id}/transition`, {
      status,
    });
    return res.data;
  },

  async participants(examId: number) {
    const res = await api.get<ApiResponse<TahfizExamParticipant[]>>(
      `/tahfiz/exams/${examId}/participants`,
    );
    return res.data.data;
  },

  async registerParticipants(examId: number, participantIds: number[]) {
    const res = await api.post<ApiResponse<TahfizExamParticipant[]>>(
      `/tahfiz/exams/${examId}/participants`,
      { participant_ids: participantIds },
    );
    return res.data;
  },

  async generateItems(examId: number, payload: TahfizExamGenerateItemsPayload) {
    const res = await api.post<ApiResponse<TahfizExamItem[]>>(
      `/tahfiz/exams/${examId}/items/generate`,
      stripEmpty(payload),
    );
    return res.data;
  },

  async scoreItem(examId: number, itemId: number, payload: TahfizExamScoreItemPayload) {
    const res = await api.post<ApiResponse<TahfizExamItem>>(
      `/tahfiz/exams/${examId}/items/${itemId}/score`,
      stripEmpty(payload),
    );
    return res.data;
  },

  async recordResult(examId: number, examParticipantId: number, payload: TahfizExamResultPayload) {
    const res = await api.post<ApiResponse<TahfizExamParticipant>>(
      `/tahfiz/exams/${examId}/participants/${examParticipantId}/result`,
      stripEmpty(payload),
    );
    return res.data;
  },
};

/* ============================ SERTIFIKAT ============================ */

export const certificatesApi = {
  ...crud<TahfizCertificate, TahfizCertificatePayload>('/tahfiz/certificates'),

  async revoke(id: number, revokeReason: string) {
    const res = await api.post<ApiResponse<TahfizCertificate>>(
      `/tahfiz/certificates/${id}/revoke`,
      { revoke_reason: revokeReason },
    );
    return res.data;
  },

  /** Verifikasi token QR — endpoint publik, tanpa autentikasi. */
  async verify(qrToken: string) {
    const res = await api.post<ApiResponse<TahfizCertificateVerifyResult>>(
      '/tahfiz/certificates/verify',
      { qr_token: qrToken },
    );
    return res.data.data;
  },
};

/* ============================ INTERVENTION ============================ */

export const interventionsApi = {
  ...crud<TahfizIntervention, TahfizInterventionPayload>('/tahfiz/interventions'),

  async transition(
    id: number,
    payload: string | { status: string; result?: string; completed_at?: string },
  ) {
    const body = typeof payload === 'string' ? { status: payload } : payload;
    const res = await api.post<ApiResponse<TahfizIntervention>>(
      `/tahfiz/interventions/${id}/transition`,
      stripEmpty(body),
    );
    return res.data;
  },
};

/* ============================ PENDAFTARAN MANDIRI ============================ */

export const registrationsApi = {
  async list(params: TahfizListParams = {}) {
    const res = await api.get<PaginatedResponse<TahfizRegistrationRequest>>(
      '/tahfiz/registrations',
      { params: buildParams(params) },
    );
    return res.data;
  },

  /** Pengajuan publik — dipanggil tanpa login. */
  async submit(payload: TahfizRegistrationPayload) {
    const res = await api.post<ApiResponse<TahfizRegistrationRequest>>(
      '/tahfiz/registrations',
      stripEmpty(payload),
    );
    return res.data;
  },

  async approve(id: number) {
    const res = await api.post<ApiResponse<TahfizRegistrationRequest>>(
      `/tahfiz/registrations/${id}/approve`,
    );
    return res.data;
  },

  async reject(id: number, reviewNote: string) {
    const res = await api.post<ApiResponse<TahfizRegistrationRequest>>(
      `/tahfiz/registrations/${id}/reject`,
      { review_note: reviewNote },
    );
    return res.data;
  },
};

/* ============================ NOTIFIKASI ============================ */

export const notificationsApi = {
  async list(params: TahfizListParams = {}) {
    const res = await api.get<PaginatedResponse<TahfizNotification>>('/tahfiz/notifications', {
      params: buildParams(params),
    });
    return res.data;
  },

  async unreadCount() {
    const res = await api.get<ApiResponse<{ count: number }>>(
      '/tahfiz/notifications/unread-count',
    );
    return res.data.data;
  },

  async markAsRead(id: number) {
    const res = await api.post<ApiResponse<TahfizNotification>>(
      `/tahfiz/notifications/${id}/read`,
    );
    return res.data;
  },

  async markAllAsRead() {
    const res = await api.post<ApiResponse<null>>('/tahfiz/notifications/read-all');
    return res.data;
  },

  async remove(id: number) {
    await api.delete(`/tahfiz/notifications/${id}`);
  },
};

/* ============================ DASHBOARD ============================ */

export const dashboardsApi = {
  async admin() {
    const res = await api.get<ApiResponse<TahfizAdminDashboard>>('/tahfiz/dashboards/admin');
    return res.data.data;
  },

  async instructor() {
    const res = await api.get<ApiResponse<TahfizTeachingDashboard>>(
      '/tahfiz/dashboards/guru',
    );
    return res.data.data;
  },

  async musyrif() {
    const res = await api.get<ApiResponse<TahfizTeachingDashboard>>(
      '/tahfiz/dashboards/musyrif',
    );
    return res.data.data;
  },

  async participant() {
    const res = await api.get<ApiResponse<TahfizParticipantSelfDashboard>>(
      '/tahfiz/dashboards/peserta',
    );
    return res.data.data;
  },

  async parent(view: 'self' | 'child', childId?: number | '') {
    const params: Record<string, unknown> = { view };
    if (view === 'child' && childId) params.participant_id = childId;
    const res = await api.get<ApiResponse<TahfizParentDashboard>>('/tahfiz/dashboards/wali', {
      params,
    });
    return res.data.data;
  },
};

/* ============================ LAPORAN & RAPOR ============================ */

export interface TahfizPeriodParams {
  from?: string;
  to?: string;
}

function periodParams(params: TahfizPeriodParams = {}) {
  const out: Record<string, unknown> = {};
  if (params.from) out.from = params.from;
  if (params.to) out.to = params.to;
  return out;
}

export const reportsApi = {
  async individual(participantId: number, params: TahfizPeriodParams = {}) {
    const res = await api.get<ApiResponse<TahfizIndividualReport>>(
      `/tahfiz/reports/individual/${participantId}`,
      { params: periodParams(params) },
    );
    return res.data.data;
  },

  async halaqah(halaqahId: number, params: TahfizPeriodParams = {}) {
    const res = await api.get<ApiResponse<TahfizHalaqahReport>>(
      `/tahfiz/reports/halaqah/${halaqahId}`,
      { params: periodParams(params) },
    );
    return res.data.data;
  },

  async program(programId: number, params: TahfizPeriodParams = {}) {
    const res = await api.get<ApiResponse<TahfizProgramReport>>(
      `/tahfiz/reports/program/${programId}`,
      { params: periodParams(params) },
    );
    return res.data.data;
  },

  async teacher(employeeId: number, params: TahfizPeriodParams = {}) {
    const res = await api.get<ApiResponse<TahfizTeacherReport>>(
      `/tahfiz/reports/teacher/${employeeId}`,
      { params: periodParams(params) },
    );
    return res.data.data;
  },

  async rapor(participantId: number, params: TahfizPeriodParams = {}) {
    const res = await api.get<ApiResponse<TahfizRapor>>(
      `/tahfiz/reports/rapor/${participantId}`,
      { params: periodParams(params) },
    );
    return res.data.data;
  },
};

export { buildParams as buildTahfizParams, stripEmpty as stripTahfizEmpty };

export type { TahfizParticipantDashboard };