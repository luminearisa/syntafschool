import { useQuery } from '@tanstack/react-query';
import { masterDataApi } from '@/features/master-data/api/masterDataApi';
import type { AttendanceCode } from '@/types/attendance';

/** Baris master data status presensi (butuh `code`, bukan hanya nama). */
export interface AttendanceStatusRow {
  id: number;
  code: string;
  name: string;
}

/**
 * Status presensi beserta kodenya.
 *
 * `useOptions('attendance-statuses')` hanya mengembalikan `{value,label}`,
 * sedangkan tombol status cepat butuh `code` (H/A/I/S/T) untuk menentukan
 * tombol mana yang aktif.
 */
export function useAttendanceStatuses() {
  const { data, isLoading } = useQuery({
    queryKey: ['options', 'attendance-statuses', 'with-code'],
    staleTime: 1000 * 60 * 30,
    queryFn: async () => {
      const rows = await masterDataApi.all('attendance-statuses');
      return rows.map<AttendanceStatusRow>((row) => ({
        id: Number(row.id),
        code: String(row.code ?? ''),
        name: String(row.name ?? ''),
      }));
    },
  });

  return { statuses: data ?? [], isLoading };
}

/** Label singkat untuk tombol status cepat. */
export const CODE_LABELS: Record<string, string> = {
  H: 'Hadir',
  A: 'Alpa',
  I: 'Izin',
  S: 'Sakit',
  T: 'Terlambat',
};

/**
 * Warna badge per kode status.
 *
 * Dijaga di satu tempat supaya tabel presensi, rekap, dan koreksi tidak
 * memakai warna yang berbeda untuk status yang sama.
 */
export function codeTone(code: string | null | undefined) {
  switch (code as AttendanceCode | undefined | null) {
    case 'H':
      return 'green' as const;
    case 'T':
      return 'amber' as const;
    case 'I':
      return 'blue' as const;
    case 'S':
      return 'brand' as const;
    case 'A':
      return 'red' as const;
    default:
      return 'gray' as const;
  }
}

/** Status yang wajib menyertakan jenis izin (sama dengan aturan backend). */
export const CODES_REQUIRING_LEAVE_TYPE: string[] = ['I', 'S'];

/** Warna persentase kehadiran. */
export function percentageTone(percentage: number) {
  if (percentage >= 90) return 'green' as const;
  if (percentage >= 75) return 'amber' as const;
  return 'red' as const;
}
