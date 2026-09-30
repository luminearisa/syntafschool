import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  ClinicCertificate,
  ClinicDashboard,
  ClinicDocument,
  ClinicEmergency,
  ClinicFollowUp,
  ClinicInjury,
  ClinicMe,
  ClinicMedicationRow,
  ClinicMetadata,
  ClinicAllergyCheck,
  ClinicEmergencyCreated,
  ClinicEmergencyDetail,
  ClinicDispensingOutcome,
  ClinicDischargeOutcome,
  ClinicMedicationBatchRow,
  ClinicPatientDirectory,
  ClinicPatientProfile,
  ClinicPortalChildren,
  ClinicPortalVisit,
  ClinicPrescriptionCreated,
  ClinicQueueBoard,
  ClinicScreeningTargets,
  ClinicStudentSearchResult,
  ClinicVitalsResult,
  ClinicPrescription,
  ClinicReferral,
  ClinicScreening,
  ClinicScreening as ClinicScreeningRow,
  ClinicVaccination,
  ClinicVisitRow,
  ClinicWorkspace,
} from '../types';

/** Parameter filter yang diteruskan apa adanya ke backend (dibersihkan dari nilai kosong). */
type Params = Record<string, unknown>;

const clean = (params: Params): Params =>
  Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== undefined && v !== null));

export const clinicApi = {
  // ------------------------------------------------------------- konteks
  me: () => api.get<ApiResponse<ClinicMe>>('/clinic/me').then((r) => r.data.data),

  metadata: () => api.get<ApiResponse<ClinicMetadata>>('/clinic/metadata').then((r) => r.data.data),

  dashboard: () => api.get<ApiResponse<ClinicDashboard>>('/clinic/dashboard').then((r) => r.data.data),

  health: () => api.get<ApiResponse<Record<string, unknown>>>('/clinic/health').then((r) => r.data.data),

  // -------------------------------------------------------------- pasien
  patients: (params: Params = {}) =>
    api
      .get<ApiResponse<ClinicPatientDirectory>>('/clinic/patients', {
        params: clean(params),
      })
      .then((r) => r.data.data),

  searchStudents: (q: string) =>
    api
      .get<ApiResponse<ClinicStudentSearchResult[]>>('/clinic/patients/search-students', { params: { q } })
      .then((r) => r.data.data),

  registerPatient: (payload: Params) =>
    api.post<ApiResponse<ClinicPatientProfile>>('/clinic/patients', payload).then((r) => r.data.data),

  patient: (id: number) =>
    api.get<ApiResponse<ClinicPatientProfile>>(`/clinic/patients/${id}`).then((r) => r.data.data),

  updatePatient: (id: number, payload: Params) =>
    api.put<ApiResponse<ClinicPatientProfile>>(`/clinic/patients/${id}`, payload).then((r) => r.data.data),

  patientVitals: (id: number, limit = 50) =>
    api
      .get<ApiResponse<ClinicWorkspace['vitals']>>(`/clinic/patients/${id}/vitals`, { params: { limit } })
      .then((r) => r.data.data),

  patientVisits: (id: number) =>
    api.get<ApiResponse<ClinicVisitRow[]>>(`/clinic/patients/${id}/visits`).then((r) => r.data.data),

  operationalNotes: (id: number) =>
    api
      .get<ApiResponse<{ student_id: number | null; notes: unknown[]; disclaimer: string }>>(
        `/clinic/patients/${id}/operational-notes`,
      )
      .then((r) => r.data.data),

  addAllergy: (patientId: number, payload: Params) =>
    api.post(`/clinic/patients/${patientId}/allergies`, payload).then((r) => r.data),

  resolveAllergy: (allergyId: number, status: string) =>
    api.put(`/clinic/allergies/${allergyId}`, { status }).then((r) => r.data),

  addCondition: (patientId: number, payload: Params) =>
    api.post(`/clinic/patients/${patientId}/conditions`, payload).then((r) => r.data),

  addHistory: (patientId: number, payload: Params) =>
    api.post(`/clinic/patients/${patientId}/histories`, payload).then((r) => r.data),

  // --------------------------------------------------------- kunjungan
  visits: (params: Params = {}) =>
    api.get<PaginatedResponse<ClinicVisitRow>>('/clinic/visits', { params: clean(params) }).then((r) => r.data),

  createVisit: (payload: Params) =>
    api.post<ApiResponse<ClinicWorkspace>>('/clinic/visits', payload).then((r) => r.data.data),

  workspace: (id: number) =>
    api.get<ApiResponse<ClinicWorkspace>>(`/clinic/visits/${id}`).then((r) => r.data.data),

  triage: (id: number, payload: Params) =>
    api.post(`/clinic/visits/${id}/triage`, payload).then((r) => r.data),

  recordVitals: (id: number, payload: Params) =>
    api
      .post<ApiResponse<ClinicVitalsResult>>(`/clinic/visits/${id}/vitals`, payload)
      .then((r) => r.data.data),

  saveAssessment: (id: number, payload: Params) =>
    api.post(`/clinic/visits/${id}/assessment`, payload).then((r) => r.data),

  addDiagnosis: (id: number, payload: Params) =>
    api.post(`/clinic/visits/${id}/diagnoses`, payload).then((r) => r.data),

  addProcedure: (id: number, payload: Params) =>
    api.post(`/clinic/visits/${id}/procedures`, payload).then((r) => r.data),

  startObservation: (id: number, payload: Params) =>
    api.post(`/clinic/visits/${id}/observations`, payload).then((r) => r.data),

  recordObservation: (observationId: number, payload: Params) =>
    api.post(`/clinic/observations/${observationId}/records`, payload).then((r) => r.data),

  endObservation: (observationId: number, payload: Params) =>
    api.put(`/clinic/observations/${observationId}/end`, payload).then((r) => r.data),

  createFollowUp: (visitId: number, payload: Params) =>
    api.post(`/clinic/visits/${visitId}/follow-ups`, payload).then((r) => r.data),

  discharge: (id: number, payload: Params) =>
    api.post<ApiResponse<ClinicDischargeOutcome>>(`/clinic/visits/${id}/discharge`, payload).then((r) => r.data.data),

  updateVisitStatus: (id: number, status: string, reason?: string) =>
    api.put(`/clinic/visits/${id}/status`, { status, reason }).then((r) => r.data),

  // ------------------------------------------------------------ antrean
  queue: () => api.get<ApiResponse<ClinicQueueBoard>>('/clinic/queue').then((r) => r.data.data),

  callNext: (clinicId?: number) =>
    api.post('/clinic/queue/call-next', { clinic_id: clinicId }).then((r) => r.data),

  moveQueue: (visitId: number, stage: string) =>
    api.put(`/clinic/queue/${visitId}/stage`, { stage }).then((r) => r.data),

  // ------------------------------------------------------------ farmasi
  medications: (params: Params = {}) =>
    api.get<ApiResponse<ClinicMedicationRow[]>>('/clinic/medications', { params: clean(params) }).then((r) => r.data.data),

  createMedication: (payload: Params) => api.post('/clinic/medications', payload).then((r) => r.data),

  updateMedication: (id: number, payload: Params) =>
    api.put(`/clinic/medications/${id}`, payload).then((r) => r.data),

  batches: (medicationId: number) =>
    api
      .get<ApiResponse<ClinicMedicationBatchRow[]>>(`/clinic/medications/${medicationId}/batches`)
      .then((r) => r.data.data),

  createBatch: (medicationId: number, payload: Params) =>
    api.post(`/clinic/medications/${medicationId}/batches`, payload).then((r) => r.data),

  allergyCheck: (patientId: number, medicationIds: number[]) =>
    api
      .post<ApiResponse<ClinicAllergyCheck>>('/clinic/pharmacy/allergy-check', {
        patient_id: patientId,
        medication_ids: medicationIds,
      })
      .then((r) => r.data.data),

  prescriptions: (params: Params = {}) =>
    api.get<ApiResponse<ClinicPrescription[]>>('/clinic/prescriptions', { params: clean(params) }).then((r) => r.data.data),

  pendingPrescriptions: () =>
    api.get<ApiResponse<ClinicPrescription[]>>('/clinic/prescriptions/pending').then((r) => r.data.data),

  prescription: (id: number) =>
    api.get<ApiResponse<ClinicPrescription>>(`/clinic/prescriptions/${id}`).then((r) => r.data.data),

  createPrescription: (visitId: number, payload: Params) =>
    api
      .post<ApiResponse<ClinicPrescriptionCreated>>(`/clinic/visits/${visitId}/prescriptions`, payload)
      .then((r) => r.data),

  cancelPrescription: (id: number, reason: string) =>
    api.put(`/clinic/prescriptions/${id}/cancel`, { reason }).then((r) => r.data),

  dispense: (prescriptionId: number, payload: Params) =>
    api
      .post<ApiResponse<ClinicDispensingOutcome>>(`/clinic/prescriptions/${prescriptionId}/dispense`, payload)
      .then((r) => r.data.data),

  administer: (visitId: number, payload: Params) =>
    api.post(`/clinic/visits/${visitId}/administrations`, payload).then((r) => r.data),

  correctAdministration: (id: number, payload: Params) =>
    api.put(`/clinic/administrations/${id}`, payload).then((r) => r.data),

  // --------------------------------------------- gawat darurat & cedera
  emergencies: (params: Params = {}) =>
    api.get<ApiResponse<ClinicEmergency[]>>('/clinic/emergencies', { params: clean(params) }).then((r) => r.data.data),

  openEmergency: (payload: Params) =>
    api.post<ApiResponse<ClinicEmergencyCreated>>('/clinic/emergencies', payload).then((r) => r.data.data),

  emergency: (id: number) =>
    api.get<ApiResponse<ClinicEmergencyDetail>>(`/clinic/emergencies/${id}`).then((r) => r.data.data),

  updateEmergency: (id: number, payload: Params) =>
    api.put(`/clinic/emergencies/${id}`, payload).then((r) => r.data),

  closeEmergency: (id: number, outcome: string) =>
    api.put(`/clinic/emergencies/${id}/close`, { outcome }).then((r) => r.data),

  injuries: (params: Params = {}) =>
    api.get<ApiResponse<ClinicInjury[]>>('/clinic/injuries', { params: clean(params) }).then((r) => r.data.data),

  recordInjury: (payload: Params) =>
    api.post<ApiResponse<ClinicInjury>>('/clinic/injuries', payload).then((r) => r.data.data),

  updateInjury: (id: number, payload: Params) =>
    api.put(`/clinic/injuries/${id}`, payload).then((r) => r.data),

  closeInjury: (id: number, followUp?: string) =>
    api.put(`/clinic/injuries/${id}/close`, { follow_up: followUp }).then((r) => r.data),

  // --------------------------------------------- rujukan & tindak lanjut
  referrals: (params: Params = {}) =>
    api.get<ApiResponse<ClinicReferral[]>>('/clinic/referrals', { params: clean(params) }).then((r) => r.data.data),

  createReferral: (visitId: number, payload: Params) =>
    api.post<ApiResponse<ClinicReferral>>(`/clinic/visits/${visitId}/referrals`, payload).then((r) => r.data.data),

  updateReferral: (id: number, payload: Params) =>
    api.put(`/clinic/referrals/${id}`, payload).then((r) => r.data),

  followUps: (params: Params = {}) =>
    api.get<ApiResponse<ClinicFollowUp[]>>('/clinic/follow-ups', { params: clean(params) }).then((r) => r.data.data),

  completeFollowUp: (id: number, notes?: string) =>
    api.put(`/clinic/follow-ups/${id}/complete`, { notes }).then((r) => r.data),

  // ------------------------------------------- skrining & imunisasi
  screeningPrograms: () =>
    api
      .get<ApiResponse<{ id: number; code: string; name: string; type: string; status: string; period_start: string | null; period_end: string | null }[]>>(
        '/clinic/screening-programs',
      )
      .then((r) => r.data.data),

  createScreeningProgram: (payload: Params) =>
    api.post('/clinic/screening-programs', payload).then((r) => r.data),

  screeningTargets: (programId: number, params: Params = {}) =>
    api
      .get<ApiResponse<ClinicScreeningTargets>>(`/clinic/screening-programs/${programId}/targets`, {
        params: clean(params),
      })
      .then((r) => r.data.data),

  screenings: (params: Params = {}) =>
    api.get<ApiResponse<ClinicScreeningRow[]>>('/clinic/screenings', { params: clean(params) }).then((r) => r.data.data),

  recordScreening: (programId: number, payload: Params) =>
    api.post<ApiResponse<ClinicScreening>>(`/clinic/screening-programs/${programId}/screenings`, payload).then((r) => r.data.data),

  vaccinations: (params: Params = {}) =>
    api.get<ApiResponse<ClinicVaccination[]>>('/clinic/vaccinations', { params: clean(params) }).then((r) => r.data.data),

  recordVaccination: (payload: Params) =>
    api.post<ApiResponse<ClinicVaccination>>('/clinic/vaccinations', payload).then((r) => r.data.data),

  // ------------------------------------- surat keterangan & dokumen
  certificates: (params: Params = {}) =>
    api.get<ApiResponse<ClinicCertificate[]>>('/clinic/certificates', { params: clean(params) }).then((r) => r.data.data),

  requestCertificate: (payload: Params) =>
    api.post<ApiResponse<ClinicCertificate>>('/clinic/certificates', payload).then((r) => r.data.data),

  reviewCertificate: (id: number, payload: Params) =>
    api.put(`/clinic/certificates/${id}/review`, payload).then((r) => r.data),

  issueCertificate: (id: number) => api.put(`/clinic/certificates/${id}/issue`).then((r) => r.data),

  documents: (patientId: number) =>
    api.get<ApiResponse<ClinicDocument[]>>(`/clinic/patients/${patientId}/documents`).then((r) => r.data.data),

  uploadDocument: (patientId: number, file: File, payload: Params = {}) => {
    const form = new FormData();
    form.append('file', file);
    form.append('patient_id', String(patientId));
    Object.entries(payload).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') form.append(key, String(value));
    });

    return api.post('/clinic/documents', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
  },

  /** Unduh dokumen: blob + nama berkas dari header Content-Disposition. */
  downloadDocument: async (documentId: number): Promise<{ blob: Blob; filename: string }> => {
    const response = await api.get(`/clinic/documents/${documentId}/download`, { responseType: 'blob' });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const match = disposition.match(/filename="?([^\";]+)"?/);

    return { blob: response.data as Blob, filename: match?.[1] ?? 'dokumen-kesehatan' };
  },

  // ------------------------------------------------------------ laporan
  reportOverview: (params: Params = {}) =>
    api
      .get<ApiResponse<Record<string, unknown>>>('/clinic/reports/overview', { params: clean(params) })
      .then((r) => r.data.data),

  report: (name: string, params: Params = {}) =>
    api.get<ApiResponse<Record<string, unknown>>>(`/clinic/reports/${name}`, { params: clean(params) }).then((r) => r.data.data),

  accessLog: (params: Params = {}) =>
    api.get<ApiResponse<Record<string, unknown>[]>>('/clinic/reports/access-log', { params: clean(params) }).then((r) => r.data.data),

  /** Ekspor CSV: diambil sebagai blob agar dapat disimpan tanpa membuka tab baru. */
  exportReport: async (
    name: string,
    params: Params = {},
  ): Promise<{ blob: Blob; filename: string }> => {
    const response = await api.get(`/clinic/reports/export/${name}`, {
      params: clean(params),
      responseType: 'blob',
    });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const match = disposition.match(/filename="?([^\";]+)"?/);

    return { blob: response.data as Blob, filename: match?.[1] ?? `laporan-clinic-${name}.csv` };
  },

  // ------------------------------------------------- portal orang tua
  portalChildren: () =>
    api.get<ApiResponse<ClinicPortalChildren>>('/clinic/portal/children').then((r) => r.data.data),

  portalChild: (patientId: number) =>
    api.get<ApiResponse<Record<string, unknown>>>(`/clinic/portal/patients/${patientId}`).then((r) => r.data.data),

  portalVisits: (patientId: number) =>
    api
      .get<ApiResponse<ClinicPortalVisit[]>>(`/clinic/portal/patients/${patientId}/visits`)
      .then((r) => r.data.data),

  portalCertificates: (patientId: number) =>
    api.get<ApiResponse<ClinicCertificate[]>>(`/clinic/portal/patients/${patientId}/certificates`).then((r) => r.data.data),
};

/** Nama tahap antrean yang dapat dipilih petugas, berurutan. */
export const QUEUE_STAGES = [
  { value: 'WAITING', label: 'Menunggu' },
  { value: 'TRIAGE', label: 'Triase' },
  { value: 'WAITING_PROVIDER', label: 'Menunggu Penanggung Jawab' },
  { value: 'IN_EXAMINATION', label: 'Pemeriksaan' },
  { value: 'PHARMACY', label: 'Farmasi' },
  { value: 'DISCHARGE', label: 'Pemulangan' },
  { value: 'COMPLETED', label: 'Selesai' },
] as const;

/** Label hasil penyerahan obat dari Inventory. */
export const INVENTORY_RESULT_LABEL: Record<string, string> = {
  PENDING: 'Menunggu',
  SUCCESS: 'Berhasil',
  INSUFFICIENT_STOCK: 'Stok kurang',
  EXPIRED: 'Kedaluwarsa',
  NOT_AVAILABLE: 'Tidak tersedia di Inventory',
  FAILED: 'Gagal',
};
