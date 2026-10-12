import { formatDate, formatRupiah } from '@/lib/utils';
import type { BlockFormat } from '@/features/profile/api/profileApi';

type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue';

const GREEN = ['active', 'aktif', 'paid', 'lunas', 'approved', 'disetujui', 'completed', 'selesai', 'done', 'graded', 'published', 'normal', 'verified', 'success', 'berhasil', 'present', 'hadir', 'returned', 'ringan', 'low', 'rendah', 'passed', 'lulus', 'naik', 'baru', 'valid'];
const RED = ['overdue', 'rejected', 'ditolak', 'cancelled', 'canceled', 'failed', 'gagal', 'unpaid', 'alfa', 'absent', 'lost', 'expired', 'revoked', 'berat', 'darurat', 'high', 'tinggi', 'critical', 'rujuk', 'keluar', 'inactive', 'nonaktif', 'blocked', 'diblokir', 'terlambat'];
const AMBER = ['pending', 'menunggu', 'submitted', 'diajukan', 'partial', 'issued', 'draft', 'late', 'sedang', 'medium', 'perlu_perhatian', 'waiting', 'under_review', 'proses', 'in_progress', 'open', 'terbuka', 'queued', 'tinggal', 'ditangani', 'dirujuk', 'sakit', 'izin'];

const LABELS: Record<string, string> = {
  active: 'Aktif', inactive: 'Nonaktif', paid: 'Lunas', unpaid: 'Belum dibayar', partial: 'Sebagian', issued: 'Terbit',
  overdue: 'Lewat tempo', cancelled: 'Dibatalkan', canceled: 'Dibatalkan', draft: 'Draf', pending: 'Menunggu',
  submitted: 'Diajukan', approved: 'Disetujui', rejected: 'Ditolak', completed: 'Selesai', graded: 'Dinilai',
  published: 'Terbit', verified: 'Terverifikasi', returned: 'Dikembalikan', borrowed: 'Dipinjam', lost: 'Hilang',
  late: 'Terlambat', present: 'Hadir', absent: 'Tidak hadir', in_progress: 'Berlangsung', open: 'Terbuka', closed: 'Ditutup',
  low: 'Rendah', medium: 'Sedang', high: 'Tinggi', critical: 'Kritis', normal: 'Normal', passed: 'Lulus', failed: 'Gagal',
  expired: 'Kedaluwarsa', revoked: 'Dicabut', blocked: 'Diblokir', queued: 'Antre', waiting: 'Menunggu',
  under_review: 'Ditinjau', perlu_perhatian: 'Perlu perhatian', rujuk: 'Rujuk',
};

export function prettify(value: string): string {
  const key = value.toLowerCase();
  if (LABELS[key]) return LABELS[key];
  const text = value.replace(/[_-]+/g, ' ').trim();
  return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
}

export function toneFor(value: unknown): Tone {
  const key = String(value ?? '').toLowerCase();
  if (GREEN.includes(key)) return 'green';
  if (RED.includes(key)) return 'red';
  if (AMBER.includes(key)) return 'amber';
  return 'blue';
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '-';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '-';
  return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function formatNumber(value: unknown): string {
  const n = Number(value);
  if (value === null || value === '' || Number.isNaN(n)) return '-';
  return n.toLocaleString('id-ID', { maximumFractionDigits: 2 });
}

/** Teks polos (badge ditangani komponen terpisah). */
export function formatPlain(value: unknown, format: BlockFormat): string {
  if (value === null || value === undefined || value === '') return '-';
  switch (format) {
    case 'date':
      return formatDate(String(value));
    case 'datetime':
      return formatDateTime(String(value));
    case 'money':
      return formatRupiah(value as number);
    case 'number':
      return formatNumber(value);
    case 'percent':
      return `${formatNumber(value)}%`;
    case 'bool':
      return value === true || value === 1 || value === '1' ? 'Ya' : 'Tidak';
    case 'badge':
      return prettify(String(value));
    default:
      return String(value);
  }
}
