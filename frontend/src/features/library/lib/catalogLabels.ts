import type { SelectOption } from '@/constants/masterData';
import type {
  BibliographyStatus,
  IdentifierType,
  ItemStatus,
  MemberLinkedType,
  MemberStatus,
  RfidStatus,
} from '@/types/libraryCatalog';

type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';

export function toOptions<T extends string>(map: Record<T, string>): SelectOption[] {
  return (Object.keys(map) as T[]).map((key) => ({ value: key, label: map[key] }));
}

/* ============================ KATALOG ============================ */

export const BIBLIOGRAPHY_STATUS_LABELS: Record<BibliographyStatus, string> = {
  draft: 'Draf',
  active: 'Aktif',
  inactive: 'Tidak Aktif',
  archived: 'Diarsipkan',
};

export const BIBLIOGRAPHY_STATUS_HINTS: Record<BibliographyStatus, string> = {
  draft: 'Belum tampil di OPAC.',
  active: 'Tampil di katalog publik (OPAC).',
  inactive: 'Disembunyikan dari OPAC.',
  archived: 'Disimpan sebagai arsip.',
};

export function bibliographyStatusTone(status: BibliographyStatus): Tone {
  switch (status) {
    case 'active':
      return 'green';
    case 'inactive':
      return 'amber';
    case 'archived':
      return 'gray';
    default:
      return 'blue';
  }
}

export const IDENTIFIER_TYPE_LABELS: Record<IdentifierType, string> = {
  isbn10: 'ISBN-10',
  isbn13: 'ISBN-13',
  issn: 'ISSN',
  doi: 'DOI',
  oclc: 'OCLC',
  local: 'ID Lokal',
  other: 'Lainnya',
};

export const FILE_TYPE_LABELS: Record<string, string> = {
  cover: 'Cover',
  thumbnail: 'Thumbnail',
  back: 'Sampul Belakang',
  additional: 'Tambahan',
};

/* ============================ EKSEMPLAR ============================ */

export const ITEM_STATUS_LABELS: Record<ItemStatus, string> = {
  available: 'Tersedia',
  on_loan: 'Dipinjam',
  reserved: 'Dipesan',
  processing: 'Diproses',
  reference_only: 'Hanya Baca di Tempat',
  lost: 'Hilang',
  damaged: 'Rusak',
  withdrawn: 'Ditarik',
  inactive: 'Tidak Aktif',
};

export function itemStatusTone(status: ItemStatus): Tone {
  switch (status) {
    case 'available':
      return 'green';
    // Status transaksi dikendalikan modul Circulation (Fase 4).
    case 'on_loan':
    case 'reserved':
      return 'blue';
    case 'processing':
      return 'gray';
    case 'reference_only':
      return 'brand';
    case 'lost':
    case 'damaged':
      return 'red';
    case 'withdrawn':
    case 'inactive':
      return 'amber';
    default:
      return 'gray';
  }
}

export const RFID_STATUS_LABELS: Record<RfidStatus, string> = {
  unassigned: 'Belum Ditautkan',
  assigned: 'Ditautkan',
  active: 'Aktif',
  lost: 'Hilang',
  disabled: 'Dinonaktifkan',
};

/* ============================ KEANGGOTAAN ============================ */

export const MEMBER_STATUS_LABELS: Record<MemberStatus, string> = {
  pending: 'Menunggu',
  active: 'Aktif',
  expired: 'Kedaluwarsa',
  suspended: 'Ditangguhkan',
  blocked: 'Diblokir',
  inactive: 'Tidak Aktif',
};

export function memberStatusTone(status: MemberStatus): Tone {
  switch (status) {
    case 'active':
      return 'green';
    case 'pending':
      return 'blue';
    case 'expired':
      return 'amber';
    case 'suspended':
      return 'brand';
    case 'blocked':
      return 'red';
    default:
      return 'gray';
  }
}

export const LINKED_TYPE_LABELS: Record<MemberLinkedType, string> = {
  student: 'Siswa',
  employee: 'Pegawai',
  guardian: 'Wali Murid',
  user: 'Pengguna',
  public: 'Umum',
};

/* ============================ OPAC ============================ */

/** Label ramah untuk tipe identifier pada halaman publik. */
export function identifierTypeLabel(type: string): string {
  return IDENTIFIER_TYPE_LABELS[type as IdentifierType] ?? type.toUpperCase();
}

/** Ringkasan ketersediaan, mis. "3 dari 5 eksemplar tersedia". */
export function availabilitySummary(available: number, total: number): string {
  if (total === 0) return 'Belum ada eksemplar';
  return `${available} dari ${total} eksemplar tersedia`;
}