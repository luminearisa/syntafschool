import { downloadBlob, filenameFromResponse, ppdbHttp, ppdbParams } from './http';
import type { ApiResponse, LoginCredentials, LoginResult, PaginatedResponse } from '@/types';
import type {
  PpdbAnnouncement,
  PpdbAnnouncementPayload,
  PpdbAppeal,
  PpdbAppealDecisionResult,
  PpdbApplicant,
  PpdbApplicantDocument,
  PpdbDashboard,
  PpdbDocumentRequirement,
  PpdbDocumentRequirementPayload,
  PpdbExportType,
  PpdbFeePayload,
  PpdbFormField,
  PpdbFormFieldPayload,
  PpdbFormMeta,
  PpdbInvoice,
  PpdbListParams,
  PpdbLookups,
  PpdbParentAccount,
  PpdbParticipantRegistrationResult,
  PpdbPayment,
  PpdbPeriod,
  PpdbPeriodPayload,
  PpdbPortalAppeals,
  PpdbProgram,
  PpdbProgramPayload,
  PpdbQuestion,
  PpdbQuestionPayload,
  PpdbRegistration,
  PpdbRegistrationFee,
  PpdbRegistrationHistory,
  PpdbRegistrationStatus,
  PpdbReportPayload,
  PpdbReportType,
  PpdbResetPasswordResult,
  PpdbSelectionParticipant,
  PpdbSelectionStage,
  PpdbSelectionStagePayload,
  PpdbSelectionResult,
  PpdbWave,
  PpdbWavePayload,
} from '@/types/ppdb';
import type {
  PpdbNotification,
  PpdbPayPayload,
  PpdbPayResult,
  PpdbPortalApplicationCard,
  PpdbPortalDashboard,
  PpdbPortalPayment,
  PpdbPortalProfile,
  PpdbPortalRegistrationDetail,
  PpdbPortalSelectionResult,
  PpdbPublicPeriodDetail,
  PpdbPublicPeriodSummary,
  PpdbRegisterPayload,
  PpdbRegisterResult,
  PpdbRegistrationUpdatePayload,
} from '@/types/ppdbPortal';

const base = '/ppdb';

/** Jawaban PUT/POST standar: kembalikan `data` saja. */
function unwrap<T>(res: { data: ApiResponse<T> }): T {
  return res.data.data;
}

/** CRUD standar untuk satu `apiResource` PPDB. */
function crud<T, P>(path: string) {
  return {
    async list(params: PpdbListParams = {}): Promise<PaginatedResponse<T>> {
      const res = await ppdbHttp.get<PaginatedResponse<T>>(`${base}/${path}`, {
        params: ppdbParams(params),
      });
      return res.data;
    },
    async listAll(params: Omit<PpdbListParams, 'page' | 'per_page'> = {}): Promise<T[]> {
      const res = await ppdbHttp.get<PaginatedResponse<T>>(`${base}/${path}`, {
        params: ppdbParams({ ...params, per_page: 100 }),
      });
      return res.data.data;
    },
    async show(id: number): Promise<T> {
      return unwrap(await ppdbHttp.get<ApiResponse<T>>(`${base}/${path}/${id}`));
    },
    async create(payload: P): Promise<T> {
      return unwrap(await ppdbHttp.post<ApiResponse<T>>(`${base}/${path}`, payload));
    },
    async update(id: number, payload: Partial<P>): Promise<T> {
      return unwrap(await ppdbHttp.put<ApiResponse<T>>(`${base}/${path}/${id}`, payload));
    },
    async remove(id: number): Promise<void> {
      await ppdbHttp.delete(`${base}/${path}/${id}`);
    },
  };
}

/* ============================ PUBLIK ============================ */

export const ppdbPublicApi = {
  /** Daftar periode yang tampil untuk umum. */
  async periods(params: PpdbListParams = {}): Promise<PpdbPublicPeriodSummary[]> {
    const res = await ppdbHttp.get<ApiResponse<PpdbPublicPeriodSummary[]> | PaginatedResponse<PpdbPublicPeriodSummary>>(
      `${base}/public`,
      { params: ppdbParams(params) },
    );
    return res.data.data;
  },

  /** Halaman publik satu periode (`/ppdb/{slug}`). */
  async period(slug: string): Promise<PpdbPublicPeriodDetail> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbPublicPeriodDetail>>(`${base}/public/${slug}`));
  },
};

export const ppdbAuthApi = {
  /** Buat akun orang tua — memakai `users` + `guardians` existing. */
  async register(payload: PpdbRegisterPayload): Promise<PpdbRegisterResult> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbRegisterResult>>(`${base}/auth/register`, payload));
  },

  /** Login memakai endpoint Sanctum existing (tanpa sistem auth baru). */
  async login(payload: LoginCredentials): Promise<LoginResult> {
    const res = await ppdbHttp.post<ApiResponse<LoginResult>>('/auth/login', payload);
    return res.data.data;
  },

  async me() {
    const res = await ppdbHttp.get<ApiResponse<{ id: number; name: string; email: string; roles?: string[] }>>(
      '/auth/me',
    );
    return res.data.data;
  },

  async logout(): Promise<void> {
    await ppdbHttp.post('/auth/logout');
  },
};

/* ============================ PORTAL ORANG TUA ============================ */

export const ppdbPortalApi = {
  async dashboard(): Promise<PpdbPortalDashboard> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbPortalDashboard>>(`${base}/portal/dashboard`));
  },

  async profile(): Promise<PpdbPortalProfile> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbPortalProfile>>(`${base}/portal/profile`));
  },

  async updateProfile(payload: Record<string, unknown>): Promise<PpdbPortalProfile> {
    return unwrap(await ppdbHttp.put<ApiResponse<PpdbPortalProfile>>(`${base}/portal/profile`, payload));
  },
};

export const ppdbPortalApplicantsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbApplicant>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbApplicant>>(`${base}/portal/applicants`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  async listAll(): Promise<PpdbApplicant[]> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbApplicant>>(`${base}/portal/applicants`, {
      params: { per_page: 100 },
    });
    return res.data.data;
  },

  async show(id: number): Promise<PpdbApplicant> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbApplicant>>(`${base}/portal/applicants/${id}`));
  },

  async create(payload: Partial<PpdbApplicant>): Promise<PpdbApplicant> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbApplicant>>(`${base}/portal/applicants`, payload));
  },

  async update(id: number, payload: Partial<PpdbApplicant>): Promise<PpdbApplicant> {
    return unwrap(await ppdbHttp.put<ApiResponse<PpdbApplicant>>(`${base}/portal/applicants/${id}`, payload));
  },
};

export const ppdbPortalRegistrationsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbPortalApplicationCard>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbPortalApplicationCard>>(
      `${base}/portal/registrations`,
      { params: ppdbParams(params) },
    );
    return res.data;
  },

  /** "Register another child" — pendaftaran baru di akun yang sama. */
  async create(payload: {
    applicant_id: number;
    period_id: number;
    program_id: number;
    wave_id?: number | '';
  }): Promise<PpdbRegistration> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbRegistration>>(`${base}/portal/registrations`, payload),
    );
  },

  async show(id: number): Promise<PpdbPortalRegistrationDetail> {
    return unwrap(
      await ppdbHttp.get<ApiResponse<PpdbPortalRegistrationDetail>>(`${base}/portal/registrations/${id}`),
    );
  },

  /** Simpan draf (autosave) — hanya saat status masih dapat diubah. */
  async update(id: number, payload: PpdbRegistrationUpdatePayload): Promise<PpdbPortalRegistrationDetail> {
    return unwrap(
      await ppdbHttp.put<ApiResponse<PpdbPortalRegistrationDetail>>(
        `${base}/portal/registrations/${id}`,
        payload,
      ),
    );
  },

  /** Kirim pendaftaran (validasi penuh + dokumen wajib). */
  async submit(id: number): Promise<PpdbRegistration> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbRegistration>>(`${base}/portal/registrations/${id}/submit`));
  },
};

export const ppdbPortalDocumentsApi = {
  async list(registrationId: number) {
    const res = await ppdbHttp.get<ApiResponse<unknown[]>>(
      `${base}/portal/registrations/${registrationId}/documents`,
    );
    return res.data.data;
  },

  /** Unggah (versi baru; versi lama tetap tersimpan). */
  async upload(registrationId: number, requirementId: number, file: File) {
    const form = new FormData();
    form.append('requirement_id', String(requirementId));
    form.append('file', file);

    const res = await ppdbHttp.post<ApiResponse<PpdbApplicantDocument>>(
      `${base}/portal/registrations/${registrationId}/documents`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    );
    return res.data;
  },
};

export const ppdbPortalPaymentApi = {
  async forRegistration(registrationId: number): Promise<PpdbPortalPayment> {
    return unwrap(
      await ppdbHttp.get<ApiResponse<PpdbPortalPayment>>(
        `${base}/portal/registrations/${registrationId}/payment`,
      ),
    );
  },

  async pay(invoiceId: number, payload: PpdbPayPayload): Promise<PpdbPayResult> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbPayResult>>(`${base}/portal/invoices/${invoiceId}/pay`, payload),
    );
  },

  /** Unggah bukti transfer untuk verifikasi manual. */
  async payWithProof(invoiceId: number, payload: PpdbPayPayload, proof?: File | null): Promise<PpdbPayResult> {
    if (!proof) return this.pay(invoiceId, payload);

    const form = new FormData();
    form.append('method', payload.method);
    form.append('amount', String(payload.amount));
    if (payload.payment_method_id) form.append('payment_method_id', String(payload.payment_method_id));
    if (payload.reference) form.append('reference', payload.reference);
    form.append('proof', proof);

    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbPayResult>>(`${base}/portal/invoices/${invoiceId}/pay`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }),
    );
  },
};

export const ppdbPortalSelectionApi = {
  async forRegistration(registrationId: number): Promise<PpdbPortalSelectionResult> {
    return unwrap(
      await ppdbHttp.get<ApiResponse<PpdbPortalSelectionResult>>(
        `${base}/portal/registrations/${registrationId}/selection`,
      ),
    );
  },
};

export const ppdbPortalNotificationsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbNotification>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbNotification>>(`${base}/portal/notifications`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  /** `GET .../unread-count` menjawab `{unread, total}` — bukan `count`. */
  async unreadCount(): Promise<number> {
    const res = await ppdbHttp.get<ApiResponse<{ unread?: number; count?: number }>>(
      `${base}/portal/notifications/unread-count`,
    );
    const data = res.data.data;

    return Number(data?.unread ?? data?.count ?? 0);
  },

  async markRead(id: string): Promise<void> {
    await ppdbHttp.post(`${base}/portal/notifications/${id}/read`);
  },

  /** `POST .../read-all` menjawab `{marked_as_read, unread}`. */
  async markAllRead(): Promise<number> {
    const res = await ppdbHttp.post<ApiResponse<{ marked_as_read?: number; count?: number }>>(
      `${base}/portal/notifications/read-all`,
    );
    const data = res.data.data;

    return Number(data?.marked_as_read ?? data?.count ?? 0);
  },
};

/* ============================ ADMIN — KONFIGURASI ============================ */

export const ppdbPeriodsApi = {
  ...crud<PpdbPeriod, PpdbPeriodPayload>('periods'),

  async publish(id: number): Promise<PpdbPeriod> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbPeriod>>(`${base}/periods/${id}/publish`));
  },
};

export const ppdbWavesApi = crud<PpdbWave, PpdbWavePayload>('waves');
export const ppdbProgramsApi = crud<PpdbProgram, PpdbProgramPayload>('programs');
export const ppdbQuestionsApi = crud<PpdbQuestion, PpdbQuestionPayload>('questions');
export const ppdbDocumentRequirementsApi = crud<
  PpdbDocumentRequirement,
  PpdbDocumentRequirementPayload
>('document-requirements');
export const ppdbFeesApi = crud<PpdbRegistrationFee, PpdbFeePayload>('fees');

export const ppdbFormFieldsApi = {
  ...crud<PpdbFormField, PpdbFormFieldPayload>('form-fields'),

  async reorder(items: { id: number; order: number }[]): Promise<PpdbFormField[]> {
    const res = await ppdbHttp.post<ApiResponse<PpdbFormField[]>>(`${base}/form-fields/reorder`, {
      items,
    });
    return res.data.data;
  },

  /** Meta section/tipe diambil dari `meta` respons daftar field. */
  async meta(periodId: number): Promise<PpdbFormMeta | null> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbFormField> & { meta?: PpdbFormMeta }>(
      `${base}/form-fields`,
      { params: { period_id: periodId, per_page: 1 } },
    );
    return (res.data as { meta?: PpdbFormMeta }).meta ?? null;
  },
};

/* ============================ ADMIN — AKUN & CALON ============================ */

export const ppdbParentAccountsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbParentAccount>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbParentAccount>>(`${base}/parent-accounts`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  async show(id: number): Promise<PpdbParentAccount> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbParentAccount>>(`${base}/parent-accounts/${id}`));
  },

  async updateStatus(id: number, status: PpdbParentAccount['account_status']): Promise<PpdbParentAccount> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbParentAccount>>(`${base}/parent-accounts/${id}/status`, {
        account_status: status,
      }),
    );
  },

  async resetPassword(id: number): Promise<PpdbResetPasswordResult> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbResetPasswordResult>>(
        `${base}/parent-accounts/${id}/reset-password`,
      ),
    );
  },
};

export const ppdbApplicantsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbApplicant>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbApplicant>>(`${base}/applicants`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  async show(id: number): Promise<PpdbApplicant> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbApplicant>>(`${base}/applicants/${id}`));
  },

  async update(id: number, payload: Partial<PpdbApplicant>): Promise<PpdbApplicant> {
    return unwrap(await ppdbHttp.put<ApiResponse<PpdbApplicant>>(`${base}/applicants/${id}`, payload));
  },

  async remove(id: number): Promise<void> {
    await ppdbHttp.delete(`${base}/applicants/${id}`);
  },
};

/* ============================ ADMIN — PENDAFTARAN & VERIFIKASI ============================ */

export const ppdbRegistrationsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbRegistration>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbRegistration>>(`${base}/registrations`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  async show(id: number): Promise<Record<string, unknown> & { registration?: PpdbRegistration }> {
    return unwrap(await ppdbHttp.get<ApiResponse<never>>(`${base}/registrations/${id}`));
  },

  async history(id: number): Promise<PpdbRegistrationHistory[]> {
    const res = await ppdbHttp.get<ApiResponse<PpdbRegistrationHistory[]>>(
      `${base}/registrations/${id}/history`,
    );
    return res.data.data;
  },

  async transition(id: number, status: PpdbRegistrationStatus, note?: string): Promise<PpdbRegistration> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbRegistration>>(`${base}/registrations/${id}/transition`, {
        status,
        note,
      }),
    );
  },

  async requestRevision(
    id: number,
    payload: { note: string; targets?: { type: 'field' | 'question' | 'document'; key?: string; id?: number; reason?: string }[] },
  ): Promise<PpdbRegistration> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbRegistration>>(
        `${base}/registrations/${id}/request-revision`,
        payload,
      ),
    );
  },

  async verify(id: number, note?: string): Promise<PpdbRegistration> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbRegistration>>(`${base}/registrations/${id}/verify`, { note }));
  },

  async reject(id: number, note?: string): Promise<PpdbRegistration> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbRegistration>>(`${base}/registrations/${id}/reject`, { note }));
  },

  async cancel(id: number, note?: string): Promise<PpdbRegistration> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbRegistration>>(`${base}/registrations/${id}/cancel`, { note }));
  },

  async setResult(id: number, result: PpdbSelectionResult, payload?: { note?: string; announcement_id?: number }): Promise<PpdbRegistration> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbRegistration>>(`${base}/registrations/${id}/result`, {
      result,
      ...payload,
    }));
  },

  async convertToStudent(id: number) {
    const res = await ppdbHttp.post<ApiResponse<{ student: { id: number; nis: string; full_name: string }; created: boolean }>>(
      `${base}/registrations/${id}/convert-to-student`,
    );
    return res.data;
  },
};

export const ppdbDocumentsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbApplicantDocument>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbApplicantDocument>>(`${base}/documents`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  async review(
    id: number,
    payload: { status: 'approved' | 'rejected' | 'under_review'; note?: string },
  ): Promise<PpdbApplicantDocument> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbApplicantDocument>>(`${base}/documents/${id}/review`, payload),
    );
  },
};

/* ============================ ADMIN — PEMBAYARAN ============================ */

export const ppdbInvoicesApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbInvoice>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbInvoice>>(`${base}/invoices`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  async show(id: number): Promise<PpdbInvoice> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbInvoice>>(`${base}/invoices/${id}`));
  },

  async create(payload: { registration_id: number; type?: 'registration' | 're_registration' | 'other' }): Promise<PpdbInvoice> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbInvoice>>(`${base}/invoices`, payload));
  },

  async remind(id: number): Promise<{ sent: boolean; message?: string }> {
    const res = await ppdbHttp.post<ApiResponse<{ sent: boolean }>>(`${base}/invoices/${id}/remind`);
    return { sent: Boolean(res.data.data?.sent), message: res.data.message };
  },
};

export const ppdbPaymentsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbPayment>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbPayment>>(`${base}/payments`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  async verify(id: number, note?: string): Promise<PpdbPayment> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbPayment>>(`${base}/payments/${id}/verify`, { note }));
  },

  async reject(id: number, reason: string): Promise<PpdbPayment> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbPayment>>(`${base}/payments/${id}/reject`, { reason, note: reason }),
    );
  },
};

/* ============================ ADMIN — SELEKSI & PENGUMUMAN ============================ */

export const ppdbSelectionApi = {
  ...crud<PpdbSelectionStage, PpdbSelectionStagePayload>('selection-stages'),

  async registerParticipants(stageId: number, registrationIds: number[]): Promise<PpdbParticipantRegistrationResult> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbParticipantRegistrationResult>>(
        `${base}/selection-stages/${stageId}/participants`,
        { registration_ids: registrationIds },
      ),
    );
  },

  async participants(stageId: number, params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbSelectionParticipant>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbSelectionParticipant>>(
      `${base}/selection-stages/${stageId}/participants`,
      { params: ppdbParams(params) },
    );
    return res.data;
  },

  async score(
    participantId: number,
    payload: { score?: number | ''; result?: PpdbSelectionResult; status?: 'invited' | 'attended' | 'absent' | 'scored'; notes?: string },
  ): Promise<PpdbSelectionParticipant> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbSelectionParticipant>>(
        `${base}/selection-participants/${participantId}/score`,
        payload,
      ),
    );
  },
};

export const ppdbAnnouncementsApi = {
  ...crud<PpdbAnnouncement, PpdbAnnouncementPayload>('announcements'),

  async publish(id: number): Promise<PpdbAnnouncement> {
    return unwrap(await ppdbHttp.post<ApiResponse<PpdbAnnouncement>>(`${base}/announcements/${id}/publish`));
  },
};

/* ============================ ADMIN — DAFTAR ULANG ============================ */

export const ppdbReRegistrationApi = {
  async open(registrationId: number): Promise<{ registration: PpdbRegistration; invoice: PpdbInvoice }> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<{ registration: PpdbRegistration; invoice: PpdbInvoice }>>(
        `${base}/registrations/${registrationId}/re-registration/open`,
      ),
    );
  },

  async complete(registrationId: number): Promise<{ registration: PpdbRegistration; student?: unknown }> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<{ registration: PpdbRegistration; student?: unknown }>>(
        `${base}/registrations/${registrationId}/re-registration/complete`,
      ),
    );
  },
};

/* ============================ SANGGAH HASIL ============================ */

export const ppdbPortalAppealsApi = {
  /** Kelayakan + daftar sanggahan satu pendaftaran milik pemanggil. */
  async forRegistration(registrationId: number): Promise<PpdbPortalAppeals> {
    return unwrap(
      await ppdbHttp.get<ApiResponse<PpdbPortalAppeals>>(`${base}/portal/registrations/${registrationId}/appeals`),
    );
  },

  /** Kirim sanggahan; berkas lampiran boleh kosong (dikirim multipart bila ada). */
  async submit(registrationId: number, reason: string, attachment?: File | null): Promise<PpdbAppeal> {
    const url = `${base}/portal/registrations/${registrationId}/appeals`;

    if (!attachment) {
      return unwrap(await ppdbHttp.post<ApiResponse<PpdbAppeal>>(url, { reason }));
    }

    const form = new FormData();
    form.append('reason', reason);
    form.append('attachment', attachment);

    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbAppeal>>(url, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }),
    );
  },
};

export const ppdbAppealsApi = {
  async list(params: PpdbListParams = {}): Promise<PaginatedResponse<PpdbAppeal>> {
    const res = await ppdbHttp.get<PaginatedResponse<PpdbAppeal>>(`${base}/appeals`, {
      params: ppdbParams(params),
    });
    return res.data;
  },

  async show(id: number): Promise<PpdbAppeal> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbAppeal>>(`${base}/appeals/${id}`));
  },

  /**
   * Menerima sanggahan hanya MEMBUKA kembali keputusan untuk panitia — hasil
   * seleksi tidak ikut berubah (`result_changed` selalu false pada respons),
   * penetapan hasil baru tetap harus dikirim eksplisit.
   */
  async decide(
    id: number,
    payload: { decision: 'accepted' | 'rejected'; review_note: string },
  ): Promise<PpdbAppealDecisionResult> {
    return unwrap(
      await ppdbHttp.post<ApiResponse<PpdbAppealDecisionResult>>(`${base}/appeals/${id}/decide`, payload),
    );
  },
};

/* ============================ ADMIN — OPSI REFERENSI ============================ */

export const ppdbLookupsApi = {
  /**
   * Whitelist referensi master data untuk dropdown PPDB.
   *
   * Bukan `/master/*`: panitia memegang `ppdb.*` saja, jadi endpoint master
   * menjawab 403 dan pilihan pada formulir mereka tampil kosong tanpa error.
   */
  async get(): Promise<PpdbLookups> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbLookups>>(`${base}/lookups`));
  },
};

/* ============================ ADMIN — DASBOR & LAPORAN ============================ */

export const ppdbDashboardApi = {
  async get(params: PpdbListParams = {}): Promise<PpdbDashboard> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbDashboard>>(`${base}/dashboard`, {
      params: ppdbParams(params),
    }));
  },
};

export const ppdbReportsApi = {
  async registration(params: PpdbListParams = {}): Promise<PpdbReportPayload> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbReportPayload>>(`${base}/reports/registration`, {
      params: ppdbParams(params),
    }));
  },

  async payment(params: PpdbListParams = {}): Promise<PpdbReportPayload> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbReportPayload>>(`${base}/reports/payment`, {
      params: ppdbParams(params),
    }));
  },

  async selection(params: PpdbListParams = {}): Promise<PpdbReportPayload> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbReportPayload>>(`${base}/reports/selection`, {
      params: ppdbParams(params),
    }));
  },

  async document(params: PpdbListParams = {}): Promise<PpdbReportPayload> {
    return unwrap(await ppdbHttp.get<ApiResponse<PpdbReportPayload>>(`${base}/reports/document`, {
      params: ppdbParams(params),
    }));
  },

  async byType(type: PpdbReportType, params: PpdbListParams = {}): Promise<PpdbReportPayload> {
    return this[type](params);
  },

  async exportCsv(type: PpdbExportType, params: PpdbListParams = {}): Promise<void> {
    const res = await ppdbHttp.get<Blob>(`${base}/export`, {
      params: ppdbParams({ ...params, type }),
      responseType: 'blob',
    });
    downloadBlob(
      res.data,
      filenameFromResponse(res.headers['content-disposition'], `ppdb-${type}.csv`),
    );
  },

  async reportPdf(type: PpdbReportType, params: PpdbListParams = {}): Promise<void> {
    const res = await ppdbHttp.get<Blob>(`${base}/reports/${type}/pdf`, {
      params: ppdbParams(params),
      responseType: 'blob',
    });
    downloadBlob(res.data, `laporan-ppdb-${type}.pdf`);
  },
};
