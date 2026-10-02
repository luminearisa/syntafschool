import dayjs from 'dayjs';
import 'dayjs/locale/id';

dayjs.locale('id');

export const rupiah = (n: number | string | null | undefined): string =>
  'Rp ' + Math.round(Number(n ?? 0)).toLocaleString('id-ID');

export const dateLong = (d?: string | null) => (d ? dayjs(d).format('dddd, D MMMM YYYY') : '-');
export const dateShort = (d?: string | null) => (d ? dayjs(d).format('D MMM YYYY') : '-');
export const timeHM = (t?: string | null) => (t ? t.slice(0, 5) : '--:--');
export const monthLabel = (ym: string) => dayjs(ym + '-01').format('MMMM YYYY');
export const shiftMonth = (ym: string, delta: number) => dayjs(ym + '-01').add(delta, 'month').format('YYYY-MM');
export const initials = (name?: string | null) =>
  (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('');

export const greeting = () => {
  const h = dayjs().hour();
  return h < 11 ? 'Selamat pagi' : h < 15 ? 'Selamat siang' : h < 18 ? 'Selamat sore' : 'Selamat malam';
};

export const DAYS = ['', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
