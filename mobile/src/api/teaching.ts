import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get, post } from './client';
import { useAuth } from '@/store/auth';

const useRole = () => useAuth((s) => s.active ?? 'none');

export interface MyClasses {
  semester: { id: number; name: string; academic_year_id: number } | null;
  classes: { class_id: number; class_name: string; is_homeroom: boolean; subjects: { subject_id: number; subject_name: string }[] }[];
}

export const useMyClasses = () => {
  const role = useRole();
  return useQuery({ queryKey: [role, 'my-classes'], queryFn: () => get<MyClasses>('/mobile/employee/classes'), enabled: role === 'employee', staleTime: 5 * 60_000 });
};

// ---------------------------------------------------------------- Jurnal
export interface Journal {
  id: number;
  schedule_id: number | null;
  class_id: number;
  subject_id: number;
  semester_id: number;
  date: string;
  topic: string;
  material?: string | null;
  activities?: string | null;
  notes?: string | null;
  student_present_count: number;
  realized_jp: number;
  status: 'planned' | 'ongoing' | 'completed' | 'cancelled';
  is_completed: boolean;
}

export interface JournalToday {
  date: string;
  semester_id: number;
  total: number;
  journaled: number;
  pending: number;
  items: {
    schedule_id: number;
    class_id: number;
    subject_id: number;
    time_slot_id: number | null;
    start_time: string | null;
    end_time: string | null;
    school_class: { id: number; name: string } | null;
    subject: { id: number; name: string } | null;
    has_journal: boolean;
    journal: Journal | null;
  }[];
}

export const useJournalToday = (date: string) => {
  const role = useRole();
  return useQuery({ queryKey: [role, 'journal-today', date], queryFn: () => get<JournalToday>('/teaching-journals/today', { date }), enabled: role === 'employee' });
};

export interface JournalForm {
  schedule_id: number;
  class_id: number;
  subject_id: number;
  semester_id: number;
  time_slot_id?: number | null;
  date: string;
  topic: string;
  material?: string;
  activities?: string;
  notes?: string;
  student_present_count?: number;
  realized_jp?: number;
}

export function useSaveJournal() {
  const qc = useQueryClient();
  return useMutation({
    /** Buat (atau perbarui) jurnal; bila `complete` diisi, langsung ditandai selesai. */
    mutationFn: async (v: { existingId?: number; form: JournalForm; complete?: { realized_jp: number } }) => {
      let id = v.existingId;
      if (id) {
        await api.put(`/teaching-journals/${id}`, v.form); // status dibiarkan; "selesai" lewat /complete
      } else {
        id = (await post<Journal>('/teaching-journals', { ...v.form, status: 'ongoing' })).id;
      }
      if (v.complete) {
        await post(`/teaching-journals/${id}/complete`, {
          realized_jp: v.complete.realized_jp,
          student_present_count: v.form.student_present_count,
          activities: v.form.activities,
          notes: v.form.notes,
        });
      }
      return id;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee', 'journal-today'] });
      qc.invalidateQueries({ queryKey: ['employee', 'employee-home'] });
    },
  });
}

// ------------------------------------------------------------------- RPP
export interface LessonPlan {
  id: number;
  code: string;
  title: string;
  subject_id: number;
  class_id: number | null;
  semester_id: number;
  learning_objectives: string[];
  activities: string[];
  assessment_plan?: string | null;
  media?: string | null;
  references?: string | null;
  duration_jp: number;
  status: 'draft' | 'submitted' | 'approved' | 'rejected';
  rejection_reason?: string | null;
  is_editable: boolean;
  subject?: { id: number; name: string } | null;
  school_class?: { id: number; name: string } | null;
  teacher?: { id: number; full_name: string } | null;
}

export const useLessonPlans = (scope: 'mine' | 'review') => {
  const role = useRole();
  const employeeId = useEmployeeId();
  return useQuery({
    queryKey: [role, 'lesson-plans', scope, employeeId],
    queryFn: async () => {
      const filter = scope === 'mine' ? { teacher_id: employeeId } : { status: 'submitted' };
      const res = await api.get<{ data: LessonPlan[] }>('/lesson-plans', { params: { filter, per_page: 50 } });
      return res.data.data;
    },
    enabled: role === 'employee' && (scope === 'review' || employeeId !== null),
  });
};

/** ID pegawai dari konteks aktif (daftar peran dibawa saat login). */
export function useEmployeeId(): number | null {
  return useAuth((s) => s.contexts.find((c) => c.type === 'employee')?.id ?? null);
}

export interface LessonPlanForm {
  subject_id: number;
  class_id?: number | null;
  semester_id: number;
  code: string;
  title: string;
  learning_objectives: string[];
  activities: string[];
  assessment_plan?: string;
  duration_jp: number;
  notes?: string;
}

export function useSaveLessonPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (v: { id?: number; form: LessonPlanForm; submit?: boolean }) => {
      const res = v.id ? await api.put<{ data: LessonPlan }>(`/lesson-plans/${v.id}`, v.form) : await api.post<{ data: LessonPlan }>('/lesson-plans', v.form);
      const id = res.data.data.id;
      if (v.submit) await post(`/lesson-plans/${id}/submit`);
      return id;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['employee', 'lesson-plans'] }),
  });
}

export function useLessonPlanAction() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; action: 'submit' | 'approve' | 'reject'; reason?: string }) =>
      post(`/lesson-plans/${v.id}/${v.action}`, v.action === 'reject' ? { rejection_reason: v.reason } : undefined),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['employee', 'lesson-plans'] }),
  });
}
