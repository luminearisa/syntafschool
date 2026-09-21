/**
 * Tipe modul PPDB (Penerimaan Peserta Didik Baru) — sisi admin.
 *
 * Peta 1:1 dengan `backend/routes/api/ppdb.php` + API Resources `Ppdb*`.
 * Relasi bersarang dibuat longgar (`Partial` / nullable) karena backend hanya
 * memuat relasi yang dibutuhkan tiap endpoint.
 */

/* ============================ UTIL ============================ */

export type IdName = { id: number; name: string };
export type IdCodeName = { id: number; code?: string; name: string };
export type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';

export interface PpdbOption {
  value: number | string;
  label: string;
}

/** Parameter query umum untuk seluruh endpoint daftar PPDB. */
export interface PpdbListParams {
  page?: number;
  per_page?: number;
  search?: string;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  /** Filter datar: status, period_id, wave_id, program_id, date_from, dst. */
  [key: string]: string | number | boolean | undefined;
}

/* ============================ PERIODE ============================ */

export type PpdbPeriodStatus = 'draft' | 'scheduled' | 'open' | 'closed' | 'archived';

export interface PpdbFaqItem {
  question: string;
  answer: string;
}

export interface PpdbPeriod {
  id: number;
  academic_year_id: number | null;
  code: string;
  name: string;
  slug: string;
  public_title: string | null;
  public_description: string | null;
  banner_path: string | null;
  banner_url?: string | null;
  logo_path: string | null;
  logo_url?: string | null;
  contact_name: string | null;
  contact_phone: string | null;
  contact_email: string | null;
  contact_address: string | null;
  faq: PpdbFaqItem[] | null;
  registration_start: string | null;
  registration_end: string | null;
  selection_announcement_date: string | null;
  re_registration_start: string | null;
  re_registration_end: string | null;
  status: PpdbPeriodStatus;
  description: string | null;
  created_by?: number | null;
  academic_year?: Partial<IdName> | null;
  waves_count?: number;
  programs_count?: number;
  registrations_count?: number;
  allowed_transitions?: PpdbPeriodStatus[];
  registration_open?: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface PpdbPeriodPayload {
  academic_year_id?: number | '';
  code?: string;
  name: string;
  slug?: string;
  public_title?: string;
  public_description?: string;
  contact_name?: string;
  contact_phone?: string;
  contact_email?: string;
  contact_address?: string;
  faq?: PpdbFaqItem[];
  registration_start?: string;
  registration_end?: string;
  selection_announcement_date?: string;
  re_registration_start?: string;
  re_registration_end?: string;
  status?: PpdbPeriodStatus;
  description?: string;
}

/* ============================ GELOMBANG & PROGRAM ============================ */

export type PpdbSimpleStatus = 'active' | 'inactive';

export interface PpdbWave {
  id: number;
  period_id: number;
  code: string;
  name: string;
  registration_start: string | null;
  registration_end: string | null;
  quota: number | null;
  priority: number;
  status: PpdbSimpleStatus;
  description: string | null;
  period?: Partial<PpdbPeriod> | null;
  registrations_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface PpdbWavePayload {
  period_id: number | '';
  code: string;
  name: string;
  registration_start?: string;
  registration_end?: string;
  quota?: number | '';
  priority?: number | '';
  status?: PpdbSimpleStatus;
  description?: string;
}

export interface PpdbProgram {
  id: number;
  period_id: number;
  program_id: number | null;
  education_level_id: number | null;
  code: string;
  name: string;
  quota: number | null;
  description: string | null;
  status: PpdbSimpleStatus;
  sort_order: number;
  program?: Partial<IdName> | null;
  education_level?: Partial<IdName> | null;
  period?: Partial<PpdbPeriod> | null;
  registrations_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface PpdbProgramPayload {
  period_id: number | '';
  program_id?: number | '';
  education_level_id?: number | '';
  code: string;
  name: string;
  quota?: number | '';
  description?: string;
  status?: PpdbSimpleStatus;
  sort_order?: number | '';
}

/* ============================ FORM BUILDER ============================ */

export type PpdbFieldType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'date'
  | 'email'
  | 'phone'
  | 'select'
  | 'radio'
  | 'checkbox'
  | 'multi_select'
  | 'file';

export type PpdbFieldSection =
  | 'data_calon'
  | 'data_orang_tua'
  | 'data_wali'
  | 'alamat'
  | 'sekolah_asal'
  | 'kesehatan'
  | 'tambahan';

export type PpdbQuestionType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'date'
  | 'yes_no'
  | 'single_choice'
  | 'multiple_choice'
  | 'dropdown'
  | 'file';

export interface PpdbFieldOption {
  label: string;
  value?: string;
}

export interface PpdbFormField {
  id: number;
  period_id: number;
  field_key: string;
  label: string;
  type: PpdbFieldType;
  section: PpdbFieldSection;
  placeholder: string | null;
  description: string | null;
  required: boolean;
  active: boolean;
  order: number;
  options: PpdbFieldOption[] | null;
  validation: string[] | null;
  period?: Partial<PpdbPeriod> | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface PpdbFormFieldPayload {
  period_id: number | '';
  field_key?: string;
  label: string;
  type: PpdbFieldType;
  section: PpdbFieldSection;
  placeholder?: string;
  description?: string;
  required?: boolean;
  active?: boolean;
  order?: number | '';
  options?: PpdbFieldOption[];
  validation?: string[];
}

/** `meta` pada GET form-fields — sumber pilihan dropdown UI. */
export interface PpdbFormMeta {
  sections: PpdbFieldSection[];
  section_labels: Partial<Record<PpdbFieldSection, string>>;
  types: PpdbFieldType[];
  question_types?: PpdbQuestionType[];
}

/* ============================ PERTANYAAN ============================ */

export interface PpdbQuestionOption {
  id?: number;
  label: string;
  value?: string | null;
  order?: number;
}

export interface PpdbQuestion {
  id: number;
  period_id: number;
  program_id: number | null;
  wave_id: number | null;
  code: string;
  question: string;
  description: string | null;
  type: PpdbQuestionType;
  required: boolean;
  active: boolean;
  order: number;
  options?: PpdbQuestionOption[];
  program?: Partial<PpdbProgram> | null;
  wave?: Partial<PpdbWave> | null;
  period?: Partial<PpdbPeriod> | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface PpdbQuestionPayload {
  period_id: number | '';
  program_id?: number | '';
  wave_id?: number | '';
  code: string;
  question: string;
  description?: string;
  type: PpdbQuestionType;
  required?: boolean;
  active?: boolean;
  order?: number | '';
  options?: PpdbQuestionOption[];
}

/* ============================ DOKUMEN & BIAYA ============================ */

export interface PpdbDocumentRequirement {
  id: number;
  period_id: number;
  program_id: number | null;
  wave_id: number | null;
  code: string;
  name: string;
  description: string | null;
  required: boolean;
  active: boolean;
  order: number;
  file_types: string;
  allowed_extensions?: string[];
  max_size_kb: number;
  min_size_kb: number | null;
  program?: Partial<PpdbProgram> | null;
  wave?: Partial<PpdbWave> | null;
  documents_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface PpdbDocumentRequirementPayload {
  period_id: number | '';
  program_id?: number | '';
  wave_id?: number | '';
  code: string;
  name: string;
  description?: string;
  required?: boolean;
  active?: boolean;
  order?: number | '';
  file_types: string;
  max_size_kb?: number | '';
  min_size_kb?: number | '';
}

export type PpdbFeeType = 'registration' | 'selection' | 'test' | 'psychology' | 'other';

export interface PpdbRegistrationFee {
  id: number;
  period_id: number;
  program_id: number | null;
  wave_id: number | null;
  type: PpdbFeeType;
  name: string;
  amount: string | number;
  required: boolean;
  payment_deadline: string | null;
  active: boolean;
  program?: Partial<PpdbProgram> | null;
  wave?: Partial<PpdbWave> | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface PpdbFeePayload {
  period_id: number | '';
  program_id?: number | '';
  wave_id?: number | '';
  type: PpdbFeeType;
  name: string;
  amount: number | '';
  required?: boolean;
  payment_deadline?: string;
  active?: boolean;
}

/* ============================ AKUN ORANG TUA ============================ */

export type PpdbAccountStatus = 'pending_verification' | 'active' | 'inactive' | 'blocked';

/** Baris "pendaftaran terakhir" pada detail akun orang tua (agregat, bukan resource penuh). */
export interface PpdbAccountRegistrationRow {
  id: number;
  registration_number: string;
  status: PpdbRegistrationStatus;
  status_label: string;
  payment_status: PpdbPaymentStatus;
  payment_status_label: string;
  document_status: PpdbDocumentStatus;
  document_status_label: string;
  applicant_id: number;
  applicant_name: string | null;
  /** Backend mengirim nama sebagai string, bukan objek relasi. */
  program: string | null;
  period: string | null;
  submitted_at: string | null;
  created_at: string | null;
}

/**
 * Bentuk akun orang tua seperti yang ditulis `PpdbParentAccountResource::row()`.
 *
 * Identitas dibaca dari relasi `user` (nama, email, telepon) dan `guardian`
 * (detail wali) — objek `guardian` TIDAK PERNAH ikut terkirim, kolomnya saja
 * yang diratakan (`address`, `gender_id`, `occupation_id`, dst).
 *
 * Supersety dari `PpdbRegistrationParentRef`: baris pendaftaran hanya memuat
 * kolom ringkasnya, sedangkan respons detail pendaftaran (kunci `parent`) dan
 * endpoint akun memuat bentuk lengkap ini.
 */
export interface PpdbParentAccount extends PpdbRegistrationParentRef {
  id: number;
  parent_account_id: number;
  user_id: number;
  guardian_id: number | null;
  name: string | null;
  email: string | null;
  phone: string | null;
  account_status: PpdbAccountStatus;
  account_status_label?: string | null;
  can_transact?: boolean;
  verified_at: string | null;
  last_activity_at: string | null;
  notes?: string | null;
  address?: string | null;
  gender_id?: number | null;
  religion_id?: number | null;
  occupation_id?: number | null;
  education_background_id?: number | null;
  income_range_id?: number | null;
  /**
   * Jumlah anak & pendaftaran — selalu dikirim (memakai hasil `withCount()`
   * bila ada, jadi daftar panjang tidak menimbulkan satu query per baris).
   */
  applicant_count?: number;
  registration_count?: number;
  one_account_for_all_children?: boolean;
  applicants?: PpdbApplicant[];
  children_note?: string | null;
  registration_summary?: PpdbRegistrationCounts | null;
  recent_registrations?: PpdbAccountRegistrationRow[];
  activities?: PpdbAccountActivityRow[];
  created_at?: string | null;
  updated_at?: string | null;
}

/** Jejak audit satu akun pada respons detail. */
export interface PpdbAccountActivityRow {
  id?: number;
  description?: string | null;
  event?: string | null;
  created_at?: string | null;
  properties?: Record<string, unknown> | null;
}

export interface PpdbRegistrationCounts {
  total: number;
  needs_action: number;
  by_status: Partial<Record<PpdbRegistrationStatus, number>>;
  by_payment_status: Partial<Record<PpdbPaymentStatus, number>>;
  by_document_status: Partial<Record<PpdbDocumentStatus, number>>;
}

/** Respons `POST /ppdb/parent-accounts/{id}/status`. */
export interface PpdbAccountStatusResult {
  parent_account: PpdbParentAccount;
  can_transact: boolean;
  portal_effect: string;
}

/** Respons `POST /ppdb/parent-accounts/{id}/reset-password` — password sekali tampil. */
export interface PpdbResetPasswordResult {
  parent_account?: PpdbParentAccount;
  temporary_password: string;
  one_time_credential?: boolean;
  warning?: string | null;
}

/* ============================ CALON & PENDAFTARAN ============================ */

export interface PpdbApplicant {
  id: number;
  parent_account_id: number;
  guardian_id: number | null;
  full_name: string;
  nickname: string | null;
  gender_id: number | null;
  birth_place: string | null;
  birth_date: string | null;
  nik: string | null;
  nisn: string | null;
  religion_id: number | null;
  address: string | null;
  province_id: string | null;
  city_id: string | null;
  district_id: string | null;
  village_id: string | null;
  postal_code: string | null;
  previous_school: string | null;
  previous_school_npsn: string | null;
  target_program_id: number | null;
  photo_path: string | null;
  status: PpdbSimpleStatus;
  notes: string | null;
  /** Backend mengirim NAMA master sebagai string, bukan objek relasi. */
  gender?: string | null;
  religion?: string | null;
  target_program?: string | null;
  age_years?: number | null;
  age_label?: string | null;
  /** Hanya ada bila relasi `parentAccount` termuat; objek `parent_account` TIDAK pernah dikirim. */
  parent_name?: string | null;
  /**
   * Daftar berkas calon ini. `PpdbApplicantResource` hanya menulisnya saat flag
   * `withRegistrations` aktif (endpoint `show` portal/admin) — daftar admin
   * MEMUAT relasinya tetapi tidak pernah menulis kolomnya (lihat laporan audit),
   * jadi baca dari sisi frontend harus defensif.
   */
  registrations?: PpdbRegistration[];
  created_at?: string | null;
  updated_at?: string | null;
}

export type PpdbRegistrationStatus =
  | 'draft'
  | 'submitted'
  | 'under_review'
  | 'need_revision'
  | 'verified'
  | 'selection'
  | 'passed'
  | 'failed'
  | 'waitlisted'
  | 're_registration'
  | 'completed'
  | 'cancelled';

export type PpdbPaymentStatus =
  | 'unpaid'
  | 'pending'
  | 'paid'
  | 'failed'
  | 'expired'
  | 'cancelled'
  | 'refunded';

export type PpdbDocumentStatus = 'not_uploaded' | 'under_review' | 'approved' | 'rejected';

export type PpdbSelectionResult = 'passed' | 'failed' | 'waitlisted';

/**
 * Kolom `parent` pada baris pendaftaran — hanya ada bila relasi akun orang tua
 * ikut dimuat (`PpdbRegistrationResource::row()`), dan isinya potongan dari
 * akun lengkap (`PpdbParentAccount`), bukan salinan dengan kunci lain.
 */
export interface PpdbRegistrationParentRef {
  parent_account_id: number;
  user_id: number | null;
  name: string | null;
  email: string | null;
  phone: string | null;
  account_status: PpdbAccountStatus | null;
  account_status_label?: string | null;
}

export interface PpdbRegistration {
  id: number;
  registration_number: string;
  period_id: number;
  wave_id: number | null;
  program_id: number;
  applicant_id: number;
  parent_account_id: number;
  status: PpdbRegistrationStatus;
  /** Label status yang dihitung server — selalu ada pada baris daftar. */
  status_label?: string | null;
  payment_status: PpdbPaymentStatus;
  payment_status_label?: string | null;
  document_status: PpdbDocumentStatus;
  document_status_label?: string | null;
  /** Tindakan lanjutan yang disarankan server untuk status ini. */
  next_action?: string | null;
  is_editable?: boolean;
  submitted_at: string | null;
  verified_at: string | null;
  verified_by?: number | null;
  selection_result: PpdbSelectionResult | null;
  announced_at: string | null;
  completed_at: string | null;
  student_id: number | null;
  revision_note: string | null;
  notes: string | null;
  period?: Partial<PpdbPeriod> | null;
  wave?: Partial<PpdbWave> | null;
  program?: Partial<PpdbProgram> | null;
  applicant?: Partial<PpdbApplicant> | null;
  /** Kunci nyata pada payload pendaftaran (`PpdbRegistrationResource::row()`). */
  parent?: PpdbRegistrationParentRef | null;
  /**
   * KUNCI LAMA YANG TIDAK PERNAH DIKIRIM SERVER.
   * Dipertahankan hanya agar halaman verifikasi lama tetap terkompilasi; baca
   * `parent` atau `parent_name`. Lihat laporan audit.
   */
  parent_account?: Partial<PpdbParentAccount> | null;
  student?: { id: number; nis: string; full_name: string } | null;
  allowed_transitions?: PpdbRegistrationStatus[];
  editable?: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

/** Ringkasan dokumen pada layar admin/portal. */
export interface PpdbApplicantDocument {
  id: number;
  registration_id: number;
  applicant_id: number;
  requirement_id: number | null;
  requirement_name: string;
  version: number;
  is_active: boolean;
  file_path: string;
  file_url?: string | null;
  original_filename: string;
  mime_type: string;
  file_size: number;
  status: 'uploaded' | 'under_review' | 'approved' | 'rejected';
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by?: number | null;
  uploaded_at: string | null;
  uploaded_by?: number | null;
  reviewer_name?: string | null;
  uploader_name?: string | null;
  registration?: Partial<PpdbRegistration> | null;
  applicant?: Partial<PpdbApplicant> | null;
  created_at?: string | null;
}

export interface PpdbRegistrationAnswer {
  id: number;
  registration_id: number;
  question_id: number | null;
  field_key: string | null;
  value: string | null;
  value_json: string[] | null;
  label?: string | null;
  typed_value?: string | number | boolean | string[] | null;
}

export interface PpdbRegistrationHistory {
  id: number;
  registration_id: number;
  action: string;
  from_status: PpdbRegistrationStatus | null;
  to_status: PpdbRegistrationStatus | null;
  note: string | null;
  changed_by: number | null;
  changed_by_name?: string | null;
  created_at: string;
}

/* ============================ TAGIHAN & PEMBAYARAN ============================ */

export type PpdbInvoiceType = 'registration' | 're_registration' | 'other';

export interface PpdbInvoiceItem {
  id: number;
  invoice_id: number;
  fee_id: number | null;
  name: string;
  amount: string | number;
}

export interface PpdbInvoice {
  id: number;
  registration_id: number;
  number: string;
  type: PpdbInvoiceType;
  type_label?: string;
  status_label?: string;
  is_paid?: boolean;
  is_overdue?: boolean;
  amount: string | number;
  discount_amount: string | number;
  total_amount: string | number;
  paid_amount: string | number;
  outstanding_amount?: string | number;
  status: PpdbPaymentStatus;
  due_date: string | null;
  issued_at: string | null;
  paid_at: string | null;
  notes: string | null;
  items?: PpdbInvoiceItem[];
  payments?: PpdbPayment[];
  registration?: Partial<PpdbRegistration> | null;
  instructions?: PpdbPaymentInstruction[];
  created_at?: string | null;
}

export interface PpdbPayment {
  id: number;
  invoice_id: number;
  method: 'manual' | 'midtrans';
  amount: string | number;
  status: 'pending' | 'paid' | 'failed' | 'expired' | 'refunded';
  payment_method_id: number | null;
  payment_method?: Partial<IdName> | null;
  provider: string | null;
  provider_reference: string | null;
  proof_path: string | null;
  proof_url?: string | null;
  paid_at: string | null;
  verified_at: string | null;
  verified_by?: number | null;
  verified_by_name?: string | null;
  note: string | null;
  created_at?: string | null;
}

/** Rekening instruksi pembayaran manual (dari master `payment_methods`). */
export interface PpdbPaymentInstruction {
  id: number;
  name: string;
  account_number?: string | null;
  account_name?: string | null;
  bank?: string | null;
  notes?: string | null;
}

/* ============================ SELEKSI & PENGUMUMAN ============================ */

export type PpdbStageType =
  | 'administrative'
  | 'academic_test'
  | 'interview'
  | 'tahfidz'
  | 'psychological_test'
  | 'health_check'
  | 'other';

export type PpdbStageStatus = 'draft' | 'scheduled' | 'ongoing' | 'done' | 'cancelled';

export interface PpdbSelectionStage {
  id: number;
  period_id: number;
  program_id: number | null;
  type: PpdbStageType;
  type_label?: string | null;
  name: string;
  scheduled_at: string | null;
  location: string | null;
  quota: number | null;
  passing_score: string | number | null;
  /** Porsi tahap pada skor gabungan; total satu grup harus tepat 100. */
  weight?: number;
  status: PpdbStageStatus;
  order: number;
  notes: string | null;
  period?: Partial<PpdbPeriod> | null;
  program?: Partial<PpdbProgram> | null;
  participants_count?: number;
  scored_participants_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

/** Respons `GET /ppdb/selection` — daftar tahap dibungkus objek, bukan paginasi datar. */
export interface PpdbSelectionListResponse {
  stages: PpdbSelectionStage[];
  weight_groups?: Record<string, PpdbWeightSummary> | null;
  pagination?: { current_page: number; last_page: number; per_page: number; total: number } | null;
  required_weight_total?: number;
}

export interface PpdbWeightSummary {
  total: number;
  expected: number;
  remaining: number;
  stages: { stage_id: number; name: string; program_id: number | null; weight: number }[];
  stages_count: number;
  valid: boolean;
  error: string | null;
}

/** Respons `GET|PUT /ppdb/selection-stages/weights`. */
export interface PpdbSelectionWeightsResponse {
  period_id: number;
  program_id: number | null;
  stages: { stage_id: number; name: string; type: PpdbStageType; program_id: number | null; weight: number }[];
  summary: PpdbWeightSummary;
  required_total: number;
}

export interface PpdbSelectionWeightsPayload {
  period_id: number;
  program_id?: number | null;
  weights: { stage_id: number; weight: number }[];
}

export interface PpdbSelectionStagePayload {
  period_id: number | '';
  program_id?: number | '';
  type: PpdbStageType;
  name: string;
  scheduled_at?: string;
  location?: string;
  quota?: number | '';
  passing_score?: number | '';
  weight?: number | '';
  status?: PpdbStageStatus;
  order?: number | '';
  notes?: string;
}

export type PpdbParticipantStatus = 'invited' | 'attended' | 'absent' | 'scored';

/** Identitas calon pada baris peserta seleksi — bersarang di dalam `registration`. */
export interface PpdbParticipantRegistrationRef {
  id?: number;
  registration_number?: string | null;
  status?: PpdbRegistrationStatus | null;
  payment_status?: PpdbPaymentStatus | null;
  selection_result?: PpdbSelectionResult | null;
  program_id?: number | null;
  wave_id?: number | null;
  applicant_name?: string | null;
  applicant_nickname?: string | null;
  applicant?: Partial<PpdbApplicant> | null;
}

export interface PpdbSelectionParticipant {
  id: number;
  stage_id: number;
  registration_id: number;
  status: PpdbParticipantStatus;
  score: string | number | null;
  result: PpdbSelectionResult | null;
  attended_at: string | null;
  notes: string | null;
  scored_by?: number | null;
  scored_by_name?: string | null;
  scored_at?: string | null;
  composite?: number | string | null;
  stage?: (Partial<PpdbSelectionStage> & { weight?: number | null }) | null;
  registration?: PpdbParticipantRegistrationRef | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export type PpdbAnnouncementChannel = 'portal' | 'email' | 'whatsapp' | 'all';
export type PpdbAnnouncementStatus = 'draft' | 'published' | 'archived';

export interface PpdbAnnouncement {
  id: number;
  period_id: number;
  title: string;
  body: string;
  channel: PpdbAnnouncementChannel;
  status: PpdbAnnouncementStatus;
  published_at: string | null;
  published_by?: number | null;
  publisher_name?: string | null;
  period?: Partial<PpdbPeriod> | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface PpdbAnnouncementPayload {
  period_id: number | '';
  title: string;
  body: string;
  channel?: PpdbAnnouncementChannel;
  status?: PpdbAnnouncementStatus;
}

/** Respons `POST /ppdb/selection-stages/{stage}/participants`: daftar, bukan angka. */
export interface PpdbParticipantRegistrationResult {
  created: { id?: number; registration_id: number; registration_number?: string | null }[];
  skipped: { registration_id: number; reason: string }[];
}

/* ============================ DASHBOARD ============================ */

export interface PpdbDashboard {
  period: Partial<PpdbPeriod> | null;
  scope: 'period' | 'all';
  updated_at: string | null;
  statistics: Record<string, number> & {
    total_applicants: number;
    total_registrations: number;
    by_status: Record<PpdbRegistrationStatus, number>;
    by_payment_status: Record<PpdbPaymentStatus, number>;
    by_document_status: Record<PpdbDocumentStatus, number>;
    by_account_status?: Partial<Record<PpdbAccountStatus, number>>;
  };
  funnel: { key: string; label: string; total: number; percent: number }[];
  financial: {
    billed: number;
    collected: number;
    outstanding: number;
    paid_invoices: number;
    unpaid_invoices: number;
    by_method?: { name: string; total: number; count: number }[];
  };
  distribution: {
    programs: {
      id: number;
      name: string;
      quota: number | null;
      registrations: number;
      passed?: number;
      percent_of_quota: number | null;
    }[];
    waves: {
      id: number;
      name: string;
      quota: number | null;
      registrations: number;
      percent_of_quota: number | null;
    }[];
    genders?: { name: string; total: number }[];
  };
  pending_actions: { key: string; label: string; count: number; route_key?: string }[];
  trend: { period: string; total: number }[];
  top?: { label: string; total: number }[];
}

/* ============================ LAPORAN ============================ */

export interface PpdbReportColumn {
  key: string;
  label: string;
}

export interface PpdbReportPayload<Row = Record<string, string | number | boolean | null>> {
  type: PpdbReportType;
  title: string;
  generated_at: string;
  filters: Record<string, unknown>;
  columns: PpdbReportColumn[];
  rows: Row[];
  summary: { key?: string; label: string; value: string | number }[] | Record<string, number>;
  meta?: Record<string, unknown>;
}

export type PpdbReportType = 'registration' | 'payment' | 'selection' | 'document';

/** `type` pada GET /ppdb/export (termasuk dua listing datar). */
export type PpdbExportType =
  | PpdbReportType
  | 'applicants'
  | 'parent_accounts';

export interface PpdbFilterOptions {
  period_id?: number | '';
  wave_id?: number | '';
  program_id?: number | '';
  status?: string;
  payment_status?: string;
  document_status?: string;
  date_from?: string;
  date_to?: string;
}

/**
 * Baris opsi referensi dari `GET /ppdb/lookups`.
 *
 * Kelompok berbeda membawa kolom berbeda (`code` hanya untuk referensi yang
 * punya kode, `jenjang` hanya `education_levels`, `education_level_id` +
 * `description` hanya `programs`), karena jawaban endpoint ini memang
 * whitelist: tidak ada kolom lain yang dikirim pulang.
 */
export interface PpdbLookupOption {
  id: number;
  code?: string;
  name: string;
  jenjang?: string;
  education_level_id?: number | null;
  description?: string | null;
}

/**
 * Whitelist referensi master data yang dipakai formulir PPDB.
 *
 * Halaman panitia membacanya dari sini, bukan dari `/master/*` atau `/programs`:
 * peran `panitia-ppdb` hanya memegang `ppdb.*`, sehingga dropdown yang menunjuk
 * endpoint modul lain menjawab 403 dan tampil diam-diam tanpa isi.
 */
export interface PpdbLookups {
  genders: PpdbLookupOption[];
  religions: PpdbLookupOption[];
  education_levels: PpdbLookupOption[];
  programs: PpdbLookupOption[];
  academic_years: PpdbLookupOption[];
  payment_methods: PpdbLookupOption[];
}

export type PpdbLookupGroup = keyof PpdbLookups;

/* ============================ SANGGAH HASIL SELEKSI ============================ */

export type PpdbAppealStatus = 'pending' | 'accepted' | 'rejected';

/**
 * Satu sanggahan orang tua atas hasil seleksi.
 *
 * Accepted TIDAK mengubah hasil: ia hanya membuka kembali berkas untuk diputus
 * panitia lewat penetapan hasil yang eksplisit (`result_changed` selalu false
 * pada respons keputusan). Kolom `attachment_*` dan `parent_name` hanya muncul
 * untuk pembaca panitia.
 */
export interface PpdbAppeal {
  id: number;
  registration_id: number;
  period_id: number;
  parent_account_id: number;
  reason: string;
  attachment_path?: string | null;
  attachment_url?: string | null;
  status: PpdbAppealStatus;
  status_label: string;
  is_decided: boolean;
  review_note: string | null;
  reviewed_by?: number | null;
  reviewed_by_name?: string;
  reviewed_at: string | null;
  registration?: {
    id: number;
    registration_number: string;
    status: PpdbRegistrationStatus;
    selection_result: PpdbSelectionResult | null;
    program_id: number | null;
    wave_id: number | null;
    applicant_name?: string | null;
    parent_name?: string;
  } | null;
  period?: {
    id: number;
    code: string;
    name: string;
    appeal_start: string | null;
    appeal_end: string | null;
  } | null;
  created_at: string | null;
  updated_at: string | null;
}

/**
 * Kelayakan mengirim sanggahan pada satu pendaftaran (area portal).
 * `reasons` berisi penjelasan backend mengapa tombol sanggah tidak aktif —
 * UI menampilkannya alih-alih menyembunyikan formulir.
 */
export interface PpdbAppealEligibility {
  can_appeal: boolean;
  window_open: boolean;
  appeal_start: string | null;
  appeal_end: string | null;
  days_left: number | null;
  result: PpdbSelectionResult | null;
  pending_id: number | null;
  reasons: string[];
}

/** `GET /ppdb/portal/registrations/{id}/appeals`. */
export interface PpdbPortalAppeals {
  appeals: PpdbAppeal[];
  eligibility: PpdbAppealEligibility;
  appealable_results: string[];
}

/** `POST /ppdb/appeals/{id}/decide` — hasil seleksi sengaja tidak ikut berubah. */
export interface PpdbAppealDecisionResult {
  appeal: PpdbAppeal;
  selection_result: PpdbSelectionResult | null;
  result_changed: boolean;
  next_action: string | null;
}
