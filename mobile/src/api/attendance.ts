import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { get, post } from './client';
import { useAuth } from '@/store/auth';

const useRole = () => useAuth((s) => s.active ?? 'none');

export interface AttendanceLookups {
  statuses: { id: number; code: string; name: string }[];
  leave_types: { id: number; name: string; requires_attachment: boolean }[];
}

export const useAttendanceLookups = () => {
  const role = useRole();
  return useQuery({ queryKey: [role, 'att-lookups'], queryFn: () => get<AttendanceLookups>('/attendance-lookups'), enabled: role === 'employee', staleTime: 30 * 60_000 });
};

export interface SheetRow {
  student_id: number;
  nis: string;
  full_name: string;
  attendance_status_id: number | null;
  status_code: string | null;
  leave_type_id: number | null;
  notes: string | null;
  recorded: boolean;
}

export interface ClassSheet {
  class_id: number;
  semester_id: number;
  date: string;
  is_school_day: boolean | null;
  total: number;
  recorded: number;
  items: SheetRow[];
}

export const useClassSheet = (p: { classId?: number; semesterId?: number; date: string; scheduleId?: number }) =>
  useQuery({
    queryKey: ['att-sheet', p],
    queryFn: () => get<ClassSheet>('/student-attendances/sheet', { class_id: p.classId, semester_id: p.semesterId, date: p.date, schedule_id: p.scheduleId }),
    enabled: !!p.classId && !!p.semesterId,
  });

export interface BulkBody {
  class_id: number;
  semester_id: number;
  date: string;
  schedule_id?: number;
  subject_id?: number;
  entries: { student_id: number; attendance_status_id: number; leave_type_id?: number | null; notes?: string }[];
}

export function useSaveSheet() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: BulkBody) => post<{ created: number; updated: number; total: number }>('/student-attendances/bulk', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['att-sheet'] }),
  });
}
