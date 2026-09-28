import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type { AttendanceCode } from '@/types/attendance';

/** Baris status presensi yang diperlukan oleh form & tombol status cepat. */
export interface AttendanceStatusRow {
  id: number;
  code: string;
  name: string;
}

interface AttendanceLookups {
  statuses: AttendanceStatusRow[];
  leave_types: { id: number; name: string; requires_attachment: boolean }[];
  semesters: { id: number; name: string; academic_year_id: number; academic_year?: { id: number; name: string } | null }[];
}

export function useAttendanceStatuses() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance', 'lookups'],
    staleTime: 1000 * 60 * 30,
    queryFn: async () => {
      const response = await api.get<ApiResponse<AttendanceLookups>>('/attendance-lookups');
      return response.data.data;
    },
  });

  return {
    statuses: data?.statuses ?? [],
    leaveTypeOptions: (data?.leave_types ?? []).map((row) => ({ value: row.id, label: row.name })),
    semesterOptions: (data?.semesters ?? []).map((row) => ({ value: row.id, label: `${row.name} · ${row.academic_year?.name ?? ''}` })),
    isLoading,
    isError,
  };
}

export const CODE_LABELS: Record<string, string> = {
  H: 'Hadir',
  A: 'Alpa',
  I: 'Izin',
  S: 'Sakit',
  T: 'Terlambat',
};

export function codeTone(code: string | null | undefined) {
  switch (code as AttendanceCode | undefined | null) {
    case 'H': return 'green' as const;
    case 'T': return 'amber' as const;
    case 'I': return 'blue' as const;
    case 'S': return 'brand' as const;
    case 'A': return 'red' as const;
    default: return 'gray' as const;
  }
}

export const CODES_REQUIRING_LEAVE_TYPE: string[] = ['I', 'S'];

export function percentageTone(percentage: number) {
  if (percentage >= 90) return 'green' as const;
  if (percentage >= 75) return 'amber' as const;
  return 'red' as const;
}
