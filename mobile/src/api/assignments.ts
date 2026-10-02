import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, post } from './client';
import { useAuth } from '@/store/auth';
import { useEmployeeId } from './teaching';

const useRole = () => useAuth((s) => s.active ?? 'none');

export interface AssignmentRow {
  id: number;
  class_id: number;
  subject_id: number;
  title: string;
  description: string;
  type: string;
  max_score: number;
  weight: number;
  assigned_date: string;
  due_date: string;
  status: 'draft' | 'published' | 'closed';
  submission_count: number;
  graded_count: number;
  is_overdue: boolean;
  school_class?: { id: number; name: string } | null;
  subject?: { id: number; name: string } | null;
}

export interface SubmissionRow {
  student_id: number;
  nis: string;
  full_name: string;
  submission_id: number | null;
  status: 'pending' | 'submitted' | 'late' | 'graded' | 'returned';
  submitted_at: string | null;
  score: number | null;
  feedback: string | null;
  has_submitted: boolean;
}

export const TYPES = ['tugas', 'kuis', 'projek', 'praktik', 'portofolio'] as const;

export const useMyAssignments = () => {
  const role = useRole();
  const employeeId = useEmployeeId();
  return useQuery({
    queryKey: [role, 'assignments', employeeId],
    queryFn: async () => (await api.get<{ data: AssignmentRow[] }>('/assignments', { params: { filter: { teacher_id: employeeId }, per_page: 50 } })).data.data,
    enabled: role === 'employee' && employeeId !== null,
  });
};

export const useSubmissions = (id: number | null) =>
  useQuery({
    queryKey: ['assignment-submissions', id],
    queryFn: async () => (await api.get<{ data: SubmissionRow[]; meta: { submitted: number; graded: number; total_students: number } }>(`/assignments/${id}/submissions`)).data,
    enabled: id !== null,
  });

const refresh = (qc: ReturnType<typeof useQueryClient>) => {
  qc.invalidateQueries({ queryKey: ['employee', 'assignments'] });
  qc.invalidateQueries({ queryKey: ['assignment-submissions'] });
};

export interface AssignmentForm {
  class_id: number; subject_id: number; teacher_id: number; semester_id: number; title: string; description: string; instructions?: string;
  type: string; max_score: number; weight: number; assigned_date: string; due_date: string; allow_late_submission: boolean;
}

export function useCreateAssignment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (v: { form: AssignmentForm; publish: boolean }) => {
      const created = await post<AssignmentRow>('/assignments', v.form);
      if (v.publish) await post(`/assignments/${created.id}/publish`);
      return created;
    },
    onSuccess: () => refresh(qc),
  });
}

export function useAssignmentAction() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (v: { id: number; action: 'publish' | 'close' }) => post(`/assignments/${v.id}/${v.action}`), onSuccess: () => refresh(qc) });
}

export function useGrade() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { assignmentId: number; submissionId: number; score: number; feedback?: string }) =>
      post(`/assignments/${v.assignmentId}/submissions/${v.submissionId}/grade`, { score: v.score, feedback: v.feedback }),
    onSuccess: () => refresh(qc),
  });
}
