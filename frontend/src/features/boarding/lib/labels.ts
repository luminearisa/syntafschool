import type { SelectOption } from '@/constants/masterData';

export function toOptions(map: Record<string, string>): SelectOption[] {
  return Object.entries(map).map(([value, label]) => ({ value, label }));
}

/** label dari peta, fallback ke nilai mentah. */
export function labelOf(map: Record<string, string>, value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  return map[value] ?? value;
}

export const GENDER_LABELS: Record<string, string> = {
  male: 'Laki-laki',
  female: 'Perempuan',
  mixed: 'Campuran',
};

export const ROOM_TYPE_LABELS: Record<string, string> = {
  regular: 'Kamar Biasa',
  isolation: 'Ruang Isolasi',
};

export const STRUCTURE_STATUS_LABELS: Record<string, string> = {
  active: 'Aktif',
  inactive: 'Tidak Aktif',
  maintenance: 'Pemeliharaan',
};

export const BED_STATUS_LABELS: Record<string, string> = {
  available: 'Tersedia',
  occupied: 'Terisi',
  maintenance: 'Pemeliharaan',
  inactive: 'Tidak Aktif',
};

export const PLACEMENT_STATUS_LABELS: Record<string, string> = {
  active: 'Aktif',
  inactive: 'Selesai',
};

export const END_REASON_LABELS: Record<string, string> = {
  transfer: 'Pindah Kamar',
  isolation: 'Isolasi Kesehatan',
};

export const MUSYRIF_POSITION_LABELS: Record<string, string> = {
  kepala_asrama: 'Kepala Asrama',
  musyrif: 'Musyrif',
  musyrifah: 'Musyrifah',
  wali_kamar: 'Wali Kamar',
  koordinator: 'Koordinator',
};

export const STUDENT_STATUS_LABELS: Record<string, string> = {
  in_dorm: 'Di Dalam Asrama',
  out_with_permission: 'Keluar (Ada Izin)',
  home: 'Pulang',
  sick: 'Sakit',
  isolation: 'Isolasi',
  absent: 'Tidak di Asrama',
  unknown: 'Tidak Diketahui',
};

export const STUDENT_STATUS_TONES: Record<
  string,
  'green' | 'blue' | 'amber' | 'red' | 'gray' | 'brand'
> = {
  in_dorm: 'green',
  out_with_permission: 'blue',
  home: 'brand',
  sick: 'amber',
  isolation: 'red',
  absent: 'gray',
  unknown: 'gray',
};

export const PERMISSION_STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  submitted: 'Menunggu Persetujuan',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  out: 'Keluar',
  returned: 'Sudah Kembali',
  expired: 'Kedaluwarsa',
  cancelled: 'Dibatalkan',
};

export const PERMISSION_TYPE_LABELS: Record<string, string> = {
  sick: 'Sakit (keluar periksa)',
  family: 'Kunjungan keluarga',
  school_activity: 'Kegiatan sekolah',
  out: 'Keluar biasa',
  special: 'Istimewa',
};

export const HOMECOMING_STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  submitted: 'Menunggu Persetujuan',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  out: 'Pulang',
  returned: 'Sudah Kembali',
  expired: 'Kedaluwarsa',
  cancelled: 'Dibatalkan',
};

export const HOMECOMING_TYPE_LABELS: Record<string, string> = {
  weekend: 'Akhir pekan',
  semester_break: 'Libur semester',
  lebaran: 'Libur Lebaran',
  special: 'Kepulangan khusus',
};

export const VISITOR_STATUS_LABELS: Record<string, string> = {
  waiting: 'Menunggu',
  visiting: 'Sedang Berkunjung',
  finished: 'Selesai',
  cancelled: 'Dibatalkan',
};

export const HEALTH_STATUS_LABELS: Record<string, string> = {
  reported: 'Tercatat',
  being_treated: 'Dalam Perawatan',
  recovered: 'Pulih',
  referred: 'Dirujuk',
};

export const DISCIPLINARY_ACTION_LABELS: Record<string, string> = {
  warning: 'Teguran',
  coaching: 'Pembinaan',
  parent_notification: 'Pemberitahuan Orang Tua',
  written_warning: 'Surat Peringatan',
  community_service: 'Kerja Sosial',
  other: 'Lainnya',
};

export const ACTION_STATUS_LABELS: Record<string, string> = {
  pending: 'Menunggu',
  completed: 'Selesai',
  cancelled: 'Dibatalkan',
};

export const VIOLATION_STATUS_LABELS: Record<string, string> = {
  open: 'Belum Ditindaklanjuti',
  actioned: 'Sudah Ditindaklanjuti',
  closed: 'Ditutup',
};

export const VIOLATION_SEVERITY_LABELS: Record<string, string> = {
  minor: 'Ringan',
  moderate: 'Sedang',
  severe: 'Berat',
};

export const VIOLATION_CATEGORY_LABELS: Record<string, string> = {
  ibadah: 'Ibadah',
  academic: 'Akademik',
  tahfidz: 'Tahfidz',
  cleanliness: 'Kebersihan',
  discipline: 'Kedisiplinan',
  social: 'Sosial',
  health: 'Kesehatan',
  other: 'Lainnya',
};

export const ACTIVITY_CATEGORY_LABELS: Record<string, string> = {
  ibadah: 'Ibadah',
  academic: 'Akademik',
  tahfidz: 'Tahfidz',
  meal: 'Makan',
  cleanliness: 'Kebersihan',
  discipline: 'Kedisiplinan',
  sports: 'Olahraga',
  health: 'Kesehatan',
  dormitory: 'Asrama',
  other: 'Lainnya',
};

export const ATTENDANCE_CATEGORY_LABELS: Record<string, string> = {
  present: 'Hadir',
  excused: 'Ada Keterangan',
  unexcused: 'Tanpa Keterangan',
  late: 'Terlambat',
  neutral: 'Netral',
};

export const ACHIEVEMENT_CATEGORY_LABELS: Record<string, string> = {
  tahfidz: 'Tahfidz',
  academic: 'Akademik',
  sports: 'Olahraga',
  language: 'Bahasa',
  leadership: 'Kepemimpinan',
  cleanliness: 'Kebersihan',
  other: 'Lainnya',
};

export const ACHIEVEMENT_LEVEL_LABELS: Record<string, string> = {
  sekolah: 'Tingkat Sekolah',
  kota: 'Tingkat Kota/Kabupaten',
  provinsi: 'Tingkat Provinsi',
  nasional: 'Tingkat Nasional',
};

export const DAY_LABELS: Record<number, string> = {
  1: 'Senin',
  2: 'Selasa',
  3: 'Rabu',
  4: 'Kamis',
  5: 'Jumat',
  6: 'Sabtu',
  7: 'Minggu',
};
