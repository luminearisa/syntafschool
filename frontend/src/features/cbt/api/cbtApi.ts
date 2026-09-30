import { api } from '@/lib/axios';
import type { PaginatedResponse } from '@/types';

export interface CbtQuestion {
  id: number;
  subject_id: number | null;
  question_type: string;
  difficulty: string;
  topic: string | null;
  stem: string;
  points: string | number;
  is_active: boolean;
  version: number;
  payload?: Record<string, unknown> | null;
  options?: { option_key: string; text: string; is_correct?: boolean; match_key?: string | null }[];
}

export interface CbtSessionRow {
  id: number;
  title: string;
  code: string;
  status: string;
  starts_at: string | null;
  ends_at: string | null;
  access_token?: string | null;
  participants_count?: number;
  subject?: { name: string } | null;
  school_class?: { name: string } | null;
  blueprint?: { name: string } | null;
}

export const cbtApi = {
  dashboard: () => api.get('/cbt/dashboard').then((r) => r.data.data),
  questions: (params: Record<string, string | number | undefined>) =>
    api.get<PaginatedResponse<CbtQuestion>>('/cbt/questions', { params }).then((r) => r.data),
  createQuestion: (body: unknown) => api.post('/cbt/questions', body).then((r) => r.data.data),
  importQuestions: (body: unknown) => api.post('/cbt/questions/import', body).then((r) => r.data.data),
  blueprints: () => api.get('/cbt/blueprints').then((r) => r.data),
  createBlueprint: (body: unknown) => api.post('/cbt/blueprints', body).then((r) => r.data.data),
  sessions: (params?: Record<string, string>) =>
    api.get<PaginatedResponse<CbtSessionRow>>('/cbt/sessions', { params }).then((r) => r.data),
  createSession: (body: unknown) => api.post('/cbt/sessions', body).then((r) => r.data.data),
  showSession: (id: number) => api.get(`/cbt/sessions/${id}`).then((r) => r.data.data),
  enroll: (id: number, body: unknown) => api.post(`/cbt/sessions/${id}/enroll`, body).then((r) => r.data.data),
  act: (id: number, action: string) => api.post(`/cbt/sessions/${id}/${action}`).then((r) => r.data.data),
  monitor: (id: number) => api.get(`/cbt/sessions/${id}/monitor`).then((r) => r.data.data),
  report: (id: number) => api.get(`/cbt/sessions/${id}/report`).then((r) => r.data.data),
  mySessions: () => api.get('/cbt/my/sessions').then((r) => r.data.data),
  start: (id: number, token?: string) => api.post(`/cbt/sessions/${id}/start`, { token }).then((r) => r.data.data),
  save: (attemptId: number, questionId: number, response: unknown, flagged?: boolean) =>
    api.put(`/cbt/attempts/${attemptId}/questions/${questionId}`, { response, flagged }).then((r) => r.data.data),
  heartbeat: (attemptId: number) => api.post(`/cbt/attempts/${attemptId}/heartbeat`).then((r) => r.data.data),
  incident: (attemptId: number, type: string, note?: string) =>
    api.post(`/cbt/attempts/${attemptId}/incidents`, { type, note }).then((r) => r.data.data),
  submit: (attemptId: number) => api.post(`/cbt/attempts/${attemptId}/submit`).then((r) => r.data.data),
  grading: () => api.get('/cbt/grading').then((r) => r.data),
  grade: (answerId: number, awarded: number, note?: string) =>
    api.post(`/cbt/answers/${answerId}/grade`, { awarded, note }).then((r) => r.data.data),
  review: (attemptId: number) => api.get(`/cbt/attempts/${attemptId}/review`).then((r) => r.data.data),
  pushScores: (sessionId: number, assessmentTypeId: number) =>
    api.post(`/cbt/sessions/${sessionId}/push-scores`, { assessment_type_id: assessmentTypeId }).then((r) => r.data.data),
};

export const QUESTION_TYPES = [
  { value: 'multiple_choice', label: 'Pilihan ganda' },
  { value: 'multi_select', label: 'Pilih banyak' },
  { value: 'true_false', label: 'Benar / salah' },
  { value: 'short_answer', label: 'Isian singkat' },
  { value: 'numeric', label: 'Numerik' },
  { value: 'matching', label: 'Menjodohkan' },
  { value: 'ordering', label: 'Mengurutkan' },
  { value: 'essay', label: 'Esai' },
];
