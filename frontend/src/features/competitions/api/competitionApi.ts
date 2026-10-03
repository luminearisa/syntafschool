import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  CompetitionAward,
  CompetitionBracketView,
  CompetitionCategory,
  CompetitionDashboard,
  CompetitionDiscoverEdition,
  CompetitionDomainOption,
  CompetitionEdition,
  CompetitionMatch,
  CompetitionMeta,
  CompetitionParticipant,
  CompetitionRegistration,
  CompetitionResult,
  CompetitionRow,
  CompetitionRound,
  CompetitionScorecard,
  CompetitionTypeOption,
  PageResult,
} from '../types';

type RawPage<T> = { data: T[]; current_page: number; last_page: number; total: number };
type Query = Record<string, string | number | boolean | undefined | null>;

function clean(params: Query = {}) {
  return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));
}

async function getData<T>(path: string, params?: Query): Promise<T> {
  const response = await api.get<ApiResponse<T>>(path, { params: clean(params) });
  return response.data.data;
}

async function getPage<T>(path: string, params?: Query): Promise<PageResult<T>> {
  const response = await api.get<ApiResponse<RawPage<T> | T[]>>(path, { params: clean(params) });
  const page = response.data.data;
  if (Array.isArray(page)) return { items: page, currentPage: 1, lastPage: 1, total: page.length };
  return { items: page.data, currentPage: page.current_page, lastPage: page.last_page, total: page.total };
}

async function postData<T>(path: string, payload?: Record<string, unknown>): Promise<T> {
  const response = await api.post<ApiResponse<T>>(path, payload ?? {});
  return response.data.data;
}

export const competitionApi = {
  meta: () => getData<CompetitionMeta>('/competitions/meta'),
  dashboard: () => getData<CompetitionDashboard>('/competitions/dashboard'),
  list: (params: Query = {}) => getPage<CompetitionRow>('/competitions', params),
  detail: (id: number) => getData<{ competition: CompetitionRow; type?: CompetitionTypeOption; domain?: CompetitionDomainOption; editions: CompetitionEdition[] }>(`/competitions/${id}`),
  create: (payload: Record<string, unknown>) => postData<CompetitionRow>('/competitions', payload),
  update: (id: number, payload: Record<string, unknown>) => api.patch<ApiResponse<CompetitionRow>>(`/competitions/${id}`, payload).then((r) => r.data.data),
  editions: (id: number) => getData<CompetitionEdition[]>(`/competitions/${id}/editions`),
  createEdition: (id: number, payload: Record<string, unknown>) => postData<CompetitionEdition>(`/competitions/${id}/editions`, payload),
  edition: (id: number) => getData<CompetitionEdition>(`/competition-editions/${id}`),
  updateEdition: (id: number, payload: Record<string, unknown>) => api.patch<ApiResponse<CompetitionEdition>>(`/competition-editions/${id}`, payload).then((r) => r.data.data),
  publishEdition: (id: number) => postData<CompetitionEdition>(`/competition-editions/${id}/publish`),
  openRegistration: (id: number) => postData<CompetitionEdition>(`/competition-editions/${id}/open-registration`),
  categories: (editionId: number) => getData<CompetitionCategory[]>(`/competition-editions/${editionId}/categories`),
  createCategory: (editionId: number, payload: Record<string, unknown>) => postData<CompetitionCategory>(`/competition-editions/${editionId}/categories`, payload),
  registrations: (editionId: number, params: Query = {}) => getPage<CompetitionRegistration>(`/competition-editions/${editionId}/registrations`, params),
  myRegistrations: (params: Query = {}) => getPage<CompetitionRegistration>('/my-competitions', params),
  discover: (params: Query = {}) => getPage<CompetitionDiscoverEdition>('/competition-discover', params),
  myChildren: () => getData<Array<{ id: number; name?: string; full_name?: string; class_name?: string }>>('/competition-my-children'),
  createRegistration: (editionId: number, payload: Record<string, unknown>) => postData<CompetitionRegistration>(`/competition-editions/${editionId}/registrations`, payload),
  registration: (id: number) => getData<{ registration: CompetitionRegistration; documents: unknown[]; consents: unknown[]; evaluations: unknown[]; history: unknown[] }>(`/competition-registrations/${id}`),
  submitRegistration: (id: number) => postData<CompetitionRegistration>(`/competition-registrations/${id}/submit`),
  evaluateEligibility: (id: number) => postData<{ result: string; reasons: { message?: string; status: string }[] }>(`/competition-registrations/${id}/evaluate-eligibility`),
  decideRegistration: (id: number, decision: 'APPROVED' | 'REJECTED' | 'NEEDS_REVISION', reason?: string, override = false) =>
    postData<CompetitionRegistration>(`/competition-registrations/${id}/${decision === 'APPROVED' ? 'approve' : decision === 'REJECTED' ? 'reject' : 'needs-revision'}`, { decision, reason, override }),
  participants: (editionId: number, params: Query = {}) => getPage<CompetitionParticipant>(`/competition-editions/${editionId}/participants`, params),
  teams: (editionId: number) => getPage<Record<string, unknown>>(`/competition-editions/${editionId}/teams`),
  createTeam: (editionId: number, payload: Record<string, unknown>) => postData<Record<string, unknown>>(`/competition-editions/${editionId}/teams`, payload),
  lockRoster: (teamId: number) => postData<Record<string, unknown>>(`/competition-teams/${teamId}/lock-roster`),
  rounds: (editionId: number, categoryId?: number) => getData<CompetitionRound[]>(`/competition-editions/${editionId}/rounds`, { category_id: categoryId }),
  createRound: (editionId: number, payload: Record<string, unknown>) => postData<CompetitionRound>(`/competition-editions/${editionId}/rounds`, payload),
  generateFixtures: (roundId: number, payload: Record<string, unknown>) => postData<{ matches: CompetitionMatch[]; participants: number; format: string; bracket?: unknown }>(`/competition-rounds/${roundId}/generate-fixtures`, payload),
  bracket: (roundId: number) => getData<{ bracket: CompetitionBracketView | null; slots: Array<Record<string, unknown>>; matches: CompetitionMatch[] }>(`/competition-rounds/${roundId}/bracket`),
  matches: (editionId: number, params: Query = {}) => getPage<CompetitionMatch>(`/competition-editions/${editionId}/matches`, params),
  match: (matchId: number) => getData<{ match: CompetitionMatch; round: CompetitionRound; edition: CompetitionEdition; category: CompetitionCategory; home_participant?: CompetitionParticipant | null; away_participant?: CompetitionParticipant | null; rubric?: { id: number; name: string; competition_rubric_criterions: Array<{ id: number; name: string; weight: number | string; min_score: number | string; max_score: number | string; is_required: boolean; required_comment: boolean }> } | null }>(`/competition-matches/${matchId}`),
  saveScores: (matchId: number, payload: Record<string, unknown>) => postData<CompetitionScorecard>(`/competition-matches/${matchId}/scorecards`, payload),
  scheduleMatch: (id: number, payload: Record<string, unknown>) => api.patch<ApiResponse<CompetitionMatch>>(`/competition-matches/${id}/schedule`, payload).then((r) => r.data.data),
  startMatch: (id: number) => postData<CompetitionMatch>(`/competition-matches/${id}/start`),
  completeMatch: (id: number) => postData<CompetitionMatch>(`/competition-matches/${id}/complete`),
  judges: () => getPage<Record<string, unknown>>('/competition-judges'),
  createJudge: (payload: Record<string, unknown>) => postData<Record<string, unknown>>('/competition-judges', payload),
  assignJudge: (editionId: number, payload: Record<string, unknown>) => postData<Record<string, unknown>>(`/competition-editions/${editionId}/judge-assignments`, payload),
  judgeAssignments: () => getPage<Record<string, unknown>>('/judge/assignments'),
  checkIn: (editionId: number, payload: Record<string, unknown>) => postData<Record<string, unknown>>(`/competition-editions/${editionId}/checkins`, payload),
  scorecards: (editionId: number, params: Query = {}) => getPage<CompetitionScorecard>(`/competition-editions/${editionId}/scorecards`, params),
  saveScorecard: (matchId: number, payload: Record<string, unknown>) => postData<CompetitionScorecard>(`/competition-matches/${matchId}/scorecards`, payload),
  submitScorecard: (id: number) => postData<CompetitionScorecard>(`/competition-scorecards/${id}/submit`),
  finalizeScorecard: (id: number, review_note?: string) => postData<CompetitionScorecard>(`/competition-scorecards/${id}/finalize`, { review_note }),
  results: (editionId: number, params: Query = {}) => getPage<CompetitionResult>(`/competition-editions/${editionId}/results`, params),
  finalizeResults: (editionId: number, category_id: number, round_id?: number) => postData<Record<string, unknown>>(`/competition-editions/${editionId}/results/finalize`, { category_id, round_id }),
  publishResults: (editionId: number, category_id?: number) => postData<Record<string, unknown>>(`/competition-editions/${editionId}/results/publish`, { category_id }),
  awards: (editionId: number) => getData<CompetitionAward[]>(`/competition-editions/${editionId}/awards`),
  createAward: (editionId: number, payload: Record<string, unknown>) => postData<CompetitionAward>(`/competition-editions/${editionId}/awards`, payload),
  assignAward: (awardId: number, resultId: number) => postData<Record<string, unknown>>(`/competition-awards/${awardId}/results/${resultId}`),
  generateCertificate: (recipientId: number) => postData<Record<string, unknown>>(`/competition-award-recipients/${recipientId}/certificate`),
};
