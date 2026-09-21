/**
 * Pembantu format & validasi sisi klien untuk halaman publik dan portal PPDB.
 * Sengaja berada di feature agar tidak menyentuh util global.
 */

import { formatDate, formatRupiah } from '@/lib/utils';

export { formatDate, formatRupiah };

const dateTimeFmt = new Intl.DateTimeFormat('id-ID', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});

const clockFmt = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' });

const MS_PER_DAY = 86_400_000;

export function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null;
  const d = value instanceof Date ? value : new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** Tanggal + jam, mis. "01 Feb 2026 08.30". */
export function formatDateTime(value: string | null | undefined): string {
  const d = toDate(value);
  return d ? dateTimeFmt.format(d) : '-';
}

/** Jam saja, dipakai indikator "tersimpan otomatis". */
export function formatClock(value: Date | string | null | undefined): string {
  const d = toDate(value);
  return d ? clockFmt.format(d) : '-';
}

/** Tanggal ISO (yyyy-mm-dd) untuk input `type="date"`. */
export function toInputDate(value: string | null | undefined): string {
  const d = toDate(value);
  if (!d) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Selisih hari bulat ke arah tanggal target (negatif = sudah lewat). */
export function daysUntil(value: string | null | undefined, from: Date = new Date()): number | null {
  const d = toDate(value);
  if (!d) return null;
  const start = new Date(from.getFullYear(), from.getMonth(), from.getDate()).getTime();
  const target = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  return Math.round((target - start) / MS_PER_DAY);
}

/** Kalimat sisa waktu yang jujur: "3 hari lagi", "Berakhir hari ini", "Sudah lewat 2 hari". */
export function remainingLabel(value: string | null | undefined): string {
  const diff = daysUntil(value);
  if (diff === null) return 'Tanggal belum ditentukan';
  if (diff > 1) return `Berakhir dalam ${diff} hari lagi`;
  if (diff === 1) return 'Berakhir besok';
  if (diff === 0) return 'Hari ini adalah batas akhir';
  if (diff === -1) return 'Baru saja lewat dari batas akhir';
  return `Sudah lewat ${Math.abs(diff)} hari dari batas akhir`;
}

/** Penghitung tanggal jatuh tempo (dipakai banner periode & batas bayar). */
export function countdownLabel(value: string | null | undefined): string {
  const diff = daysUntil(value);
  if (diff === null) return '-';
  if (diff > 0) return `${diff} hari`;
  if (diff === 0) return 'Hari ini';
  return `Lewat ${Math.abs(diff)} hari`;
}

/** Ukuran berkas manusia dari KB persyaratan. */
function kbMagnitude(kb: number): string {
  if (kb < 1024) return `${kb} KB`;
  return `${Math.round((kb / 1024) * 10) / 10} MB`;
}

export function formatKbLimit(kb: number | null | undefined): string {
  if (!kb || kb <= 0) return 'tanpa batas ukuran';
  return `maksimum ${kbMagnitude(kb)}`;
}

/** Batas bawah ukuran — jangan pakai `formatKbLimit`, hasilnya "minimal maksimum". */
export function formatKbFloor(kb: number | null | undefined): string {
  if (!kb || kb <= 0) return 'tanpa batas ukuran';
  return `minimal ${kbMagnitude(kb)}`;
}

export function formatFileSize(bytes: number | null | undefined): string {
  if (!bytes || bytes <= 0) return '-';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Ekstensi tanpa titik, huruf kecil. Menggabungkan `allowed_extensions` + `file_types`. */
export function allowedExtensionsOf(
  allowed: string[] | null | undefined,
  fileTypes: string | null | undefined,
): string[] {
  const raw = [...(allowed ?? []), ...(fileTypes ? fileTypes.split(/[,|\s]+/) : [])];
  const out = raw
    .map((item) => item.trim().toLowerCase().replace(/^\./, ''))
    .filter((item) => item.length > 0 && item.length <= 5);
  return Array.from(new Set(out));
}

export function acceptAttribute(extensions: string[]): string | undefined {
  return extensions.length ? extensions.map((ext) => `.${ext}`).join(',') : undefined;
}

/** Penjaga sisi klien sebelum berkas dikirim — hemat kuota orang tua. */
export function validateFileAgainstRequirement(
  file: File,
  rule: { extensions: string[]; maxSizeKb?: number | null; minSizeKb?: number | null },
): string | null {
  const ext = (file.name.split('.').pop() ?? '').toLowerCase();
  if (rule.extensions.length && !rule.extensions.includes(ext)) {
    return `Format berkas harus ${rule.extensions.join(', ')}. Berkas Anda berformat ${ext || 'tidak dikenal'}.`;
  }
  if (rule.maxSizeKb && rule.maxSizeKb > 0 && file.size > rule.maxSizeKb * 1024) {
    return `Ukuran berkas melebihi ${formatKbLimit(rule.maxSizeKb)}. Berkas Anda ${formatFileSize(file.size)}.`;
  }
  if (rule.minSizeKb && rule.minSizeKb > 0 && file.size < rule.minSizeKb * 1024) {
    return `Ukuran berkas terlalu kecil, ${formatKbFloor(rule.minSizeKb)}.`;
  }
  return null;
}

export function toId(value: string | number | '' | null | undefined): number | '' {
  if (value === '' || value === null || value === undefined) return '';
  const num = Number(value);
  return Number.isNaN(num) ? '' : num;
}

export function toNumber(value: unknown, fallback = 0): number {
  const num = Number(value ?? fallback);
  return Number.isFinite(num) ? num : fallback;
}

export function initialsOf(name: string | null | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).slice(0, 2);
  if (!parts.length) return '?';
  return parts.map((p) => p.charAt(0).toUpperCase()).join('');
}

/**
 * Nama dari kolom rujukan.
 *
 * `PpdbApplicantResource` mengirim `gender` / `religion` / `target_program`
 * sebagai string, sementara `PpdbRegistrationResource` mengirim `{name}`;
 * pembacaan keduanya disatukan di sini agar halaman tidak crash bila bentuk
 * salah satu kolom berubah.
 */
export function nameOf(value: unknown): string | null {
  if (typeof value === 'string') return value === '' ? null : value;
  if (value && typeof value === 'object' && 'name' in value) {
    const name = (value as { name?: unknown }).name;
    return typeof name === 'string' && name !== '' ? name : null;
  }
  return null;
}

/** Baris "kuota": sisa + persentase terisi, aman untuk data null. */
export function quotaLine(quota: number | null, taken: number | null): string {
  if (quota === null || quota === undefined) {
    return taken !== null && taken !== undefined ? `${taken} pendaftar` : 'Kuota belum ditetapkan';
  }
  const filled = taken ?? 0;
  if (quota === 0) return 'Kuota habis';
  const remaining = Math.max(quota - filled, 0);
  return `${filled}/${quota} terisi · sisa ${remaining}`;
}

export function percentOf(quota: number | null | undefined, taken: number | null | undefined): number {
  if (!quota || quota <= 0) return 0;
  return Math.min(100, Math.round(((taken ?? 0) / quota) * 100));
}

/** Panjang jawabannya tidak boleh bikin kartu meledak. */
export function truncate(value: string, max = 90): string {
  return value.length <= max ? value : `${value.slice(0, max - 1)}…`;
}
