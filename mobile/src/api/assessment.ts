import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get, post } from './client';
import { useAuth } from '@/store/auth';
import { useEmployeeId } from './teaching';

const useRole = () => useAuth((s) => s.active ?? 'none');

export interface AssessmentRow {
  id: number;
  class_id: number;
  subject_id: number;
  semester_id: number;
  name: string;
  max_score: number;
  date: string | null;
  status: 'draft' | 'submitted' | 'verified' | 'finalized' | 'revision_requested';
  type?: { id: number; name: string } | null;
  school_class?: { id: number; name: string } | null;
  subject?: { id: number; name: string } | null;
  scores_count?: number;
  graded_count?: number;
}

export interface AssessmentSheet {
  assessment: AssessmentRow;
  max_score: number;
  passing_grade: number | null;
  is_locked: boolean;
  total: number;
  graded: number;
  pending: number;
  items: { student_id: number; nis: string; full_name: string; score: number | null; grade: string | null; notes: string | null; graded: boolean }[];
}

export const useMyAssessments = () => {
  const role = useRole();
  const employeeId = useEmployeeId();
  return useQuery({
    queryKey: [role, 'assessments', employeeId],
    queryFn: async () => (await api.get<{ data: AssessmentRow[] }>('/assessments', { params: { filter: { teacher_id: employeeId }, per_page: 50 } })).data.data,
    enabled: role === 'employee' && employeeId !== null,
  });
};

export const useAssessmentTypes = () => {
  const role = useRole();
  return useQuery({ queryKey: [role, 'assessment-types'], queryFn: async () => (await get<{ types: { id: number; name: string; category: string }[] }>('/assessment-lookups')).types, enabled: role === 'employee', staleTime: 30 * 60_000 });
};

export const useAssessmentSheet = (id: number | null) =>
  useQuery({ queryKey: ['assessment-sheet', id], queryFn: () => get<AssessmentSheet>(`/assessments/${id}/sheet`), enabled: id !== null });

const refresh = (qc: ReturnType<typeof useQueryClient>) => {
  qc.invalidateQueries({ queryKey: ['assessment-sheet'] });
  qc.invalidateQueries({ queryKey: ['employee', 'assessments'] });
};

export function useCreateAssessment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (b: { class_id: number; subject_id: number; semester_id: number; assessment_type_id: number; name: string; max_score: number; date?: string }) => post<AssessmentRow>('/assessments', b),
    onSuccess: () => refresh(qc),
  });
}

export function useSaveScores() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; entries: { student_id: number; score: number | null; notes?: string }[]; reason?: string }) =>
      post<{ created: number; updated: number; total: number }>(`/assessments/${v.id}/scores/bulk`, { entries: v.entries, reason: v.reason }),
    onSuccess: () => refresh(qc),
  });
}

export function useSubmitAssessment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; force?: boolean; reason?: string }) => post(`/assessments/${v.id}/submit`, { force: v.force ?? false, reason: v.reason }),
    onSuccess: () => refresh(qc),
  });
}
