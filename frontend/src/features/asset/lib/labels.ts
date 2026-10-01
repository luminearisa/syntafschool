import type { SelectOption } from '../types';

type Tone = 'green' | 'amber' | 'red' | 'blue' | 'gray' | 'brand';

/**
 * Terjemahan nilai enum → label + warna, satu tempat untuk seluruh modul.
 *
 * Isinya DISELARASKAN dengan enum backend (`App\Modules\Asset\Domain\Enums`)
 * agar tidak ada nilai yang tampil sebagai bahasa Inggris mentah. Nilai yang
 * belum dikenal tidak pernah ditampilkan apa adanya: `labelOf()` mempercantiknya
 * supaya layar tetap terbaca saat backend menambah status lebih dulu daripada
 * frontend menyusul — dan itu memang urutan yang benar.
 */
const prettify = (value?: string | null): string => {
  if (!value) return '-';
  const teks = value.toLowerCase().replace(/_/g, ' ');
  return teks.charAt(0).toUpperCase() + teks.slice(1);
};

const make = (map: Record<string, [string, Tone]>) => ({
  label: (value?: string | null): string => (value ? map[value]?.[0] ?? prettify(value) : '-'),
  tone: (value?: string | null): Tone => (value ? map[value]?.[1] ?? 'gray' : 'gray'),
  options: (): SelectOption[] => Object.entries(map).map(([value, [label]]) => ({ value, label })),
  values: (): string[] => Object.keys(map),
});

export const assetStatus = make({
  PLANNED: ['Direncanakan', 'blue'],
  ORDERED: ['Dipesan', 'blue'],
  RECEIVED: ['Diterima', 'blue'],
  REGISTERED: ['Terdaftar', 'blue'],
  TAGGED: ['Berlabel', 'blue'],
  ACTIVE: ['Aktif', 'green'],
  ASSIGNED: ['Ditugaskan', 'blue'],
  IN_STORAGE: ['Di gudang', 'gray'],
  ON_LOAN: ['Dipinjam', 'blue'],
  IN_MAINTENANCE: ['Dalam perbaikan', 'amber'],
  DAMAGED: ['Rusak', 'red'],
  LOST: ['Hilang', 'red'],
  UNDER_INVESTIGATION: ['Dalam penyelidikan', 'amber'],
  DISPOSED: ['Dihapusbukukan', 'gray'],
  RETIRED: ['Pensiun', 'gray'],
});

export const assetCondition = make({
  NEW: ['Baru', 'green'],
  GOOD: ['Baik', 'green'],
  FAIR: ['Cukup', 'blue'],
  POOR: ['Rusak ringan', 'amber'],
  DAMAGED: ['Rusak', 'red'],
  CRITICAL: ['Kritis', 'red'],
  UNUSABLE: ['Tidak layak pakai', 'red'],
});

export const criticality = make({
  LOW: ['Rendah', 'gray'],
  NORMAL: ['Normal', 'blue'],
  HIGH: ['Tinggi', 'amber'],
  CRITICAL: ['Kritis', 'red'],
});

export const acquisitionSource = make({
  PURCHASE: ['Pembelian', 'blue'],
  DONATION: ['Sumbangan', 'green'],
  TRANSFER: ['Transfer masuk', 'blue'],
  CONSTRUCTION: ['Dibangun sendiri', 'blue'],
  LEASE: ['Sewa', 'amber'],
  FOUND: ['Temuan', 'amber'],
  OTHER: ['Lain-lain', 'gray'],
});

export const capitalizationDecision = make({
  ASSET: ['Aset', 'green'],
  INVENTORY: ['Persediaan', 'blue'],
  EXPENSE: ['Beban', 'amber'],
});

export const tagStatus = make({
  PENDING_TAGGING: ['Belum dilabeli', 'gray'],
  TAGGED: ['Berlabel', 'blue'],
  TAG_DAMAGED: ['Label rusak', 'amber'],
  TAG_REPLACEMENT: ['Perlu label baru', 'blue'],
});

export const loanStatus = make({
  REQUESTED: ['Diajukan', 'amber'],
  APPROVED: ['Disetujui', 'blue'],
  RESERVED: ['Slot dipesan', 'blue'],
  HANDED_OVER: ['Diserahkan', 'blue'],
  ACTIVE: ['Aktif', 'green'],
  OVERDUE: ['Terlambat', 'red'],
  RETURNED: ['Dikembalikan', 'green'],
  DAMAGED: ['Rusak', 'red'],
  LOST: ['Hilang', 'red'],
  CANCELLED: ['Dibatalkan', 'gray'],
  REJECTED: ['Ditolak', 'red'],
});

export const transferStatus = make({
  REQUESTED: ['Diajukan', 'amber'],
  APPROVED: ['Disetujui', 'blue'],
  HANDED_OVER: ['Diserahkan', 'blue'],
  COMPLETED: ['Selesai', 'green'],
  REJECTED: ['Ditolak', 'red'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const transferType = make({
  ROOM_TRANSFER: ['Pindah ruang', 'blue'],
  DEPARTMENT_TRANSFER: ['Pindah unit', 'blue'],
  CUSTODIAN_TRANSFER: ['Ganti pemegang', 'blue'],
  CAMPUS_TRANSFER: ['Pindah kampus', 'blue'],
  TEMPORARY_TRANSFER: ['Pindah sementara', 'amber'],
  PERMANENT_TRANSFER: ['Pindah tetap', 'blue'],
});

export const maintenanceRequestStatus = make({
  NEW: ['Baru', 'green'],
  TRIAGED: ['Ditriage', 'blue'],
  ASSIGNED: ['Ditugaskan', 'blue'],
  IN_PROGRESS: ['Dikerjakan', 'blue'],
  WAITING_PARTS: ['Menunggu suku cadang', 'amber'],
  WAITING_VENDOR: ['Menunggu pihak ketiga', 'amber'],
  COMPLETED: ['Selesai', 'green'],
  VERIFIED: ['Terverifikasi', 'green'],
  CLOSED: ['Ditutup', 'gray'],
  REJECTED: ['Ditolak', 'red'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const workOrderStatus = make({
  DRAFT: ['Draf', 'gray'],
  OPEN: ['Terbuka', 'blue'],
  ASSIGNED: ['Ditugaskan', 'blue'],
  IN_PROGRESS: ['Dikerjakan', 'blue'],
  ON_HOLD: ['Ditahan', 'amber'],
  COMPLETED: ['Selesai', 'green'],
  VERIFIED: ['Terverifikasi', 'green'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const workOrderType = make({
  PREVENTIVE: ['Preventif', 'blue'],
  CORRECTIVE: ['Perbaikan', 'amber'],
  EMERGENCY: ['Darurat', 'red'],
  INSPECTION_FOLLOW_UP: ['Tindak lanjut inspeksi', 'blue'],
  IMPROVEMENT: ['Peningkatan', 'green'],
});

export const priority = make({
  LOW: ['Rendah', 'gray'],
  NORMAL: ['Normal', 'blue'],
  HIGH: ['Tinggi', 'amber'],
  URGENT: ['Mendesak', 'red'],
  CRITICAL: ['Kritis', 'red'],
});

export const frequency = make({
  DAYS_30: ['Setiap 30 hari', 'blue'],
  DAYS_90: ['Setiap 90 hari', 'blue'],
  DAYS_180: ['Setiap 180 hari', 'blue'],
  DAYS_365: ['Setiap 365 hari', 'blue'],
  USAGE_BASED: ['Berdasarkan pemakaian', 'amber'],
  METER_BASED: ['Berdasarkan meter', 'amber'],
  CUSTOM: ['Khusus', 'gray'],
});

export const inspectionStatus = make({
  SCHEDULED: ['Terjadwal', 'blue'],
  IN_PROGRESS: ['Dikerjakan', 'blue'],
  PASSED: ['Lulus', 'green'],
  PASSED_WITH_NOTES: ['Lulus dengan catatan', 'amber'],
  FAILED: ['Gagal', 'red'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const inspectionResult = make({
  PASS: ['Lulus', 'green'],
  FAIL: ['Gagal', 'red'],
  NOT_APPLICABLE: ['Tidak berlaku', 'gray'],
});

export const inspectionType = make({
  INITIAL: ['Initial', 'gray'],
  ROUTINE: ['Routine', 'gray'],
  SAFETY: ['Safety', 'gray'],
  PREVENTIVE: ['Preventif', 'blue'],
  POST_MAINTENANCE: ['Post maintenance', 'gray'],
  PRE_DISPOSAL: ['Pre disposal', 'gray'],
  STOCK_OPNAME: ['Stock opname', 'gray'],
  RETURN: ['Return', 'gray'],
});

export const damageSeverity = make({
  MINOR: ['Ringan', 'blue'],
  MODERATE: ['Sedang', 'amber'],
  MAJOR: ['Berat', 'red'],
  TOTAL: ['Rusak total', 'red'],
});

export const damageStatus = make({
  REPORTED: ['Dilaporkan', 'amber'],
  VERIFIED: ['Terverifikasi', 'green'],
  INSPECTED: ['Diperiksa', 'blue'],
  MAINTENANCE_CREATED: ['Pemeliharaan dibuat', 'blue'],
  RESOLVED: ['Tuntas', 'green'],
  REJECTED: ['Ditolak', 'red'],
});

export const facilityStatus = make({
  PLANNED: ['Direncanakan', 'blue'],
  ACTIVE: ['Aktif', 'green'],
  LIMITED: ['Terbatas', 'amber'],
  MAINTENANCE: ['Maintenance', 'gray'],
  CLOSED: ['Ditutup', 'gray'],
  RETIRED: ['Pensiun', 'gray'],
});

export const bookingStatus = make({
  REQUESTED: ['Diajukan', 'amber'],
  APPROVED: ['Disetujui', 'blue'],
  RESERVED: ['Slot dipesan', 'blue'],
  IN_USE: ['Sedang dipakai', 'green'],
  COMPLETED: ['Selesai', 'green'],
  CANCELLED: ['Dibatalkan', 'gray'],
  REJECTED: ['Ditolak', 'red'],
  NO_SHOW: ['Tidak hadir', 'red'],
});

export const opnameStatus = make({
  PLANNED: ['Direncanakan', 'blue'],
  IN_PROGRESS: ['Dikerjakan', 'blue'],
  COUNTING: ['Penghitungan', 'amber'],
  VERIFICATION: ['Verifikasi', 'blue'],
  APPROVAL: ['Persetujuan', 'blue'],
  COMPLETED: ['Selesai', 'green'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const scanResult = make({
  FOUND: ['Temuan', 'amber'],
  MISSING: ['Tidak ditemukan', 'red'],
  EXTRA: ['Berlebih', 'amber'],
  WRONG_LOCATION: ['Salah lokasi', 'amber'],
  WRONG_CUSTODIAN: ['Salah pemegang', 'amber'],
  DAMAGED: ['Rusak', 'red'],
  UNIDENTIFIED: ['Tidak dikenal', 'amber'],
});

export const varianceDecision = make({
  PENDING: ['Menunggu keputusan', 'amber'],
  ACCEPTED: ['Diterima', 'green'],
  INVESTIGATED: ['Diselidiki', 'blue'],
  ADJUSTED: ['Disesuaikan', 'green'],
  REJECTED: ['Ditolak', 'red'],
});

export const disposalStatus = make({
  DRAFT: ['Draf', 'gray'],
  SUBMITTED: ['Diajukan', 'amber'],
  INSPECTED: ['Diperiksa', 'blue'],
  VALUED: ['Dinilai', 'blue'],
  APPROVED: ['Disetujui', 'blue'],
  EXECUTED: ['Dilaksanakan', 'green'],
  REJECTED: ['Ditolak', 'red'],
  CANCELLED: ['Dibatalkan', 'gray'],
  RETIRED: ['Pensiun', 'gray'],
});

export const disposalReason = make({
  DAMAGED: ['Rusak', 'red'],
  OBSOLETE: ['Obsolete', 'gray'],
  UNECONOMICAL_REPAIR: ['Perbaikan tidak ekonomis', 'amber'],
  LOST: ['Hilang', 'red'],
  SOLD: ['Dijual', 'blue'],
  DONATED: ['Dihibahkan', 'blue'],
  SCRAPPED: ['Dibesikan', 'gray'],
  TRANSFERRED: ['Dipindah keluar', 'blue'],
  OTHER: ['Lain-lain', 'gray'],
});

export const disposalMethod = make({
  SALE: ['Dijual', 'blue'],
  DONATION: ['Sumbangan', 'green'],
  SCRAP: ['Dibesikan', 'gray'],
  WRITE_OFF: ['Dihapusbukukan', 'gray'],
  TRANSFER: ['Transfer masuk', 'blue'],
  RETURN_TO_VENDOR: ['Dikembalikan ke pemasok', 'amber'],
  OTHER: ['Lain-lain', 'gray'],
});

export const replacementRecommendation = make({
  MONITOR: ['Pantau', 'blue'],
  MAINTAIN: ['Rawat', 'green'],
  REPAIR: ['Perbaiki', 'amber'],
  REPLACE_REVIEW: ['Kaji penggantian', 'amber'],
});

export const locationType = make({
  CAMPUS: ['Kampus', 'blue'],
  BUILDING: ['Gedung', 'blue'],
  FLOOR: ['Lantai', 'blue'],
  ROOM: ['Ruang', 'blue'],
  CLASSROOM: ['Ruang kelas', 'blue'],
  LAB: ['Laboratorium', 'blue'],
  OFFICE: ['Kantor', 'blue'],
  LIBRARY: ['Perpustakaan', 'blue'],
  WAREHOUSE: ['Gudang', 'blue'],
  DORMITORY: ['Asrama', 'blue'],
  HALL: ['Aula', 'blue'],
  SPORT_FACILITY: ['Sarana olahraga', 'blue'],
  KITCHEN: ['Dapur', 'blue'],
  CLINIC: ['Klinik', 'blue'],
  MOSQUE: ['Masjid', 'blue'],
  WORKSHOP: ['Bengkel', 'blue'],
  PARKING: ['Parkir', 'blue'],
  OUTDOOR_AREA: ['Area luar', 'blue'],
  OTHER: ['Lain-lain', 'gray'],
});

export const custodianType = make({
  EMPLOYEE: ['Pegawai', 'blue'],
  TEACHER: ['Guru', 'blue'],
  STAFF: ['Tenaga kependidikan', 'blue'],
  DEPARTMENT: ['Unit kerja', 'blue'],
  UNIT: ['Unit', 'blue'],
  ROOM: ['Ruang', 'blue'],
  STUDENT: ['Siswa', 'blue'],
});

export const valuationType = make({
  ACQUISITION: ['Perolehan', 'blue'],
  BOOK_VALUE: ['Nilai buku', 'blue'],
  RESIDUAL: ['Nilai residu', 'blue'],
  FAIR_VALUE: ['Nilai wajar', 'blue'],
  REVALUATION: ['Penilaian ulang', 'blue'],
  IMPAIRMENT: ['Penurunan nilai', 'amber'],
});

/** Ringkasan ketersediaan untuk papan fasilitas. */
export function availabilityLabel(available?: boolean | null): { label: string; tone: Tone } {
  if (available === true) return { label: 'Tersedia', tone: 'green' };
  if (available === false) return { label: 'Terpakai', tone: 'amber' };
  return { label: '-', tone: 'gray' };
}

/** Nama hari untuk jam operasional fasilitas (0 = Minggu). */
export const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
