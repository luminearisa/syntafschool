/**
 * Kamus status PPDB.
 *
 * Tiga sumbu status (pendaftaran / pembayaran / dokumen) sengaja dipisah:
 * satu pendaftar bisa berstatus "Terverifikasi" sambil "Belum Bayar" dan
 * "Ditolak" pada satu dokumennya. Tidak boleh digabung jadi satu badge.
 */

import { formatDate, formatRupiah } from '@/lib/utils';
import type {
  PpdbAccountStatus,
  PpdbAnnouncementChannel,
  PpdbAnnouncementStatus,
  PpdbAppealStatus,
  PpdbDocumentStatus,
  PpdbFieldType,
  PpdbFieldSection,
  PpdbInvoiceType,
  PpdbParticipantStatus,
  PpdbPaymentStatus,
  PpdbPeriodStatus,
  PpdbQuestionType,
  PpdbRegistrationStatus,
  PpdbSelectionResult,
  PpdbSimpleStatus,
  PpdbStageStatus,
  PpdbStageType,
  Tone,
} from '@/types/ppdb';

/* ============================ SUMBU 1: STATUS PENDAFTARAN ============================ */

export const REGISTRATION_STATUS_LABELS: Record<PpdbRegistrationStatus, string> = {
  draft: 'Draf',
  submitted: 'Terkirim',
  under_review: 'Sedang Ditinjau',
  need_revision: 'Perlu Revisi',
  verified: 'Terverifikasi',
  selection: 'Ikut Seleksi',
  passed: 'Lulus',
  failed: 'Tidak Lulus',
  waitlisted: 'Masih Cadangan',
  re_registration: 'Daftar Ulang',
  completed: 'Selesai',
  cancelled: 'Dibatalkan',
};

const REGISTRATION_STATUS_TONES: Record<PpdbRegistrationStatus, Tone> = {
  draft: 'gray',
  submitted: 'blue',
  under_review: 'amber',
  need_revision: 'amber',
  verified: 'green',
  selection: 'brand',
  passed: 'green',
  failed: 'red',
  waitlisted: 'amber',
  re_registration: 'brand',
  completed: 'green',
  cancelled: 'gray',
};

export const REGISTRATION_STATUS_HINTS: Record<PpdbRegistrationStatus, string> = {
  draft: 'Orang tua belum mengirim formulir.',
  submitted: 'Menunggu pemeriksaan petugas.',
  under_review: 'Sedang diperiksa, termasuk dokumennya.',
  need_revision: 'Perlu diperbaiki orang tua sesuai catatan petugas.',
  verified: 'Berkas lulus pemeriksaan.',
  selection: 'Mengikuti tahap seleksi.',
  passed: 'Dinyatakan lulus seleksi.',
  failed: 'Tidak lulus seleksi.',
  waitlisted: 'Cadangan, menunggu kuota kosong.',
  re_registration: 'Sedang menyelesaikan daftar ulang.',
  completed: 'Selesai daftar ulang — dapat dikonversi menjadi siswa.',
  cancelled: 'Pendaftaran dihentikan.',
};

export function registrationStatusTone(status: PpdbRegistrationStatus): Tone {
  return REGISTRATION_STATUS_TONES[status] ?? 'gray';
}

/**
 * Cerminan lokal dari mesin status di server — dipakai hanya bila respons
 * tidak menyertakan `allowed_transitions`.
 */
export const REGISTRATION_TRANSITIONS: Record<PpdbRegistrationStatus, PpdbRegistrationStatus[]> = {
  draft: ['submitted', 'cancelled'],
  submitted: ['under_review', 'verified', 'need_revision', 'cancelled'],
  under_review: ['verified', 'need_revision', 'cancelled'],
  need_revision: ['under_review', 'submitted', 'cancelled'],
  verified: ['selection', 'need_revision', 'cancelled'],
  selection: ['passed', 'failed', 'waitlisted'],
  passed: ['re_registration', 'completed'],
  waitlisted: ['passed', 'failed', 'cancelled'],
  failed: ['cancelled'],
  re_registration: ['completed', 'cancelled'],
  completed: [],
  cancelled: [],
};

const ALL_REGISTRATION_STATUSES = Object.keys(REGISTRATION_STATUS_LABELS) as PpdbRegistrationStatus[];

/** `options()` backend: daftar `{value,label}` — transisi datang dalam bentuk ini. */
export type TransitionOption = PpdbRegistrationStatus | { value?: string; label?: string };

/** Transisi yang boleh dijalankan petugas pada status saat ini. */
export function allowedTransitions(
  status: PpdbRegistrationStatus,
  fromServer?: TransitionOption[] | null,
): PpdbRegistrationStatus[] {
  const keys = (fromServer ?? [])
    .map((item) => (typeof item === 'string' ? item : String(item?.value ?? '')))
    .filter((value) => ALL_REGISTRATION_STATUSES.includes(value as PpdbRegistrationStatus));

  if (keys.length > 0) return keys as PpdbRegistrationStatus[];

  return REGISTRATION_TRANSITIONS[status] ?? [];
}

/** Status akhir: tidak ada aksi alur lagi selain melihat riwayat. */
export function isFinalStatus(status: PpdbRegistrationStatus): boolean {
  return allowedTransitions(status).length === 0;
}

/* ============================ SUMBU 2: STATUS PEMBAYARAN ============================ */

export const PAYMENT_STATUS_LABELS: Record<PpdbPaymentStatus, string> = {
  unpaid: 'Belum Dibayar',
  pending: 'Menunggu Verifikasi Bayar',
  paid: 'Lunas',
  failed: 'Pembayaran Gagal',
  expired: 'Kedaluwarsa',
  cancelled: 'Dibatalkan',
  refunded: 'Ditagih Ulang/Dikembalikan',
};

const PAYMENT_STATUS_TONES: Record<PpdbPaymentStatus, Tone> = {
  unpaid: 'amber',
  pending: 'blue',
  paid: 'green',
  failed: 'red',
  expired: 'red',
  cancelled: 'gray',
  refunded: 'gray',
};

export function paymentStatusTone(status: PpdbPaymentStatus): Tone {
  return PAYMENT_STATUS_TONES[status] ?? 'gray';
}

/* ============================ SUMBU 3: STATUS DOKUMEN ============================ */

export const DOCUMENT_STATUS_LABELS: Record<PpdbDocumentStatus, string> = {
  not_uploaded: 'Dokumen Belum Lengkap',
  under_review: 'Dokumen Ditinjau',
  approved: 'Dokumen Disetujui',
  rejected: 'Dokumen Ditolak',
};

const DOCUMENT_STATUS_TONES: Record<PpdbDocumentStatus, Tone> = {
  not_uploaded: 'gray',
  under_review: 'amber',
  approved: 'green',
  rejected: 'red',
};

export function documentStatusTone(status: PpdbDocumentStatus): Tone {
  return DOCUMENT_STATUS_TONES[status] ?? 'gray';
}

/** Status satu berkas (`PpdbApplicantDocument.status`) — level rinci dari sumbu 3. */
export const FILE_REVIEW_LABELS: Record<'uploaded' | PpdbDocumentStatus, string> = {
  uploaded: 'Terunggah',
  not_uploaded: 'Belum Diunggah',
  under_review: 'Menunggu Review',
  approved: 'Disetujui',
  rejected: 'Ditolak',
};

export const FILE_REVIEW_TONES: Record<'uploaded' | PpdbDocumentStatus, Tone> = {
  uploaded: 'blue',
  not_uploaded: 'gray',
  under_review: 'amber',
  approved: 'green',
  rejected: 'red',
};

/* ============================ AKUN, TAGIHAN, SELEKSI, PENGUMUMAN ============================ */

export const ACCOUNT_STATUS_LABELS: Record<PpdbAccountStatus, string> = {
  pending_verification: 'Menunggu Verifikasi',
  active: 'Aktif',
  inactive: 'Nonaktif',
  blocked: 'Diblokir',
};

const ACCOUNT_STATUS_TONES: Record<PpdbAccountStatus, Tone> = {
  pending_verification: 'amber',
  active: 'green',
  inactive: 'gray',
  blocked: 'red',
};

export function accountStatusTone(status: PpdbAccountStatus): Tone {
  return ACCOUNT_STATUS_TONES[status] ?? 'gray';
}

export const INVOICE_TYPE_LABELS: Record<PpdbInvoiceType, string> = {
  registration: 'Biaya Pendaftaran',
  re_registration: 'Biaya Daftar Ulang',
  other: 'Biaya Lainnya',
};

export const PAYMENT_KIND_LABELS: Record<'manual' | 'midtrans', string> = {
  manual: 'Manual / Bukti Transfer',
  midtrans: 'Midtrans (Otomatis)',
};

export function paymentKindTone(kind: 'manual' | 'midtrans'): Tone {
  return kind === 'midtrans' ? 'blue' : 'brand';
}

export const STAGE_TYPE_LABELS: Record<PpdbStageType, string> = {
  administrative: 'Seleksi Administrasi',
  academic_test: 'Tes Akademik',
  interview: 'Wawancara',
  tahfidz: 'Tes Tahfiz',
  psychological_test: 'Tes Psikologi',
  health_check: 'Cek Kesehatan',
  other: 'Seleksi Lainnya',
};

export const STAGE_STATUS_LABELS: Record<PpdbStageStatus, string> = {
  draft: 'Draf',
  scheduled: 'Terjadwal',
  ongoing: 'Berlangsung',
  done: 'Selesai',
  cancelled: 'Dibatalkan',
};

const STAGE_STATUS_TONES: Record<PpdbStageStatus, Tone> = {
  draft: 'gray',
  scheduled: 'blue',
  ongoing: 'brand',
  done: 'green',
  cancelled: 'red',
};

export function stageStatusTone(status: PpdbStageStatus): Tone {
  return STAGE_STATUS_TONES[status] ?? 'gray';
}

export const PARTICIPANT_STATUS_LABELS: Record<PpdbParticipantStatus, string> = {
  invited: 'Diundang',
  attended: 'Hadir',
  absent: 'Tidak Hadir',
  scored: 'Sudah Dinilai',
};

const PARTICIPANT_STATUS_TONES: Record<PpdbParticipantStatus, Tone> = {
  invited: 'gray',
  attended: 'blue',
  absent: 'red',
  scored: 'green',
};

export function participantStatusTone(status: PpdbParticipantStatus): Tone {
  return PARTICIPANT_STATUS_TONES[status] ?? 'gray';
}

export const SELECTION_RESULT_LABELS: Record<PpdbSelectionResult, string> = {
  passed: 'Lulus',
  failed: 'Tidak Lulus',
  waitlisted: 'Cadangan',
};

export const ANNOUNCEMENT_CHANNEL_LABELS: Record<PpdbAnnouncementChannel, string> = {
  portal: 'Portal Orang Tua',
  email: 'Email',
  whatsapp: 'WhatsApp',
  all: 'Semua Kanal',
};

export const ANNOUNCEMENT_STATUS_LABELS: Record<PpdbAnnouncementStatus, string> = {
  draft: 'Draf',
  published: 'Terbit',
  archived: 'Diarsipkan',
};

const ANNOUNCEMENT_STATUS_TONES: Record<PpdbAnnouncementStatus, Tone> = {
  draft: 'gray',
  published: 'green',
  archived: 'amber',
};

export function announcementStatusTone(status: PpdbAnnouncementStatus): Tone {
  return ANNOUNCEMENT_STATUS_TONES[status] ?? 'gray';
}

/** Status sanggahan hasil seleksi — kunci yang sama dengan `PpdbRegistrationAppeal::STATUSES`. */
export const APPEAL_STATUS_LABELS: Record<PpdbAppealStatus, string> = {
  pending: 'Menunggu pemeriksaan',
  accepted: 'Diterima',
  rejected: 'Ditolak',
};

export const APPEAL_STATUS_TONES: Record<PpdbAppealStatus, Tone> = {
  pending: 'amber',
  accepted: 'green',
  rejected: 'red',
};

export function appealStatusTone(status: string | null | undefined): Tone {
  return APPEAL_STATUS_TONES[status as PpdbAppealStatus] ?? 'gray';
}

export const APPEAL_STATUS_OPTIONS = toSelectOptions(APPEAL_STATUS_LABELS);

/** Efek keputusan sanggahan — diterima tidak pernah mengubah hasil seleksi. */
export const APPEAL_DECISION_HINTS: Record<'accepted' | 'rejected', string> = {
  accepted: 'Sanggahan diterima: keputusan dibuka kembali untuk panitia, tetapi hasil seleksi belum berubah.',
  rejected: 'Sanggahan ditolak: hasil seleksi tetap berlaku dan orang tua menerima alasannya.',
};

export const PERIOD_STATUS_LABELS: Record<PpdbPeriodStatus, string> = {
  draft: 'Draf',
  scheduled: 'Terjadwal',
  open: 'Dibuka',
  closed: 'Ditutup',
  archived: 'Diarsipkan',
};

export const SIMPLE_STATUS_LABELS: Record<PpdbSimpleStatus, string> = {
  active: 'Aktif',
  inactive: 'Nonaktif',
};

/* ============================ FORMULIR DINAMIS ============================ */

export const FIELD_SECTION_LABELS: Record<PpdbFieldSection, string> = {
  data_calon: 'Data Calon Siswa',
  data_orang_tua: 'Data Orang Tua',
  data_wali: 'Data Wali',
  alamat: 'Alamat',
  sekolah_asal: 'Sekolah Asal',
  kesehatan: 'Kesehatan',
  tambahan: 'Tambahan',
};

export const FIELD_TYPE_LABELS: Record<PpdbFieldType, string> = {
  text: 'Teks',
  textarea: 'Uraian',
  number: 'Angka',
  date: 'Tanggal',
  email: 'Email',
  phone: 'Nomor Telepon',
  select: 'Pilihan tunggal',
  radio: 'Radio',
  checkbox: 'Kotak centang',
  multi_select: 'Pilihan ganda',
  file: 'Berkas',
};

export const QUESTION_TYPE_LABELS: Record<PpdbQuestionType, string> = {
  text: 'Jawaban singkat',
  textarea: 'Uraian',
  number: 'Angka',
  date: 'Tanggal',
  yes_no: 'Ya / Tidak',
  single_choice: 'Pilihan tunggal',
  multiple_choice: 'Pilihan ganda',
  dropdown: 'Dropdown',
  file: 'Berkas',
};

/* ============================ RIWAYAT ============================ */

const HISTORY_ACTION_LABELS: Record<string, string> = {
  created: 'Pendaftaran dibuat',
  updated: 'Berkas diperbarui',
  submitted: 'Dikirim oleh orang tua',
  under_review: 'Mulai ditinjau',
  verified: 'Diverifikasi',
  rejected: 'Ditolak',
  revision_requested: 'Revisi diminta',
  need_revision: 'Dikembalikan untuk revisi',
  transition: 'Status dipindahkan',
  cancelled: 'Dibatalkan',
  result_set: 'Hasil seleksi ditetapkan',
  selection: 'Dimasukkan ke tahap seleksi',
  document_uploaded: 'Dokumen diunggah',
  document_replaced: 'Dokumen diganti',
  document_approved: 'Dokumen disetujui',
  document_rejected: 'Dokumen ditolak',
  invoice_created: 'Tagihan dibuat',
  invoice_reminded: 'Pengingat tagihan dikirim',
  payment_submitted: 'Pembayaran dilaporkan',
  payment_verified: 'Pembayaran disetujui',
  payment_rejected: 'Pembayaran ditolak',
  participant_registered: 'Didaftarkan ke tahap seleksi',
  participant_scored: 'Nilai seleksi dicatat',
  announcement_published: 'Pengumuman diterbitkan',
  re_registration_opened: 'Daftar ulang dibuka',
  re_registration_completed: 'Daftar ulang diselesaikan',
  converted_to_student: 'Dikonversi menjadi siswa',
};

export function historyActionLabel(action: string): string {
  return HISTORY_ACTION_LABELS[action] ?? prettifyKey(action);
}

/* ============================ UTIL ============================ */

/** Ubah `snake_case` / `kebab-case` menjadi label terbaca bila tidak ada di kamus. */
export function prettifyKey(key: string): string {
  return key
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
    .trim();
}

/** Kunci mana pun (known atau tidak) -> label + tone, untuk badge generik. */
export function statusLabelOf<T extends string>(
  map: Record<T, string>,
  key: T | string | null | undefined,
): string {
  if (!key) return '—';
  return (map as Record<string, string>)[key] ?? prettifyKey(key);
}

export function toSelectOptions<T extends string>(map: Record<T, string>): { value: T; label: string }[] {
  return (Object.keys(map) as T[]).map((key) => ({ value: key, label: map[key] }));
}

export const REGISTRATION_STATUS_OPTIONS = toSelectOptions(REGISTRATION_STATUS_LABELS);
export const PAYMENT_STATUS_OPTIONS = toSelectOptions(PAYMENT_STATUS_LABELS);
export const DOCUMENT_STATUS_OPTIONS = toSelectOptions(DOCUMENT_STATUS_LABELS);
export const ACCOUNT_STATUS_OPTIONS = toSelectOptions(ACCOUNT_STATUS_LABELS);
export const INVOICE_TYPE_OPTIONS = toSelectOptions(INVOICE_TYPE_LABELS);
export const STAGE_TYPE_OPTIONS = toSelectOptions(STAGE_TYPE_LABELS);
export const STAGE_STATUS_OPTIONS = toSelectOptions(STAGE_STATUS_LABELS);
export const PARTICIPANT_STATUS_OPTIONS = toSelectOptions(PARTICIPANT_STATUS_LABELS);
export const SELECTION_RESULT_OPTIONS = toSelectOptions(SELECTION_RESULT_LABELS);
export const ANNOUNCEMENT_CHANNEL_OPTIONS = toSelectOptions(ANNOUNCEMENT_CHANNEL_LABELS);
export const ANNOUNCEMENT_STATUS_OPTIONS = toSelectOptions(ANNOUNCEMENT_STATUS_LABELS);

/** `true`/`false`/kosong → label Indonesia untuk kolom boolean. */
export function yesNoLabel(value: unknown): string {
  if (value === true || value === 'true' || value === 1 || value === '1') return 'Ya';
  if (value === false || value === 'false' || value === 0 || value === '0') return 'Tidak';
  return '—';
}

/**
 * URL berkas PPDB.
 *
 * Backend mengirim `url` / `proof_url` / `attachment_url` yang sudah absolut;
 * bila hanya tersedia `_path`, berkas ada di disk `public` milik API sehingga
 * prefix `/api/v1` pada `VITE_API_BASE_URL` harus dilepas — kalau tidak,
 * tautannya menunjuk ke `/api/v1/storage/...` yang tidak pernah ada.
 */
export function fileUrl(absolute?: string | null, path?: string | null): string | null {
  const direct = absolute ?? null;
  if (direct) return direct;
  if (!path) return null;
  if (/^(https?:)?\/\//i.test(path)) return path;
  const base = (import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/+$/, '');
  const origin = base.replace(/\/api(\/.*)?$/, '');
  const file = path.replace(/^\/+/, '');
  return file.startsWith('storage/') ? `${origin}/${file}` : `${origin}/storage/${file}`;
}

export function money(value: string | number | null | undefined): string {
  return formatRupiah(typeof value === 'string' ? Number(value) : value);
}

export function toNumber(value: string | number | null | undefined): number {
  const num = Number(value ?? 0);
  return Number.isFinite(num) ? num : 0;
}

/** `2026-03-18 14:22:00` / ISO -> `18 Mar 2026 14:22`. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';
  const normalized = value.includes('T') ? value : value.replace(' ', 'T');
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return value;
  const time = date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  return `${formatDate(value)} ${time}`;
}

/** Umur berkas/aksi dalam bahasa Indonesia singkat. */
export function relativeAge(value: string | null | undefined): string | null {
  if (!value) return null;
  const time = new Date(value.replace(' ', 'T')).getTime();
  if (Number.isNaN(time)) return null;
  const diff = Date.now() - time;
  const minutes = Math.round(diff / 60000);
  if (minutes < 1) return 'baru saja';
  if (minutes < 60) return `${minutes} menit lalu`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} jam lalu`;
  const days = Math.round(hours / 24);
  if (days < 30) return `${days} hari lalu`;
  const months = Math.round(days / 30);
  return months < 12 ? `${months} bulan lalu` : `${Math.round(months / 12)} tahun lalu`;
}

/** Nama calon untuk baris tabel — fallback ke nomor pendaftaran. */
export function applicantName(
  registration: { applicant?: { full_name?: string | null } | null } | null | undefined,
  fallback = '—',
): string {
  return registration?.applicant?.full_name?.trim() || fallback;
}
