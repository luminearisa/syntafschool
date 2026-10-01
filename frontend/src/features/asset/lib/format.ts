/** Pembantu tampilan khusus modul aset (tanggal-waktu, durasi, rentang). */

export function fmtDateTime(value?: string | null): string {
  if (!value) return '-';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '-';
  return d.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function fmtTime(value?: string | null): string {
  if (!value) return '-';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '-';
  return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}

/** `2026-03-14` untuk `<input type="date">` */
export function toDateInput(value?: string | Date | null): string {
  if (!value) return '';
  const d = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(d.getTime())) return '';
  const bulan = `${d.getMonth() + 1}`.padStart(2, '0');
  const hari = `${d.getDate()}`.padStart(2, '0');
  return `${d.getFullYear()}-${bulan}-${hari}`;
}

/** `2026-03-14T08:30` untuk `<input type="datetime-local">` */
export function toDateTimeInput(value?: string | Date | null): string {
  if (!value) return '';
  const d = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(d.getTime())) return '';
  const jam = `${d.getHours()}`.padStart(2, '0');
  const menit = `${d.getMinutes()}`.padStart(2, '0');
  return `${toDateInput(d)}T${jam}:${menit}`;
}

/** Durasi menit → "2 jam 30 menit" (dibaca manusia, bukan 150 menit). */
export function fmtDuration(minutes?: number | null): string {
  if (minutes === null || minutes === undefined) return '-';
  const total = Math.max(0, Math.round(minutes));
  const jam = Math.floor(total / 60);
  const menit = total % 60;
  if (jam === 0) return `${menit} menit`;
  if (menit === 0) return `${jam} jam`;
  return `${jam} jam ${menit} menit`;
}

/** Umur dalam hari sejak tanggal tertentu (untuk "75% umur manfaat"). */
export function daysSince(value?: string | null): number | null {
  if (!value) return null;
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return null;
  return Math.floor((Date.now() - d.getTime()) / 86_400_000);
}

export function isOverdue(due?: string | null, done?: boolean | null): boolean {
  if (done || !due) return false;
  return new Date(due).getTime() < Date.now();
}

/** Awal minggu (Senin) untuk papan jadwal. */
export function startOfWeek(date: Date): Date {
  const d = new Date(date);
  const day = d.getDay();
  const selisih = day === 0 ? -6 : 1 - day;
  d.setDate(d.getDate() + selisih);
  d.setHours(0, 0, 0, 0);
  return d;
}

export function addDays(date: Date, days: number): Date {
  const d = new Date(date);
  d.setDate(d.getDate() + days);
  return d;
}

export function sameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

export function fmtDayLabel(date: Date): string {
  return date.toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short' });
}

export function fmtPeriod(date: Date): string {
  return `${date.getFullYear()}-${`${date.getMonth() + 1}`.padStart(2, '0')}`;
}

/**
 * Umur manfaat yang dapat dibaca manusia.
 *
 * Backend menyimpan `useful_life_months` dan `expected_life_end`; sisa umur dan
 * persentase dihitung di sini dari keduanya. Perhitungan ini murni tampilan —
 * keputusan penggantian tetap dihitung ulang di backend, bukan dipercaya dari
 * layar.
 */
export function lifeProgress(
  expectedLifeEnd?: string | null,
  usefulLifeMonths?: number | null,
): { remainingMonths: number | null; percent: number | null; lewat: boolean } {
  if (!expectedLifeEnd) return { remainingMonths: null, percent: null, lewat: false };

  const akhir = new Date(expectedLifeEnd);
  if (Number.isNaN(akhir.getTime())) return { remainingMonths: null, percent: null, lewat: false };

  const selisihHari = Math.round((akhir.getTime() - Date.now()) / 86_400_000);
  const remainingMonths = Math.max(0, Math.round(selisihHari / 30.44));
  const lewat = selisihHari < 0;

  if (!usefulLifeMonths || usefulLifeMonths <= 0) {
    return { remainingMonths, percent: null, lewat };
  }

  const terpakaiBulan = Math.min(usefulLifeMonths, Math.max(0, usefulLifeMonths - remainingMonths));
  const percent = Math.round((terpakaiBulan / usefulLifeMonths) * 100);

  return { remainingMonths, percent: lewat ? 100 : percent, lewat };
}
