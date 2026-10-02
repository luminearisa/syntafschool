import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { get, post } from './client';
import { useAuth } from '@/store/auth';
import type {
  AttendanceRecord, ChildCard, EmployeeAttendanceRow, EmployeeHome, FinancePayload, HomePayload,
  NotificationItem, ReportCardRow, ScheduleRow, ScoreRow,
} from './types';

// Kunci query selalu memuat peran aktif agar cache satu peran tak bocor ke peran lain.
const useKey = () => useAuth((s) => s.active ?? 'none');

export const useHome = () => {
  const role = useKey();
  return useQuery({ queryKey: [role, 'home'], queryFn: () => get<HomePayload>('/mobile/home'), enabled: role === 'guardian' || role === 'student' });
};

export const useEmployeeHome = () => {
  const role = useKey();
  return useQuery({ queryKey: [role, 'employee-home'], queryFn: () => get<EmployeeHome>('/mobile/employee/home'), enabled: role === 'employee' });
};

export function useChildren() {
  const role = useKey();
  const selected = useAuth((s) => s.selectedChildId);
  const q = useQuery({ queryKey: [role, 'children'], queryFn: () => get<ChildCard[]>('/mobile/children'), enabled: role === 'guardian' || role === 'student' });
  const children = q.data ?? [];
  const child = children.find((c) => c.id === selected) ?? children[0] ?? null;
  return { ...q, children, child };
}

const childQuery = <T,>(path: string, childId: number | undefined, params?: Record<string, unknown>) => ({
  queryKey: ['child', childId, path, params],
  queryFn: () => get<T>(`/mobile/children/${childId}/${path}`, params),
  enabled: !!childId,
});

export const useChildAttendance = (id: number | undefined, month: string) =>
  useQuery(childQuery<{ month: string; summary: Record<string, number>; records: AttendanceRecord[] }>('attendance', id, { month }));
export const useChildScores = (id?: number) =>
  useQuery(childQuery<{ subjects: { subject: string; average: number | null; count: number }[]; scores: ScoreRow[] }>('scores', id));
export const useChildReportCards = (id?: number) => useQuery(childQuery<ReportCardRow[]>('report-cards', id));
export const useChildSchedule = (id?: number) => useQuery(childQuery<ScheduleRow[]>('schedule', id));
export const useChildFinance = (id?: number) => useQuery(childQuery<FinancePayload>('finance', id));

export const useEmployeeAttendance = (month: string) => {
  const role = useKey();
  return useQuery({
    queryKey: [role, 'employee-attendance', month],
    queryFn: () => get<{ month: string; summary: Record<string, number>; records: EmployeeAttendanceRow[] }>('/mobile/employee/attendance', { month }),
    enabled: role === 'employee',
  });
};

export function useCheck() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (kind: 'check-in' | 'check-out') => post<EmployeeAttendanceRow>(`/mobile/employee/attendance/${kind}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee', 'employee-home'] });
      qc.invalidateQueries({ queryKey: ['employee', 'employee-attendance'] });
    },
  });
}

export const useNotifications = () => {
  const token = useAuth((s) => s.token);
  const active = useAuth((s) => s.active);
  return useQuery({
    queryKey: [active, 'notifications'],
    queryFn: () => get<{ unread: number; items: NotificationItem[] }>('/mobile/notifications'),
    enabled: !!token && !!active,
    refetchInterval: 60_000, // pengganti push: poll ringan saat aplikasi aktif
  });
};

export function useReadNotifications() {
  const qc = useQueryClient();
  const active = useAuth((s) => s.active);
  return useMutation({
    mutationFn: (id?: string) => post(id ? `/mobile/notifications/${id}/read` : '/mobile/notifications/read-all'),
    onSuccess: () => qc.invalidateQueries({ queryKey: [active, 'notifications'] }),
  });
}
