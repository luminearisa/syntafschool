/**
 * Tipe modul PPDB — halaman publik & portal orang tua.
 *
 * Setiap bentuk di sini mem-follow sumber respons yang sebenarnya:
 * `PpdbPublicController`, `PpdbPortalController`, `PpdbRegistrationController`
 * (sisi portal → `PpdbRegistrationDetailResource`), `PpdbApplicantController`,
 * `PpdbDocumentService::annotated()`, `PpdbInvoiceController::forRegistration`,
 * `PpdbPaymentController::store`, `PpdbSelectionController::forRegistration`,
 * `PpdbAppealController::forRegistration`, `PpdbNotificationController` dan
 * `PpdbScopeService` (skema formulir dinamis).
 *
 * Catatan penting: detail pendaftaran itu FLAT — baris pendaftaran
 * (`PpdbRegistrationResource::row()`) digabung langsung dengan
 * `PpdbRegistrationQueryService::detail()`, jadi tidak ada pembungkus
 * `registration`.
 */

import type {
  PpdbAccountStatus,
  PpdbApplicant,
  PpdbApplicantDocument,
  PpdbDocumentStatus,
  PpdbFieldOption,
  PpdbFieldSection,
  PpdbFieldType,
  PpdbInvoice,
  PpdbPayment,
  PpdbPeriod,
  PpdbQuestionOption,
  PpdbQuestionType,
  PpdbRegistration,
  PpdbRegistrationFee,
  PpdbRegistrationStatus,
  PpdbSelectionResult,
  PpdbPaymentStatus,
  IdName,
} from './ppdb';

/* ============================ PAKAIAN UMUM ============================ */

/** Rujungan ringkas `{id, code, name}` dari `PpdbRegistrationResource::ref()`. */
export interface PpdbRef {
  id: number | null;
  code: string | null;
  name: string | null;
}

/** Tenggat dari `PpdbRegistrationQueryService::deadlines()` (tanggal lampau dibuang). */
export interface PpdbDeadline {
  key: string;
  label: string;
  date: string;
  days_left: number;
  state: 'soon' | 'upcoming' | 'passed';
  wave_id: number | null;
}

/** Aksi lanjutan — `PpdbRegistrationQueryService::nextAction()`, tidak pernah null. */
export interface PpdbNextAction {
  key: string;
  label: string;
  status: PpdbRegistrationStatus | null;
  status_label: string;
}

/** Satu opsi `{value,label}` seperti `PpdbRegistrationQueryService::options()`. */
export interface PpdbOptionValue<T extends string = string> {
  value: T;
  label: string;
}

/**
 * `PpdbApplicantResource::row()`.
 *
 * Kolom relasi datang sebagai NAMA (string), bukan objek: `gender`, `religion`,
 * dan `target_program` di-`?->name` oleh resource, jadi `PpdbApplicant` pada
 * `types/ppdb.ts` tidak cocok untuk dibaca langsung di portal.
 */
export interface PpdbPortalApplicant extends Omit<
  PpdbApplicant,
  'gender' | 'religion' | 'target_program' | 'parent_account' | 'registrations' | 'registrations_count'
> {
  gender: string | null;
  religion: string | null;
  target_program: string | null;
  age_years: number | null;
  age_label: string | null;
}

/** `PpdbParentAccountResource::row()` — dipakai dashboard, profil, dan detail. */
export interface PpdbPortalParentRow {
  id: number;
  parent_account_id: number;
  user_id: number;
  guardian_id: number | null;
  name: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  gender_id: number | null;
  religion_id: number | null;
  occupation_id: number | null;
  education_background_id: number | null;
  income_range_id: number | null;
  account_status: PpdbAccountStatus;
  account_status_label: string;
  can_transact: boolean;
  verified_at: string | null;
  last_activity_at: string | null;
  applicant_count: number;
  registration_count: number;
  one_account_for_all_children: boolean;
  created_at: string | null;
}

/* ============================ HALAMAN PUBLIK ============================ */

/** Kuota periode (`quotaSummaries()`), dipakai daftar publik & dashboard. */
export interface PpdbPublicQuota {
  total_quota: number | null;
  registered: number;
  remaining: number | null;
  open_program_count: number;
}

export interface PpdbPublicWave {
  id: number;
  code: string | null;
  name: string;
  priority: number;
  quota: number | null;
  registered: number;
  remaining: number | null;
  registration_start: string | null;
  registration_end: string | null;
  description: string | null;
  is_open: boolean;
}

/** Baris program dari `quotaSummary()`; halaman detail menambah dokumen & biaya. */
export interface PpdbPublicProgram {
  id: number;
  code: string | null;
  name: string;
  description: string | null;
  education_level_id: number | null;
  /** Nama jenjang ikut dikirim karena publik tidak boleh membaca `/master/*`. */
  education_level: { id: number; name: string; jenjang: string | null } | null;
  quota: number | null;
  registered: number;
  remaining: number | null;
  is_full: boolean;
}

/** `PpdbScopeService::documentRequirements()` — tidak ada `program_name`. */
export interface PpdbPublicRequirement {
  id: number;
  code: string;
  name: string;
  description: string | null;
  required: boolean;
  order: number;
  program_id: number | null;
  wave_id: number | null;
  file_types: string;
  allowed_extensions: string[];
  max_size_kb: number;
  min_size_kb: number | null;
}

export interface PpdbPublicFee {
  id: number;
  type: PpdbRegistrationFee['type'];
  name: string;
  amount: string | number;
  required: boolean;
  payment_deadline: string | null;
  program_id: number | null;
  wave_id: number | null;
}

/** `PpdbPublicPeriodResource::summary()` — bentuk datar, tanpa pembungkus. */
export interface PpdbPublicPeriodSummary {
  id: number;
  code: string;
  name: string;
  slug: string;
  public_title: string;
  public_description: string | null;
  status: PpdbPeriod['status'];
  academic_year: { id: number; name: string; start_date: string | null; end_date: string | null } | null;
  banner_url: string | null;
  logo_url: string | null;
  contact: {
    name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
  };
  registration_open: boolean;
  registration_start: string | null;
  registration_end: string | null;
  selection_announcement_date: string | null;
  re_registration_start: string | null;
  re_registration_end: string | null;
  quota: PpdbPublicQuota;
  programs: PpdbPublicProgram[];
  waves: PpdbPublicWave[];
  created_at: string | null;
}

/** Tahap seleksi pada halaman publik (`publicPeriodPayload()`). */
export interface PpdbPublicStage {
  id: number;
  name: string;
  type: string;
  type_label: string;
  program_id: number | null;
  scheduled_at: string | null;
  location: string | null;
  status: string;
  notes: string | null;
}

/**
 * `GET /ppdb/public/{slug}` — `summary()` di-merge dengan
 * `PpdbRegistrationQueryService::publicPeriodPayload()`, jadi semuanya datar.
 */
export interface PpdbPublicPeriodDetail extends PpdbPublicPeriodSummary {
  /** Program + isi per program. */
  programs: (PpdbPublicProgram & {
    documents: PpdbPublicRequirement[];
    fees: PpdbPublicFee[];
    fee_total: number;
  })[];
  documents: PpdbPublicRequirement[];
  fees: PpdbPublicFee[];
  fee_total: number;
  selection_stages: PpdbPublicStage[];
  faq: { question: string; answer: string }[];
  deadlines: PpdbDeadline[];
}

/* ============================ PENDAFTARAN AKUN ============================ */

export interface PpdbRegisterPayload {
  name: string;
  email: string;
  phone: string;
  password: string;
  password_confirmation?: string;
  gender_id?: number | '';
  religion_id?: number | '';
  address?: string;
  occupation_id?: number | '';
  income_range_id?: number | '';
  education_background_id?: number | '';
}

/** `PpdbParentAuthController::register` — status akun ada di akar, bukan di `account`. */
export interface PpdbRegisterResult {
  token: string;
  token_type: string;
  user: { id: number; name: string; email: string; phone: string | null; roles: string[] };
  parent_account: PpdbPortalParentRow;
  children_count: number;
  one_account_for_all_children: boolean;
  account_note: string;
  can_transact: boolean;
  account_status: PpdbAccountStatus;
  account_status_label: string;
  next_steps: string[];
}

/* ============================ PORTAL: SKEMA FORMULIR ============================ */

/** Field form builder yang sudah terselesaikan scope-nya (`PpdbScopeService::fields()`). */
export interface PpdbSchemaField {
  id: number;
  key: string;
  label: string;
  type: PpdbFieldType;
  section: PpdbFieldSection;
  section_label: string;
  placeholder: string | null;
  description: string | null;
  required: boolean;
  order: number;
  options: PpdbFieldOption[];
  validation: string[];
}

export interface PpdbSchemaQuestion {
  id: number;
  code: string;
  question: string;
  description: string | null;
  type: PpdbQuestionType;
  required: boolean;
  order: number;
  program_id: number | null;
  wave_id: number | null;
  options: PpdbQuestionOption[];
}

export interface PpdbSchemaDocument {
  id: number;
  code: string;
  name: string;
  description: string | null;
  required: boolean;
  order: number;
  program_id: number | null;
  wave_id: number | null;
  file_types: string;
  allowed_extensions: string[];
  max_size_kb: number;
  min_size_kb: number | null;
}

export interface PpdbSchemaFee {
  id: number;
  type: PpdbRegistrationFee['type'];
  name: string;
  amount: number;
  required: boolean;
  payment_deadline: string | null;
  program_id: number | null;
  wave_id: number | null;
}

/** Output `PpdbScopeService::schemaFor()`. */
export interface PpdbRegistrationSchema {
  period_id: number;
  program_id: number | null;
  wave_id: number | null;
  sections: Partial<Record<PpdbFieldSection, string>>;
  sections_ordered: { key: PpdbFieldSection; label: string }[];
  fields: PpdbSchemaField[];
  questions: PpdbSchemaQuestion[];
  documents: PpdbSchemaDocument[];
  fees: PpdbSchemaFee[];
  fee_total: number;
}

/** Jawaban yang dikirim portal: kunci = `field_key` / id pertanyaan. */
export type PpdbAnswerValue = string | number | boolean | string[] | null;

export interface PpdbAnswerInput {
  fields?: Record<string, PpdbAnswerValue>;
  questions?: Record<string, PpdbAnswerValue>;
}

/** `PpdbAnswerService::keyed()`. */
export interface PpdbKeyedAnswers {
  fields: Record<string, PpdbAnswerValue>;
  questions: Record<string, PpdbAnswerValue>;
  list: unknown[];
}

/* ============================ PORTAL: DASAR ============================ */

export interface PpdbPortalDashboard {
  parent: PpdbPortalParentRow | null;
  applicant_count: number;
  registration_count: number;
  applications: PpdbPortalApplicationCard[];
  counts: PpdbPortalRegistrationCounts;
  /** Periode acuan dashboard + isi yang ditawarkan (`periodState()`). */
  period: (PpdbPublicPeriodSummary & {
    offerings: {
      programs: { id: number; code: string | null; name: string; quota: number | null; description: string | null; education_level_id: number | null }[];
      waves: { id: number; code: string | null; name: string; quota: number | null; priority: number; registration_start: string | null; registration_end: string | null; description: string | null }[];
    };
  }) | null;
  deadlines: PpdbDeadline[];
  unread_notifications: number;
  empty_state: { title: string; description: string } | null;
}

/** `PpdbRegistrationQueryService::counts()` — pada daftar, `total` ditimpa paginasi. */
export interface PpdbPortalRegistrationCounts {
  total: number;
  needs_action: number;
  by_status: Record<string, number>;
  by_payment_status: Record<string, number>;
  by_document_status: Record<string, number>;
  summary: (PpdbOptionValue<PpdbRegistrationStatus> & { count: number })[];
}

/** Baris pendaftaran datar (`PpdbRegistrationResource::row()`). */
export interface PpdbPortalApplicationCard {
  id: number;
  registration_id: number;
  registration_number: string;
  status: PpdbRegistrationStatus;
  status_label: string;
  payment_status: PpdbPaymentStatus;
  payment_status_label: string;
  document_status: PpdbDocumentStatus;
  document_status_label: string;
  is_editable: boolean;
  revision_note: string | null;
  notes: string | null;
  submitted_at: string | null;
  verified_at: string | null;
  selection_result: PpdbSelectionResult | null;
  announced_at: string | null;
  completed_at: string | null;
  student_id: number | null;
  next_action: PpdbNextAction;
  applicant: {
    id: number;
    full_name: string;
    nickname: string | null;
    gender_id: number | null;
    birth_date: string | null;
  } | null;
  program: PpdbRef | null;
  wave: PpdbRef | null;
  period: (PpdbRef & { slug: string | null }) | null;
  applicant_id: number;
  parent_account_id: number;
  period_id: number;
  program_id: number;
  wave_id: number | null;
  created_at: string | null;
  updated_at: string | null;
  links: Record<string, string>;
}

/** `GET /ppdb/portal/profile` — akun + identitas + pengguna + wali + anak. */
export interface PpdbPortalProfile {
  account: PpdbPortalParentRow;
  parent: PpdbPortalParentRow | null;
  user: {
    id: number;
    name: string;
    username: string | null;
    email: string;
    phone: string | null;
    avatar_path: string | null;
    is_active?: boolean;
    last_login_at?: string | null;
  } | null;
  guardian: {
    id: number;
    full_name: string | null;
    gender_id: number | null;
    religion_id: number | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    occupation_id: number | null;
    income_range_id: number | null;
    education_background_id: number | null;
    occupation?: (Partial<IdName> & Record<string, unknown>) | null;
    income_range?: (Partial<IdName> & Record<string, unknown>) | null;
    education_background?: (Partial<IdName> & Record<string, unknown>) | null;
  } | null;
  children_count: number;
  applicants: PpdbPortalApplicant[];
}

/* ============================ PORTAL: PENDAFTARAN ============================ */

/** Status satu berkas: `not_uploaded` hanya ada pada baris persyaratan. */
export type PpdbDocumentItemStatus = 'not_uploaded' | 'uploaded' | 'under_review' | 'approved' | 'rejected';

/** `PpdbApplicantDocumentResource::row()` — kunci berkas adalah `url`. */
export interface PpdbPortalDocument extends Omit<PpdbApplicantDocument, 'file_url' | 'file_path' | 'status'> {
  status: Exclude<PpdbDocumentItemStatus, 'not_uploaded'>;
  status_label: string;
  file_path: string | null;
  url: string | null;
  file_size_human: string;
}

/** Satu persyaratan dokumen + berkas aktif + riwayat versinya (`annotated()`). */
export interface PpdbDocumentSlot extends PpdbSchemaDocument {
  status: PpdbDocumentItemStatus;
  status_label: string;
  document: PpdbPortalDocument | null;
  versions: PpdbPortalDocument[];
  version_count: number;
  uploaded: boolean;
}

/**
 * Syarat dokumen wajib yang belum punya berkas aktif
 * (`PpdbDocumentService::summarize()` → `summary.missing`).
 */
export interface PpdbDocumentMissingItem {
  id: number;
  name: string;
  required: boolean;
}

export interface PpdbDocumentSummary {
  requirements: PpdbDocumentSlot[];
  unlinked_documents: PpdbPortalDocument[];
  summary: {
    total: number;
    required_total: number;
    uploaded: number;
    approved: number;
    rejected: number;
    under_review: number;
    missing: PpdbDocumentMissingItem[];
    complete: boolean;
  };
  can_upload: boolean;
}

/**
 * Kunci langkah progres — persis `$this->step(...)` di dalam
 * `PpdbRegistrationQueryService::progress()`.
 *
 * `pilihan` (program & gelombang) TIDAK ada di ujung ini: portal tidak memilikinya
 * sebagai pertanyaan terpisah karena program/gelombang terkunci saat pendaftaran
 * dibuat, jadi wizard menyusunnya sendiri di sisi klien.
 */
export type PpdbProgressKey =
  | 'data_calon'
  | 'formulir'
  | 'pertanyaan'
  | 'dokumen'
  | 'review'
  | 'bayar'
  | 'kirim';

interface PpdbProgressStepBase {
  label: string;
  done: boolean;
}

/** `data_calon`: `missing` = LABEL kolom calon yang kosong (`APPLICANT_REQUIRED`). */
export interface PpdbProgressApplicantStep extends PpdbProgressStepBase {
  key: 'data_calon';
  missing: string[];
}

/** `formulir` & `pertanyaan`: hitungan isian wajib yang sudah terjawab. */
export interface PpdbProgressAnswersStep extends PpdbProgressStepBase {
  key: 'formulir' | 'pertanyaan';
  answered: number;
  required_total: number;
  total: number;
}

/** `dokumen`: `missing` = syarat wajib tanpa berkas aktif. */
export interface PpdbProgressDocumentStep extends PpdbProgressStepBase {
  key: 'dokumen';
  uploaded: number;
  required_total: number;
  total: number;
  missing: PpdbDocumentMissingItem[];
}

/** `review` hanya lengkap bila semua langkah sebelumnya `done`. */
export interface PpdbProgressReviewStep extends PpdbProgressStepBase {
  key: 'review';
  hint: string;
}

/** `bayar`: `applicable` = ada biaya pada pilihan ini (`schema.fee_total > 0`). */
export interface PpdbProgressPaymentStep extends PpdbProgressStepBase {
  key: 'bayar';
  applicable: boolean;
  payment_status: PpdbPaymentStatus;
  payment_status_label: string;
  amount_due: number;
  hint: string;
}

/** `kirim`: `done` berarti status pendaftaran sudah melewati `draft`/`need_revision`. */
export interface PpdbProgressSubmitStep extends PpdbProgressStepBase {
  key: 'kirim';
  submitted_at: string | null;
}

export type PpdbRegistrationProgressStep =
  | PpdbProgressApplicantStep
  | PpdbProgressAnswersStep
  | PpdbProgressDocumentStep
  | PpdbProgressReviewStep
  | PpdbProgressPaymentStep
  | PpdbProgressSubmitStep;

/**
 * Satu penghambat field/pertanyaan — `PpdbAnswerService::missing()`.
 * `key` = `field_key` untuk field form builder, dan id pertanyaan sebagai
 * STRING untuk pertanyaan tambahan (sama dengan kunci pada `answers.questions`).
 */
export interface PpdbBlockerItem {
  key: string;
  label: string;
  type: string;
  source: 'field' | 'question';
}

/**
 * Penghambat kirim (`PpdbRegistrationQueryService::blockers()`).
 *
 * Field bertipe `file` sengaja tidak muncul di `fields`: berkas dikelola modul
 * dokumen, sehingga kekosongannya terlihat di `documents`.
 */
export interface PpdbRegistrationBlockers {
  fields: PpdbBlockerItem[];
  questions: PpdbBlockerItem[];
  documents: { id: number; name: string }[];
  /** Label kolom calon yang kosong (`full_name`, `birth_date`, `gender_id`). */
  applicant: string[];
  total: number;
  ready: boolean;
}

/**
 * `GET /ppdb/portal/registrations/{id}`.
 *
 * Baris pendaftaran digabung datar dengan `detail()`, sehingga `applicant` dan
 * `parent` versi lengkap menimpa versi ringkas pada baris. Tidak ada pembungkus
 * `registration`, tidak ada `answer_map`/`missing`/`steps`/`invoice`.
 *
 * Yang HANYA ada pada respons admin (`detail($registration, true)`) dan karena itu
 * tidak boleh dibaca portal: `quota_override`, `histories`, `invoices`,
 * `selection_participants` — `detail()` mengirimnya `null` lalu `array_filter`
 * membuangnya. Tagihan dibaca lewat `GET .../payment` (`PpdbPortalPayment`).
 */
export interface PpdbPortalRegistrationDetail extends Omit<PpdbPortalApplicationCard, 'applicant'> {
  schema: PpdbRegistrationSchema;
  answers: PpdbKeyedAnswers;
  documents: PpdbDocumentSummary;
  progress: PpdbRegistrationProgressStep[];
  blockers: PpdbRegistrationBlockers;
  can_submit: boolean;
  allowed_transitions: PpdbOptionValue<PpdbRegistrationStatus>[];
  editable: boolean;
  document_upload_allowed: boolean;
  validation_rules: Record<string, string[]>;
  applicant: PpdbPortalApplicant | null;
  parent: PpdbPortalParentRow | null;
}

/** Payload PUT /ppdb/portal/registrations/{id} */
export interface PpdbRegistrationUpdatePayload extends PpdbAnswerInput {
  applicant?: Partial<
    Pick<
      PpdbApplicant,
      | 'full_name'
      | 'nickname'
      | 'gender_id'
      | 'birth_place'
      | 'birth_date'
      | 'nik'
      | 'nisn'
      | 'religion_id'
      | 'address'
      | 'province_id'
      | 'city_id'
      | 'district_id'
      | 'village_id'
      | 'postal_code'
      | 'previous_school'
      | 'previous_school_npsn'
    >
  >;
}

/* ============================ PORTAL: PEMBAYARAN & SELEKSI ============================ */

/** `PpdbInvoiceService::manualInstructions()` — kuncinya `payment_method_id`. */
export interface PpdbPaymentInstruction {
  payment_method_id: number;
  name: string;
  code: string | null;
  method: 'manual';
  instructions: string;
}

/** `GET /ppdb/portal/registrations/{id}/payment` — bisa lebih dari satu tagihan. */
export interface PpdbPortalPayment {
  registration: Pick<PpdbRegistration, 'id' | 'registration_number' | 'status' | 'payment_status'> & {
    applicant_name: string | null;
  };
  invoices: PpdbInvoice[];
  outstanding_amount: number;
  currency: string;
  instructions: PpdbPaymentInstruction[];
  online_available: boolean;
}

export interface PpdbPayPayload {
  method: 'manual' | 'midtrans';
  amount: number;
  payment_method_id?: number | '';
  reference?: string;
}

/** Gerbang Midtrans; `null` pada kanal manual. */
export interface PpdbPaymentGateway {
  provider: string;
  redirect_url: string | null;
  snap_token: string | null;
  order_id: string | null;
  gross_amount: string | number;
  client_key: string | null;
}

/** `POST /ppdb/portal/invoices/{id}/pay`. */
export interface PpdbPayResult {
  payment: PpdbPayment;
  invoice: PpdbInvoice;
  gateway: PpdbPaymentGateway | null;
  next_action: 'wait_verification' | 'redirect_to_gateway';
}

/** `PpdbSelectionService::stagesForRegistration()`. */
export interface PpdbPortalSelection {
  stage: {
    id: number;
    name: string;
    type: string;
    status: string;
    scheduled_at: string | null;
    location: string | null;
    order: number;
    notes: string | null;
    weight: number;
    passing_score: number | null;
  };
  participant: {
    id: number;
    status: string;
    score: number | null;
    result: PpdbSelectionResult | null;
    attended_at: string | null;
    notes: string | null;
    scored_by: string | null;
    scored_at: string | null;
  } | null;
}

/** Skor gabungan (`compositeFor()`) — sebagian kunci hanya terisi bila ada nilai. */
export interface PpdbComposite {
  score: number | null;
  weighted_sum: number;
  total_weight: number;
  assessed_weight: number;
  expected_weight: number;
  weight_valid: boolean;
  weight_error: string | null;
  stages_count: number;
  stages: unknown[];
  formula: string;
}

/** `GET /ppdb/portal/registrations/{id}/selection`. */
export interface PpdbPortalSelectionResult {
  registration_id: number;
  registration_number: string;
  status: PpdbRegistrationStatus;
  selection_result: PpdbSelectionResult | null;
  announced_at: string | null;
  applicant_name: string | null;
  program: string | null;
  stages: PpdbPortalSelection[];
  composite: PpdbComposite;
  weights: {
    period_id: number;
    program_id: number | null;
    required_total: number;
    stages: { stage_id: number; name: string; type: string; program_id: number | null; weight: number }[];
    summary: {
      total: number;
      expected: number;
      remaining: number;
      stages: { stage_id: number; name: string; program_id: number | null; weight: number }[];
      stages_count: number;
      valid: boolean;
      error: string | null;
    };
  };
  formula: string;
  re_registration: {
    open: boolean;
    start: string | null;
    end: string | null;
    invoice: PpdbInvoice | null;
  };
}

/* ============================ PORTAL: SANGGAH ============================ */

/** Status sanggahan (`PpdbRegistrationAppeal::STATUSES`). */
export type PpdbAppealStatus = 'pending' | 'accepted' | 'rejected';

/**
 * `PpdbAppealService::eligibility()` — `reasons` berisi seluruh penyebab tombol
 * sanggah tidak aktif, dan `can_appeal` benar hanya bila daftar itu kosong.
 */
export interface PpdbPortalAppealEligibility {
  can_appeal: boolean;
  window_open: boolean;
  appeal_start: string | null;
  appeal_end: string | null;
  days_left: number | null;
  result: PpdbSelectionResult | null;
  pending_id: number | null;
  reasons: string[];
}

/** Batas `PpdbAppealRequest`. */
export const APPEAL_REASON_MIN = 20;
export const APPEAL_REASON_MAX = 2000;
export const APPEAL_ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
export const APPEAL_ATTACHMENT_MAX_KB = 5120;

/* ============================ NOTIFIKASI ============================ */

export type PpdbNotificationType =
  | 'account_created'
  | 'account_blocked'
  | 'registration_submitted'
  | 'document_rejected'
  | 'revision_requested'
  | 'registration_verified'
  | 'registration_cancelled'
  | 'payment_paid'
  | 'payment_failed'
  | 'payment_reminder'
  | 'selection_scheduled'
  | 'selection_result'
  | 're_registration_open'
  | 'appeal_submitted'
  | 'appeal_decided'
  | 'announcement';

/** Isi `data` notifikasi (kolom JSON bawahan `notifications.data`). */
export interface PpdbNotificationPayload {
  type?: PpdbNotificationType;
  title?: string;
  body?: string;
  registration_id?: number;
  registration_number?: string;
  status?: PpdbRegistrationStatus;
  applicant_id?: number;
  parent_account_id?: number;
  document_id?: number;
  requirement_id?: number;
  requirement_name?: string;
  invoice_id?: number;
  reason?: string;
  note?: string;
}

/** `PpdbNotificationResource::row()`. */
export interface PpdbNotification {
  id: string;
  type: PpdbNotificationType;
  type_label: string;
  title: string | null;
  body: string | null;
  data: PpdbNotificationPayload;
  is_read: boolean;
  read_at: string | null;
  created_at: string | null;
  context: {
    registration_id: number | null;
    registration_number: string | null;
    parent_account_id: number | null;
    applicant_id: number | null;
    document_id: number | null;
    requirement_id: number | null;
    requirement_name: string | null;
    invoice_id: number | null;
    reason: string | null;
  };
  links: { api_detail?: string };
}

/** `meta` respons daftar notifikasi — MENIMPA meta paginasi bawaan. */
export interface PpdbNotificationListMeta {
  unread_count: number;
  type_options: Record<string, string>;
}
