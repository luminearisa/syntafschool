import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get, post } from './client';
import { useAuth } from '@/store/auth';

type Row = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export interface CbtCard {
  id: number; title: string; code: string; status: 'scheduled' | 'open' | 'closed'; starts_at: string | null; ends_at: string | null;
  subject: string | null; require_token: boolean; attempt: { id: number; status: string; percentage: number | null; passed: boolean | null } | null;
}
export interface CbtQuestion {
  id: number; type: string; stem: string; points: number; flagged: boolean; response: Row | null;
  options: { key: string; text: string }[]; payload_public: Row;
}
export interface CbtPlayer {
  attempt: { id: number; status: string; started_at: string; deadline_at: string | null; attempt_number: number };
  server_now: string;
  session: { id: number; title: string; lock_navigation: boolean; instructions: string | null };
  questions: CbtQuestion[];
}

export const useCbtSessions = () => {
  const role = useAuth((s) => s.active);
  return useQuery({ queryKey: ['cbt', 'sessions'], queryFn: () => get<CbtCard[]>('/cbt/my/sessions'), enabled: role === 'student', refetchInterval: 60_000 });
};

export const cbtStart = (sessionId: number, token?: string) => post<CbtPlayer>(`/cbt/sessions/${sessionId}/start`, token ? { token } : {});
export const cbtLoad = (attemptId: number) => get<CbtPlayer>(`/cbt/attempts/${attemptId}`);
export const cbtSave = (attemptId: number, questionId: number, body: { response?: Row | null; flagged?: boolean }) =>
  api.put(`/cbt/attempts/${attemptId}/questions/${questionId}`, body);
export const cbtHeartbeat = (attemptId: number) => post<{ status: string; deadline_at: string | null; server_now: string }>(`/cbt/attempts/${attemptId}/heartbeat`);
export const cbtIncident = (attemptId: number, type: 'tab_blur' | 'fullscreen_exit' | 'copy_attempt' | 'manual_flag', note?: string) =>
  post(`/cbt/attempts/${attemptId}/incidents`, { type, note }).catch(() => undefined);
export const cbtSubmit = (attemptId: number) =>
  post<{ status: string; needs_manual_grade: boolean; score: number | null; max_score: number | null; percentage: number | null; passed: boolean | null }>(`/cbt/attempts/${attemptId}/submit`);

export function useInvalidateCbt() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: ['cbt'] });
}
export { useMutation };
