import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get, post } from './client';
import { useAuth } from '@/store/auth';

type Row = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export const useMyEmployeeId = () => useAuth((s) => s.contexts.find((c) => c.type === 'employee')?.id ?? null);

export const useVisits = () => {
  const role = useAuth((s) => s.active);
  return useQuery({
    queryKey: ['supervisi', 'visits'],
    queryFn: async () => { const r = await get<Row>('/supervisi/visits', { per_page: 50 }); return (r.data ?? r) as Row[]; },
    enabled: role === 'employee',
  });
};
export const useVisit = (id: number | null) =>
  useQuery({ queryKey: ['supervisi', 'visit', id], queryFn: () => get<Row>(`/supervisi/visits/${id}`), enabled: !!id });

export const saveAnswers = (id: number, answers: Row[]) => api.put(`/supervisi/visits/${id}/answers`, { answers }).then((r) => r.data?.data as Row);

export function useSubmitVisit() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: number) => post(`/supervisi/visits/${id}/submit`), onSuccess: () => qc.invalidateQueries({ queryKey: ['supervisi'] }) });
}
export function useAcknowledge() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (v: { id: number; note?: string }) => post(`/supervisi/visits/${v.id}/acknowledge`, { note: v.note }), onSuccess: () => qc.invalidateQueries({ queryKey: ['supervisi'] }) });
}
