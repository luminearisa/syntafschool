import type { SelectOption } from '@/constants/masterData';
import type {
  LibraryClosureStatus,
  LibraryClosureType,
  LibraryLocationStatus,
  LibraryLocationType,
  LibraryNumberingResetPeriod,
  LibraryOperatingStatus,
  LibrarySettingType,
  LibrarySetupStatus,
  LibraryStatus,
} from '@/types/library';

type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';

/** Ubah peta label menjadi opsi dropdown. */
export function toOptions<T extends string>(map: Record<T, string>): SelectOption[] {
  return (Object.keys(map) as T[]).map((key) => ({ value: key, label: map[key] }));
}

/* ============================ PERPUSTAKAAN ============================ */

export const LIBRARY_STATUS_LABELS: Record<LibraryStatus, string> = {
  draft: 'Draf',
  active: 'Aktif',
  inactive: 'Tidak Aktif',
  archived: 'Diarsipkan',
};

export function libraryStatusTone(status: LibraryStatus): Tone {
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

/** Penjelasan singkat arti tiap status (dipakai pada dialog transisi). */
export const LIBRARY_STATUS_HINTS: Record<LibraryStatus, string> = {
  draft: 'Belum siap digunakan.',
  active: 'Perpustakaan dapat digunakan.',
  inactive: 'Tidak dapat digunakan untuk transaksi baru.',
  archived: 'Data historis tetap tersedia. Bersifat final.',
};

/* ============================ LOKASI ============================ */

export const LOCATION_TYPE_LABELS: Record<LibraryLocationType, string> = {
  building: 'Gedung',
  floor: 'Lantai',
  room: 'Ruang',
  reading_area: 'Ruang Baca',
  storage: 'Penyimpanan',
  service_desk: 'Meja Layanan',
  other: 'Lainnya',
};

export const LOCATION_STATUS_LABELS: Record<LibraryLocationStatus, string> = {
  active: 'Aktif',
  inactive: 'Tidak Aktif',
};

/* ============================ JAM OPERASIONAL ============================ */

export const DAY_LABELS: Record<number, string> = {
  0: 'Ahad',
  1: 'Senin',
  2: 'Selasa',
  3: 'Rabu',
  4: 'Kamis',
  5: 'Jumat',
  6: 'Sabtu',
};

/* ============================ PENUTUPAN ============================ */

export const CLOSURE_TYPE_LABELS: Record<LibraryClosureType, string> = {
  national_holiday: 'Libur Nasional',
  school_holiday: 'Libur Sekolah',
  maintenance: 'Pemeliharaan',
  school_event: 'Acara Sekolah',
  other: 'Lainnya',
};

export const CLOSURE_STATUS_LABELS: Record<LibraryClosureStatus, string> = {
  active: 'Aktif',
  cancelled: 'Dibatalkan',
};

export function closureStatusTone(status: LibraryClosureStatus): Tone {
  return status === 'active' ? 'amber' : 'gray';
}

/* ============================ PENOMORAN ============================ */

export const NUMBERING_RESET_LABELS: Record<LibraryNumberingResetPeriod, string> = {
  never: 'Tidak pernah',
  year: 'Tahunan',
  month: 'Bulanan',
};

/** Token format yang didukung backend. */
export const NUMBERING_TOKENS = ['{SEQ}', '{YYYY}', '{YY}', '{MM}', '{LIBRARY_CODE}'];

/* ============================ KONFIGURASI ============================ */

export const SETTING_GROUP_LABELS: Record<string, string> = {
  general: 'Umum',
  loan: 'Peminjaman',
  fine: 'Denda',
  member: 'Keanggotaan',
  catalog: 'Katalog',
  reservation: 'Reservasi',
};

export const SETTING_TYPE_LABELS: Record<LibrarySettingType, string> = {
  string: 'Teks',
  integer: 'Bilangan bulat',
  boolean: 'Ya / Tidak',
  decimal: 'Desimal',
  json: 'JSON',
};

/* ============================ DASHBOARD & SETUP ============================ */

export const OPERATING_STATUS_LABELS: Record<LibraryOperatingStatus, string> = {
  open: 'Buka',
  closed: 'Tutup',
  unset: 'Belum diatur',
};

export function operatingStatusTone(status: LibraryOperatingStatus): Tone {
  switch (status) {
    case 'open':
      return 'green';
    case 'closed':
      return 'red';
    default:
      return 'gray';
  }
}

export const SETUP_STATUS_LABELS: Record<LibrarySetupStatus, string> = {
  complete: 'Lengkap',
  incomplete: 'Belum Lengkap',
  warning: 'Perlu Perhatian',
};

export function setupStatusTone(status: LibrarySetupStatus): Tone {
  switch (status) {
    case 'complete':
      return 'green';
    case 'warning':
      return 'amber';
    default:
      return 'red';
  }
}

export function checkStatusTone(status: string): Tone {
  switch (status) {
    case 'complete':
      return 'green';
    case 'warning':
      return 'amber';
    default:
      return 'red';
  }
}

/** Tampilkan "—" untuk nilai yang belum punya sumber data. */
export function orDash(value: number | string | null | undefined): string {
  return value === null || value === undefined || value === '' ? '—' : String(value);
}

export { type Tone as LibraryTone };