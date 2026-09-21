/**
 * Kosakata status portal PPDB.
 *
 * Tiga sumbu status (pendaftaran / pembayaran / dokumen) sengaja dipisah agar
 * orang tua tidak membaca "pending" sebagai satu kebenaran tunggal.
 */

import type {
  PpdbAccountStatus,
  PpdbDocumentStatus,
  PpdbFeeType,
  PpdbFieldSection,
  PpdbFieldType,
  PpdbInvoiceType,
  PpdbPaymentStatus,
  PpdbPeriodStatus,
  PpdbQuestionType,
  PpdbRegistrationStatus,
  PpdbSelectionResult,
  PpdbStageStatus,
  PpdbStageType,
} from '@/types/ppdb';
import type { PpdbAppealStatus, PpdbNotificationType } from '@/types/ppdbPortal';

export type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';

/** Status dokumen pada portal: tambahan `uploaded` dari slot & versi berkas. */
export type DocumentState = PpdbDocumentStatus | 'uploaded';

export const REGISTRATION_STATUS_LABELS: Record<PpdbRegistrationStatus, string> = {
  draft: 'Draf',
  submitted: 'Terkirim',
  under_review: 'Sedang diperiksa',
  need_revision: 'Perlu revisi',
  verified: 'Terverifikasi',
  selection: 'Tahap seleksi',
  passed: 'Diterima',
  failed: 'Belum diterima',
  waitlisted: 'Daftar tunggu',
  re_registration: 'Daftar ulang',
  completed: 'Selesai',
  cancelled: 'Dibatalkan',
};

export const REGISTRATION_STATUS_TONES: Record<PpdbRegistrationStatus, Tone> = {
  draft: 'gray',
  submitted: 'blue',
  under_review: 'blue',
  need_revision: 'amber',
  verified: 'brand',
  selection: 'brand',
  passed: 'green',
  failed: 'red',
  waitlisted: 'amber',
  re_registration: 'brand',
  completed: 'green',
  cancelled: 'gray',
};

export const PAYMENT_STATUS_LABELS: Record<PpdbPaymentStatus, string> = {
  unpaid: 'Belum dibayar',
  pending: 'Menunggu verifikasi pembayaran',
  paid: 'Lunas',
  failed: 'Gagal',
  expired: 'Kedaluwarsa',
  cancelled: 'Dibatalkan',
  refunded: 'Dikembalikan',
};

export const PAYMENT_STATUS_TONES: Record<PpdbPaymentStatus, Tone> = {
  unpaid: 'red',
  pending: 'amber',
  paid: 'green',
  failed: 'red',
  expired: 'red',
  cancelled: 'gray',
  refunded: 'blue',
};

export const DOCUMENT_STATUS_LABELS: Record<DocumentState, string> = {
  not_uploaded: 'Belum diunggah',
  uploaded: 'Terkirim',
  under_review: 'Ditinjau',
  approved: 'Disetujui',
  rejected: 'Perlu diperbaiki',
};

export const DOCUMENT_STATUS_TONES: Record<DocumentState, Tone> = {
  not_uploaded: 'gray',
  uploaded: 'blue',
  under_review: 'amber',
  approved: 'green',
  rejected: 'red',
};

export const ACCOUNT_STATUS_LABELS: Record<PpdbAccountStatus, string> = {
  pending_verification: 'Menunggu verifikasi',
  active: 'Aktif',
  inactive: 'Nonaktif',
  blocked: 'Diblokir',
};

export const ACCOUNT_STATUS_TONES: Record<PpdbAccountStatus, Tone> = {
  pending_verification: 'amber',
  active: 'green',
  inactive: 'gray',
  blocked: 'red',
};

export const ACCOUNT_STATUS_HINTS: Record<PpdbAccountStatus, string> = {
  pending_verification:
    'Data akun Anda sedang kami periksa. Anda sudah bisa melengkapi profil, tetapi pendaftaran calon siswa baru dibuka setelah akun terverifikasi.',
  active: 'Akun aktif. Anda dapat mendaftarkan anak sebanyak yang Anda butuhkan pada akun ini.',
  inactive:
    'Akun Anda dinonaktifkan sementara. Silakan hubungi panitia PPDB untuk mengaktifkannya kembali.',
  blocked:
    'Akun Anda diblokir. Hubungi panitia PPDB melalui kanal kontak pada halaman informasi periode untuk pemulihan akun.',
};

export const PERIOD_STATUS_LABELS: Record<PpdbPeriodStatus, string> = {
  draft: 'Belum dipublikasikan',
  scheduled: 'Terjadwal',
  open: 'Dibuka',
  closed: 'Ditutup',
  archived: 'Arsip',
};

export const PERIOD_STATUS_TONES: Record<PpdbPeriodStatus, Tone> = {
  draft: 'gray',
  scheduled: 'blue',
  open: 'green',
  closed: 'amber',
  archived: 'gray',
};

export const FEE_TYPE_LABELS: Record<PpdbFeeType, string> = {
  registration: 'Biaya pendaftaran',
  selection: 'Biaya seleksi',
  test: 'Biaya tes',
  psychology: 'Biaya tes psikologi',
  other: 'Biaya lain',
};

export const INVOICE_TYPE_LABELS: Record<PpdbInvoiceType, string> = {
  registration: 'Tagihan pendaftaran',
  re_registration: 'Tagihan daftar ulang',
  other: 'Tagihan lain',
};

export const STAGE_TYPE_LABELS: Record<PpdbStageType, string> = {
  administrative: 'Seleksi administrasi',
  academic_test: 'Tes akademik',
  interview: 'Wawancara',
  tahfidz: 'Tes tahfidz',
  psychological_test: 'Tes psikologi',
  health_check: 'Cek kesehatan',
  other: 'Seleksi lainnya',
};

export const STAGE_STATUS_LABELS: Record<PpdbStageStatus, string> = {
  draft: 'Belum dijadwalkan',
  scheduled: 'Terjadwal',
  ongoing: 'Sedang berjalan',
  done: 'Selesai',
  cancelled: 'Dibatalkan',
};

export const STAGE_STATUS_TONES: Record<PpdbStageStatus, Tone> = {
  draft: 'gray',
  scheduled: 'blue',
  ongoing: 'amber',
  done: 'green',
  cancelled: 'gray',
};

export const SELECTION_RESULT_LABELS: Record<PpdbSelectionResult, string> = {
  passed: 'Diterima',
  failed: 'Belum diterima',
  waitlisted: 'Daftar tunggu',
};

export const SELECTION_RESULT_TONES: Record<PpdbSelectionResult, Tone> = {
  passed: 'green',
  failed: 'red',
  waitlisted: 'amber',
};

export const PARTICIPANT_STATUS_LABELS: Record<'invited' | 'attended' | 'absent' | 'scored', string> = {
  invited: 'Dipanggil',
  attended: 'Hadir',
  absent: 'Tidak hadir',
  scored: 'Sudah dinilai',
};

export const PARTICIPANT_STATUS_TONES: Record<'invited' | 'attended' | 'absent' | 'scored', Tone> = {
  invited: 'blue',
  attended: 'green',
  absent: 'red',
  scored: 'brand',
};

export const PAYMENT_METHOD_LABELS: Record<'manual' | 'midtrans', string> = {
  manual: 'Transfer manual',
  midtrans: 'Pembayaran online',
};

export const SECTION_LABELS: Record<PpdbFieldSection, string> = {
  data_calon: 'Data Calon Siswa',
  data_orang_tua: 'Data Orang Tua',
  data_wali: 'Data Wali',
  alamat: 'Alamat',
  sekolah_asal: 'Sekolah Asal',
  kesehatan: 'Kondisi Kesehatan',
  tambahan: 'Data Tambahan',
};

export const FIELD_TYPE_LABELS: Record<PpdbFieldType, string> = {
  text: 'Teks',
  textarea: 'Uraian panjang',
  number: 'Angka',
  date: 'Tanggal',
  email: 'Email',
  phone: 'Nomor telepon',
  select: 'Pilihan tunggal',
  radio: 'Pilihan tunggal',
  checkbox: 'Pencentang',
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
  dropdown: 'Daftar pilihan',
  file: 'Berkas',
};

export const NOTIFICATION_TYPE_LABELS: Record<PpdbNotificationType, string> = {
  account_created: 'Akun dibuat',
  account_blocked: 'Akun dinonaktifkan',
  registration_submitted: 'Pendaftaran terkirim',
  document_rejected: 'Dokumen perlu diperbaiki',
  revision_requested: 'Revisi diminta',
  registration_verified: 'Pendaftaran terverifikasi',
  registration_cancelled: 'Pendaftaran dibatalkan',
  payment_paid: 'Pembayaran terverifikasi',
  payment_failed: 'Pembayaran belum berhasil',
  payment_reminder: 'Pengingat pembayaran',
  selection_scheduled: 'Jadwal seleksi',
  selection_result: 'Hasil seleksi',
  re_registration_open: 'Daftar ulang dibuka',
  appeal_submitted: 'Sanggahan terkirim',
  appeal_decided: 'Sanggahan dijawab',
  announcement: 'Pengumuman',
};

export const NOTIFICATION_TYPE_TONES: Record<PpdbNotificationType, Tone> = {
  account_created: 'blue',
  account_blocked: 'red',
  registration_submitted: 'blue',
  document_rejected: 'red',
  revision_requested: 'amber',
  registration_verified: 'green',
  registration_cancelled: 'gray',
  payment_paid: 'green',
  payment_failed: 'red',
  payment_reminder: 'amber',
  selection_scheduled: 'brand',
  selection_result: 'brand',
  re_registration_open: 'brand',
  appeal_submitted: 'blue',
  appeal_decided: 'brand',
  announcement: 'blue',
};

/** Status sanggahan; `accepted` berarti berkas dibuka kembali, bukan lulus. */
export const APPEAL_STATUS_LABELS: Record<PpdbAppealStatus, string> = {
  pending: 'Menunggu pemeriksaan',
  accepted: 'Diterima — keputusan dibuka kembali',
  rejected: 'Ditolak',
};

export const APPEAL_STATUS_TONES: Record<PpdbAppealStatus, Tone> = {
  pending: 'amber',
  accepted: 'brand',
  rejected: 'gray',
};

export function appealStatusLabel(status: string | null | undefined): string {
  return status ? APPEAL_STATUS_LABELS[status as PpdbAppealStatus] ?? status : 'Menunggu pemeriksaan';
}

export function appealStatusTone(status: string | null | undefined): Tone {
  return status ? APPEAL_STATUS_TONES[status as PpdbAppealStatus] ?? 'gray' : 'gray';
}

/** Ubah peta label enum menjadi opsi dropdown. */
export function toOptions<T extends string>(map: Record<T, string>): { value: string; label: string }[] {
  return (Object.keys(map) as T[]).map((key) => ({ value: key, label: map[key] }));
}

export function registrationStatusTone(status: PpdbRegistrationStatus | null | undefined): Tone {
  return status ? REGISTRATION_STATUS_TONES[status] ?? 'gray' : 'gray';
}

export function paymentStatusTone(status: PpdbPaymentStatus | null | undefined): Tone {
  return status ? PAYMENT_STATUS_TONES[status] ?? 'gray' : 'gray';
}

export function documentStatusTone(status: DocumentState | null | undefined): Tone {
  return status ? DOCUMENT_STATUS_TONES[status] ?? 'gray' : 'gray';
}

export function documentStatusLabel(status: DocumentState | null | undefined): string {
  return status ? DOCUMENT_STATUS_LABELS[status] ?? status : 'Belum diunggah';
}

export function registrationStatusLabel(status: PpdbRegistrationStatus | null | undefined): string {
  return status ? REGISTRATION_STATUS_LABELS[status] ?? status : '-';
}

export function paymentStatusLabel(status: PpdbPaymentStatus | null | undefined): string {
  return status ? PAYMENT_STATUS_LABELS[status] ?? status : '-';
}
