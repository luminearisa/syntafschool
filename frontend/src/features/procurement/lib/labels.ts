import type { SelectOption } from '../types';

type Tone = 'green' | 'amber' | 'red' | 'blue' | 'gray' | 'brand';

/**
 * Terjemahan nilai enum → label + warna, satu tempat untuk seluruh modul.
 *
 * DISELARASKAN dengan enum backend (`App\Modules\Procurement\Domain\Enums`).
 * Nilai yang belum dikenal tidak pernah ditampilkan apa adanya: `prettify()`
 * mempercantiknya, sehingga layar tetap terbaca bila backend menambah status
 * lebih dulu daripada frontend menyusul — dan itu memang urutan yang benar.
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

/* ── Vendor ──────────────────────────────────────────────────────────────── */

export const vendorStatus = make({
  DRAFT: ['Draf', 'gray'],
  REGISTERED: ['Terdaftar', 'blue'],
  UNDER_VERIFICATION: ['Menunggu verifikasi', 'amber'],
  VERIFIED: ['Terverifikasi', 'blue'],
  ACTIVE: ['Aktif', 'green'],
  SUSPENDED: ['Ditangguhkan', 'amber'],
  INACTIVE: ['Nonaktif', 'gray'],
  REJECTED: ['Ditolak', 'red'],
  BLACKLISTED: ['Daftar hitam', 'red'],
});

export const vendorType = make({
  SUPPLIER: ['Pemasok', 'blue'],
  SERVICE_PROVIDER: ['Penyedia jasa', 'blue'],
  CONTRACTOR: ['Kontraktor', 'blue'],
  CONSULTANT: ['Konsultan', 'blue'],
  DISTRIBUTOR: ['Distributor', 'blue'],
  MANUFACTURER: ['Produsen', 'blue'],
  RENTAL_PROVIDER: ['Penyedia sewa', 'gray'],
  TRANSPORTATION_PROVIDER: ['Transportasi', 'gray'],
  CATERING_PROVIDER: ['Katering', 'gray'],
  MAINTENANCE_PROVIDER: ['Pemeliharaan', 'gray'],
  TECHNOLOGY_PROVIDER: ['Teknologi', 'blue'],
  OTHER: ['Lainnya', 'gray'],
});

export const vendorTier = make({
  TIER_1: ['Tier 1', 'green'],
  TIER_2: ['Tier 2', 'blue'],
  TIER_3: ['Tier 3', 'gray'],
});

export const vendorClassification = make({
  NORMAL: ['Normal', 'gray'],
  STRATEGIC: ['Strategis', 'brand'],
  PREFERRED: ['Diutamakan', 'green'],
  RESTRICTED: ['Terbatas', 'amber'],
  BLACKLISTED: ['Daftar hitam', 'red'],
});

export const vendorRisk = make({
  LOW: ['Rendah', 'green'],
  MEDIUM: ['Sedang', 'amber'],
  HIGH: ['Tinggi', 'red'],
});

export const vendorDocumentStatus = make({
  PENDING: ['Menunggu', 'gray'],
  SUBMITTED: ['Diajukan', 'blue'],
  VERIFIED: ['Terverifikasi', 'green'],
  REJECTED: ['Ditolak', 'red'],
  EXPIRED: ['Kedaluwarsa', 'amber'],
});

export const vendorDocumentType = make({
  LEGAL: ['Legalitas', 'blue'],
  NPWP: ['NPWP', 'blue'],
  NIB: ['NIB', 'blue'],
  BANK_ACCOUNT: ['Rekening bank', 'blue'],
  CONTRACT: ['Kontrak', 'blue'],
  QUOTATION_PROFILE: ['Profil penawaran', 'gray'],
  CERTIFICATE: ['Sertifikat', 'blue'],
  LICENSE: ['Izin', 'blue'],
  INSURANCE: ['Asuransi', 'blue'],
  COMPLIANCE: ['Kepatuhan', 'blue'],
  OTHER: ['Lainnya', 'gray'],
});

/* ── Katalog & metode ────────────────────────────────────────────────────── */

export const itemType = make({
  GOODS: ['Barang', 'blue'],
  SERVICE: ['Jasa', 'brand'],
  SUBSCRIPTION: ['Langganan', 'brand'],
  RENTAL: ['Sewa', 'gray'],
  MAINTENANCE: ['Pemeliharaan', 'gray'],
  CONSTRUCTION: ['Konstruksi', 'amber'],
  CONSULTING: ['Konsultasi', 'blue'],
  RECURRING: ['Berulang', 'gray'],
});

export const procurementMethod = make({
  DIRECT: ['Pembelian langsung', 'gray'],
  RFQ: ['Permintaan penawaran', 'blue'],
  COMPETITIVE: ['Kompetitif', 'brand'],
  CONTRACT: ['Berbasis kontrak', 'blue'],
  EMERGENCY: ['Darurat', 'red'],
});

/* ── Purchase Request ────────────────────────────────────────────────────── */

export const prStatus = make({
  DRAFT: ['Draf', 'gray'],
  SUBMITTED: ['Diajukan', 'blue'],
  UNDER_REVIEW: ['Dalam telaah', 'amber'],
  APPROVED: ['Disetujui', 'green'],
  REJECTED: ['Ditolak', 'red'],
  RETURNED: ['Dikembalikan', 'amber'],
  CONVERTED: ['Dikonversi', 'brand'],
  CLOSED: ['Selesai', 'gray'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const prPriority = make({
  LOW: ['Rendah', 'gray'],
  NORMAL: ['Normal', 'blue'],
  HIGH: ['Tinggi', 'amber'],
  URGENT: ['Mendesak', 'red'],
});

export const approvalStatus = make({
  PENDING: ['Menunggu', 'amber'],
  APPROVED: ['Disetujui', 'green'],
  REJECTED: ['Ditolak', 'red'],
  RETURNED: ['Dikembalikan', 'amber'],
  SKIPPED: ['Dilewati', 'gray'],
});

/* ── RFQ, penawaran, evaluasi ────────────────────────────────────────────── */

export const rfqStatus = make({
  DRAFT: ['Draf', 'gray'],
  ISSUED: ['Terbit', 'blue'],
  OPEN: ['Terbuka', 'blue'],
  PARTIALLY_RESPONDED: ['Sebagian dijawab', 'amber'],
  CLOSED: ['Ditutup', 'gray'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const quotationStatus = make({
  DRAFT: ['Draf', 'gray'],
  RECEIVED: ['Diterima', 'blue'],
  EVALUATED: ['Dievaluasi', 'brand'],
  SELECTED: ['Terpilih', 'green'],
  NOT_SELECTED: ['Tidak terpilih', 'gray'],
  EXPIRED: ['Kedaluwarsa', 'amber'],
  WITHDRAWN: ['Ditarik', 'red'],
});

export const selectionStatus = make({
  DRAFT: ['Draf', 'gray'],
  PENDING: ['Menunggu persetujuan', 'amber'],
  APPROVED: ['Disetujui', 'green'],
  REJECTED: ['Ditolak', 'red'],
});

/* ── Purchase Order ──────────────────────────────────────────────────────── */

export const poStatus = make({
  DRAFT: ['Draf', 'gray'],
  PENDING_APPROVAL: ['Menunggu persetujuan', 'amber'],
  APPROVED: ['Disetujui', 'blue'],
  REJECTED: ['Ditolak', 'red'],
  RETURNED: ['Dikembalikan', 'amber'],
  ISSUED: ['Terbit', 'brand'],
  VENDOR_CONFIRMED: ['Dikonfirmasi vendor', 'blue'],
  PARTIALLY_RECEIVED: ['Diterima sebagian', 'amber'],
  FULLY_RECEIVED: ['Diterima penuh', 'green'],
  CLOSED: ['Selesai', 'gray'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const vendorConfirmationStatus = make({
  PENDING: ['Menunggu', 'amber'],
  ACCEPTED: ['Diterima', 'green'],
  PARTIALLY_ACCEPTED: ['Diterima sebagian', 'amber'],
  REJECTED: ['Ditolak', 'red'],
  REVISED: ['Direvisi', 'blue'],
});

/* ── Penerimaan ──────────────────────────────────────────────────────────── */

export const receiptStatus = make({
  DRAFT: ['Draf', 'gray'],
  RECEIVED: ['Diterima', 'blue'],
  INSPECTED: ['Diinspeksi', 'brand'],
  ACCEPTED: ['Diterima baik', 'green'],
  PARTIALLY_ACCEPTED: ['Diterima sebagian', 'amber'],
  REJECTED: ['Ditolak', 'red'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

/* ── Invoice & matching ──────────────────────────────────────────────────── */

export const invoiceStatus = make({
  DRAFT: ['Draf', 'gray'],
  RECEIVED: ['Diterima', 'blue'],
  UNDER_MATCHING: ['Dalam pencocokan', 'amber'],
  MATCHED: ['Cocok', 'green'],
  MISMATCH: ['Tidak cocok', 'red'],
  EXCEPTION: ['Pengecualian', 'amber'],
  APPROVED: ['Disetujui', 'brand'],
  SENT_TO_ACCOUNTING: ['Dikirim ke akuntansi', 'brand'],
  POSTED: ['Terposting', 'green'],
  REJECTED: ['Ditolak', 'red'],
  CANCELLED: ['Dibatalkan', 'gray'],
});

export const matchStatus = make({
  MATCHED: ['Cocok', 'green'],
  PARTIALLY_MATCHED: ['Cocok sebagian', 'amber'],
  MISMATCH: ['Tidak cocok', 'red'],
  EXCEPTION: ['Pengecualian', 'amber'],
});

export const exceptionType = make({
  QUANTITY_MISMATCH: ['Selisih kuantitas', 'amber'],
  PRICE_MISMATCH: ['Selisih harga', 'amber'],
  TAX_MISMATCH: ['Selisih pajak', 'amber'],
  MISSING_RECEIVING: ['Belum ada penerimaan', 'red'],
  DUPLICATE_INVOICE: ['Invoice ganda', 'red'],
  WRONG_VENDOR: ['Vendor salah', 'red'],
  WRONG_PO: ['PO salah', 'red'],
  INVALID_INVOICE: ['Invoice tidak sah', 'red'],
  OVERBILLING: ['Tagihan berlebih', 'red'],
});

export const exceptionStatus = make({
  DETECTED: ['Terdeteksi', 'amber'],
  INVESTIGATION: ['Dalam penyelidikan', 'blue'],
  RESOLVED: ['Selesai', 'green'],
  APPROVED: ['Disetujui', 'green'],
  REJECTED: ['Ditolak', 'red'],
  CLOSED: ['Ditutup', 'gray'],
});

export const handoffStatus = make({
  PENDING: ['Menunggu', 'amber'],
  SENT: ['Terkirim', 'blue'],
  ACCEPTED: ['Diterima', 'green'],
  REJECTED: ['Ditolak', 'red'],
  POSTED: ['Terposting', 'green'],
  FAILED: ['Gagal', 'red'],
  RETRYING: ['Mencoba ulang', 'amber'],
});

/* ── Kontrak ─────────────────────────────────────────────────────────────── */

export const contractStatus = make({
  DRAFT: ['Draf', 'gray'],
  ACTIVE: ['Aktif', 'green'],
  EXPIRING: ['Akan berakhir', 'amber'],
  EXPIRED: ['Berakhir', 'red'],
  TERMINATED: ['Diputus', 'red'],
  RENEWED: ['Diperbarui', 'blue'],
});
