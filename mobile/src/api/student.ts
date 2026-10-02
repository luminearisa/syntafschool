import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get, post } from './client';
import { useAuth } from '@/store/auth';

type Row = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
const useRole = () => useAuth((s) => s.active ?? 'none');
const isStudent = (r: string) => r === 'student';
const studentCtxId = () => useAuth.getState().contexts.find((c) => c.type === 'student')?.id ?? null;

// ============================================================ TUGAS (ERP)
export interface AssignmentItem { assignment: Row; submission: Row | null; status: 'pending' | 'submitted' | 'late' | 'graded' | 'returned' }

/** Tugas satu siswa (`students/{id}/assignments`, dipagari `student.family`). Wali memakai id anaknya. */
export const useStudentAssignments = (studentId?: number | null) => {
  const role = useRole();
  return useQuery({
    queryKey: ['assignments', studentId],
    queryFn: () => get<AssignmentItem[]>(`/students/${studentId}/assignments`),
    enabled: (role === 'student' || role === 'guardian') && !!studentId,
  });
};

export function useSubmitAssignment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { assignmentId: number; content: string }) => post(`/mobile/assignments/${v.assignmentId}/submit`, { content: v.content }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['assignments'] }),
  });
}

export const useOwnStudentId = () => useAuth((s) => s.contexts.find((c) => c.type === 'student')?.id ?? null);

// ================================================================== LMS
export const useLmsDashboard = () => {
  const role = useRole();
  return useQuery({ queryKey: ['lms', 'dashboard'], queryFn: () => get<Row>('/lms/dashboard/student'), enabled: isStudent(role) });
};
export const useLmsCourses = () => {
  const role = useRole();
  return useQuery({ queryKey: ['lms', 'courses'], queryFn: () => get<{ summary: Row; courses: Row[] }>('/lms/my/courses'), enabled: isStudent(role) });
};
export const useLmsPlayer = (courseId: number | null) =>
  useQuery({ queryKey: ['lms', 'player', courseId], queryFn: () => get<Row>(`/lms/courses/${courseId}/learn`), enabled: courseId !== null });
export const useLmsItem = (courseId: number | null, itemId: number | null) =>
  useQuery({ queryKey: ['lms', 'item', courseId, itemId], queryFn: () => get<Row>(`/lms/courses/${courseId}/items/${itemId}`), enabled: courseId !== null && itemId !== null });

export function useLmsComplete() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (v: { courseId: number; itemId: number; done: boolean }) => {
      if (v.done) return post(`/lms/courses/${v.courseId}/items/${v.itemId}/complete`);
      return (await api.delete(`/lms/courses/${v.courseId}/items/${v.itemId}/complete`)).data;
    },
    onSuccess: (_d, v) => {
      qc.invalidateQueries({ queryKey: ['lms', 'player', v.courseId] });
      qc.invalidateQueries({ queryKey: ['lms', 'item', v.courseId, v.itemId] });
      qc.invalidateQueries({ queryKey: ['lms', 'courses'] });
      qc.invalidateQueries({ queryKey: ['lms', 'dashboard'] });
    },
  });
}
/** Catat materi dibuka (item bertipe VIEW tuntas otomatis). Kegagalan tidak boleh mengganggu belajar. */
export const trackItemView = (courseId: number, itemId: number) => post(`/lms/courses/${courseId}/items/${itemId}/view`).catch(() => undefined);

export const useLmsAssignment = (activityId: number | null) =>
  useQuery({ queryKey: ['lms', 'assignment', activityId], queryFn: () => get<Row>(`/lms/activities/${activityId}/assignment`), enabled: activityId !== null });
export function useLmsSubmit() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { activityId: number; text: string }) => post(`/lms/activities/${v.activityId}/submissions/submit`, { text_content: v.text }),
    onSuccess: (_d, v) => { qc.invalidateQueries({ queryKey: ['lms', 'assignment', v.activityId] }); qc.invalidateQueries({ queryKey: ['lms', 'dashboard'] }); },
  });
}
export const useLmsGrades = () => {
  const role = useRole();
  return useQuery({ queryKey: ['lms', 'grades'], queryFn: () => get<{ courses: Row[]; summary: Row }>('/lms/my/grades'), enabled: isStudent(role) });
};
export const useLmsLive = () => {
  const role = useRole();
  return useQuery({ queryKey: ['lms', 'live'], queryFn: () => get<{ live_now: Row[]; sessions: Row[] }>('/lms/my/live-sessions'), enabled: isStudent(role) });
};
export const lmsJoinInfo = (id: number) => get<{ joinable: boolean; reason: string | null; url: string | null }>(`/lms/live-sessions/${id}/join`);
export const lmsRecordJoin = (id: number) => post(`/lms/live-sessions/${id}/join`).catch(() => undefined);
export const useLmsCertificates = () => {
  const role = useRole();
  return useQuery({ queryKey: ['lms', 'certs'], queryFn: () => get<{ certificates: Row[] }>('/lms/my/certificates'), enabled: isStudent(role) });
};

// ========================================================== PERPUSTAKAAN
export const useLibraryMe = () => {
  const role = useRole();
  return useQuery({ queryKey: ['library', 'me'], queryFn: () => get<Row>('/mobile/library/me'), enabled: role === 'student' || role === 'employee' });
};
export const useLibrarySearch = (q: string) =>
  useQuery({
    queryKey: ['library', 'search', q],
    queryFn: async () => (await api.get<{ data: Row[] }>('/library/opac', { params: { q, per_page: 20 } })).data.data,
    enabled: q.trim().length >= 2,
  });
export const libraryAvailability = (slug: string) => get<Row>(`/library/opac/${encodeURIComponent(slug)}/availability`);

const invalidateLib = (qc: ReturnType<typeof useQueryClient>) => qc.invalidateQueries({ queryKey: ['library', 'me'] });
export function useReserve() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (slug: string) => post<{ code: string; queue_position: number }>('/mobile/library/reservations', { slug }), onSuccess: () => invalidateLib(qc) });
}
export function useCancelReservation() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: number) => post(`/mobile/library/reservations/${id}/cancel`), onSuccess: () => invalidateLib(qc) });
}
export function useRenewLoan() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: number) => post<{ due_at: string }>(`/mobile/library/loans/${id}/renew`), onSuccess: () => invalidateLib(qc) });
}

// ============================================================ TAHFIZ SISWA
export const useTahfizSelf = () => {
  const role = useRole();
  return useQuery({ queryKey: ['tahfiz', 'self-student'], queryFn: () => get<Row>('/tahfiz/dashboards/peserta'), enabled: isStudent(role) });
};

// =========================================================== EKSKUL SISWA
export const useMyRegistrations = () => {
  const role = useRole();
  return useQuery({ queryKey: ['activity', 'mine'], queryFn: async () => { const r = await get<Row>('/activities/registrations/mine'); return (r.data ?? r) as Row[]; }, enabled: isStudent(role) });
};
export const useDiscoverPrograms = () => {
  const role = useRole();
  return useQuery({ queryKey: ['activity', 'discover'], queryFn: async () => { const r = await get<Row>('/activities/discover'); return (r.data ?? r) as Row[]; }, enabled: isStudent(role) });
};
export const useActivityStudent = () => {
  const role = useRole();
  const id = studentCtxId();
  return useQuery({ queryKey: ['activity', 'student', id], queryFn: () => get<Row>(`/activities/portal/parent/children/${id}`), enabled: isStudent(role) && !!id });
};
export function useRegisterProgram() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (v: { programId: number; studentId: number; motivation?: string; key: string }) => {
      // Server menentukan status awal (disetujui / seleksi / daftar tunggu / perlu persetujuan orang tua).
      return post<Row>(`/activities/programs/${v.programId}/registrations`, { student_id: v.studentId, motivation: v.motivation, idempotency_key: v.key });
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['activity'] }),
  });
}
export function useCancelRegistration() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: number) => post(`/activities/registrations/${id}/cancel`, {}), onSuccess: () => qc.invalidateQueries({ queryKey: ['activity'] }) });
}
