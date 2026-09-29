import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  AcademicIntegrationStatus,
  AssessmentResult,
  ChildGradesResponse,
  Gradebook,
  GradebookReleasesResponse,
  GradeItem,
  GradeItemStatistics,
  GradeSheetResponse,
  GradeReview,
  LearningOutcome,
  MyGradesResponse,
  OutcomeAchievementRow,
  Rubric,
  RubricAssessment,
  StudentPerformanceResponse,
} from '../types.phase3';

/**
 * Klien API Phase 3 (§54) — gradebook, penilaian, rubric, outcome, competency,
 * banding nilai, dan integrasi akademik.
 *
 * Komponen **tidak pernah** menghitung nilai akhir sendiri: seluruh angka
 * berasal dari `GradeCalculationService` di server (§14).
 */
const unwrap = async <T>(promise: Promise<{ data: ApiResponse<T> }>): Promise<T> => {
  const response = await promise;

  return response.data.data;
};

export const gradebookApi = {
  // ---------------------------------------------------------------- gradebook
  show: (courseId: number) =>
    unwrap<{ gradebook: Gradebook; items: GradeItem[]; sources: unknown[]; scales: unknown[] }>(
      api.get(`/lms/courses/${courseId}/gradebook`),
    ),
  update: (courseId: number, payload: Record<string, unknown>) =>
    unwrap<Gradebook>(api.put(`/lms/courses/${courseId}/gradebook`, payload)),
  freeze: (courseId: number) => unwrap<Gradebook>(api.post(`/lms/courses/${courseId}/gradebook/freeze`)),
  unfreeze: (courseId: number) => unwrap<Gradebook>(api.post(`/lms/courses/${courseId}/gradebook/unfreeze`)),
  sheet: (courseId: number, params: Record<string, unknown> = {}) =>
    unwrap<GradeSheetResponse>(api.get(`/lms/courses/${courseId}/gradebook/sheet`, { params })),
  calculate: (courseId: number) =>
    unwrap<{ calculated_at: string | null; students: unknown[] }>(api.post(`/lms/courses/${courseId}/gradebook/calculate`)),
  statistics: (courseId: number) =>
    unwrap<{ summary: unknown; performance: unknown }>(api.get(`/lms/courses/${courseId}/gradebook/statistics`)),
  syncItems: (courseId: number) =>
    unwrap<{ created_items: number; pulled_results: number }>(api.post(`/lms/courses/${courseId}/gradebook/sync-items`)),
  exportUrl: (courseId: number, params: Record<string, unknown> = {}) => {
    const query = new URLSearchParams(
      Object.entries(params).reduce<Record<string, string>>((acc, [key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
          acc[key] = String(value);
        }

        return acc;
      }, {}),
    );

    return `/api/v1/lms/courses/${courseId}/gradebook/export?${query.toString()}`;
  },

  // ------------------------------------------------------ kategori & komponen
  storeCategory: (courseId: number, payload: Record<string, unknown>) =>
    unwrap<{ category: { id: number } }>(api.post(`/lms/courses/${courseId}/gradebook/categories`, payload)),
  updateCategory: (categoryId: number, payload: Record<string, unknown>) =>
    unwrap<{ category: { id: number } }>(api.put(`/lms/grade-categories/${categoryId}`, payload)),
  deleteCategory: async (categoryId: number) => {
    await api.delete(`/lms/grade-categories/${categoryId}`);
  },
  items: (courseId: number) => unwrap<GradeItem[]>(api.get(`/lms/courses/${courseId}/gradebook/items`)),
  storeItem: (courseId: number, payload: Record<string, unknown>) =>
    unwrap<{ item: GradeItem }>(api.post(`/lms/courses/${courseId}/gradebook/items`, payload)),
  updateItem: (itemId: number, payload: Record<string, unknown>) =>
    unwrap<{ item: GradeItem }>(api.put(`/lms/grade-items/${itemId}`, payload)),
  deleteItem: async (itemId: number) => {
    await api.delete(`/lms/grade-items/${itemId}`);
  },

  // --------------------------------------------------------------- skala nilai
  scales: (courseId: number) => unwrap<unknown[]>(api.get(`/lms/courses/${courseId}/grade-scales`)),
  storeScale: (payload: Record<string, unknown>) => unwrap<{ id: number }>(api.post('/lms/grade-scales', payload)),

  // -------------------------------------------------------- nilai per komponen
  itemGrades: (itemId: number) =>
    unwrap<{ item: GradeItem; students: unknown[]; statistics: GradeItemStatistics }>(api.get(`/lms/grade-items/${itemId}/grades`)),
  saveGrade: (itemId: number, studentId: number, payload: Record<string, unknown>) =>
    unwrap<AssessmentResult>(api.put(`/lms/grade-items/${itemId}/grades/${studentId}`, payload)),
  bulkGrades: (itemId: number, entries: { user_id: number; score: number | null; feedback?: string | null }[]) =>
    unwrap<AssessmentResult[]>(api.post(`/lms/grade-items/${itemId}/grades/bulk`, { entries })),
  exempt: (itemId: number, studentId: number, reason: string) =>
    unwrap<AssessmentResult>(api.post(`/lms/grade-items/${itemId}/grades/${studentId}/exempt`, { reason })),
  unexempt: (itemId: number, studentId: number) =>
    unwrap<AssessmentResult>(api.delete(`/lms/grade-items/${itemId}/grades/${studentId}/exempt`)),
  returnGrades: (itemId: number, userIds: number[]) =>
    unwrap<{ returned: number }>(api.post(`/lms/grade-items/${itemId}/grades/return`, { user_ids: userIds })),
  correct: (resultId: number, score: number, reason: string) =>
    unwrap<{ previous: number | null; corrected: number | null }>(
      api.post(`/lms/assessment-results/${resultId}/correct`, { score, reason }),
    ),
  history: (resultId: number) => unwrap<unknown[]>(api.get(`/lms/assessment-results/${resultId}/history`)),

  // ------------------------------------------------------------------- rilis
  release: (courseId: number, payload: { scope: 'individual' | 'selected' | 'all'; user_ids?: number[]; user_id?: number }) =>
    unwrap<{ released: unknown[]; skipped: unknown[] }>(api.post(`/lms/courses/${courseId}/gradebook/release`, payload)),
  releases: (courseId: number) => unwrap<GradebookReleasesResponse>(api.get(`/lms/courses/${courseId}/gradebook/releases`)),
  revokeRelease: (releaseId: number, reason?: string) =>
    unwrap<unknown>(api.post(`/lms/grade-releases/${releaseId}/revoke`, { reason })),
  lockRelease: (releaseId: number, reason?: string) =>
    unwrap<unknown>(api.post(`/lms/grade-releases/${releaseId}/lock`, { reason })),
  unlockRelease: (releaseId: number, reason: string) =>
    unwrap<unknown>(api.post(`/lms/grade-releases/${releaseId}/unlock`, { reason })),

  // ------------------------------------------------------- integrasi akademik
  integration: () => unwrap<AcademicIntegrationStatus>(api.get('/lms/integration/academic')),
  sync: (courseId: number, userIds?: number[]) =>
    unwrap<{ synced: number; skipped: number; failed: number; available: boolean; provider: string }>(
      api.post(`/lms/courses/${courseId}/grades/sync`, { user_ids: userIds }),
    ),
  payload: (courseId: number, studentId: number) =>
    unwrap<{ payload: Record<string, unknown>; provider: AcademicIntegrationStatus }>(
      api.get(`/lms/courses/${courseId}/grades/payload/${studentId}`),
    ),

  // ------------------------------------------------------------ tampilan nilai
  myGrades: (userId?: number) =>
    unwrap<MyGradesResponse>(api.get('/lms/my/grades', { params: userId === undefined ? {} : { user_id: userId } })),
  myPerformance: (userId?: number) =>
    unwrap<StudentPerformanceResponse>(api.get('/lms/my/performance', { params: userId === undefined ? {} : { user_id: userId } })),
  myOutcomes: (courseId: number) =>
    unwrap<OutcomeAchievementRow[]>(api.get('/lms/my/outcomes', { params: { course_id: courseId } })),
  myCompetencies: (courseId: number) =>
    unwrap<unknown[]>(api.get('/lms/my/competencies', { params: { course_id: courseId } })),
  courseGrades: (courseId: number, userId?: number) =>
    unwrap<unknown>(api.get(`/lms/courses/${courseId}/grades`, { params: userId === undefined ? {} : { user_id: userId } })),
  childGrades: (childId: number) => unwrap<ChildGradesResponse>(api.get(`/lms/children/${childId}/grades`)),

  // ------------------------------------------------------------------- rubric
  rubrics: (courseId: number) =>
    unwrap<{ rubrics: Rubric[]; templates: Rubric[] }>(api.get(`/lms/courses/${courseId}/rubrics`)),
  templates: () => unwrap<Rubric[]>(api.get('/lms/rubric-templates')),
  createRubric: (payload: Record<string, unknown>) => unwrap<Rubric>(api.post('/lms/rubrics', payload)),
  updateRubric: (rubricId: number, payload: Record<string, unknown>) => unwrap<Rubric>(api.put(`/lms/rubrics/${rubricId}`, payload)),
  archiveRubric: (rubricId: number) => unwrap<Rubric>(api.post(`/lms/rubrics/${rubricId}/archive`)),
  duplicateRubric: (rubricId: number, payload: Record<string, unknown>) =>
    unwrap<Rubric>(api.post(`/lms/rubrics/${rubricId}/duplicate`, payload)),
  attachRubric: (itemId: number, rubricId: number | null) =>
    unwrap<{ rubric: number | null }>(api.put(`/lms/grade-items/${itemId}/rubric`, { rubric_id: rubricId })),
  rubricAssessment: (itemId: number, studentId: number) =>
    unwrap<{ rubric: Rubric | null; assessment: RubricAssessment | null }>(
      api.get(`/lms/grade-items/${itemId}/rubric/${studentId}`),
    ),
  assessRubric: (itemId: number, studentId: number, payload: Record<string, unknown>) =>
    unwrap<{ breakdown: unknown[]; total: number; maximum: number; normalized: number; assessment: RubricAssessment | null }>(
      api.post(`/lms/grade-items/${itemId}/rubric/${studentId}`, payload),
    ),

  // ------------------------------------------------------- outcome & kompetensi
  outcomes: (courseId: number) =>
    unwrap<{ outcomes: LearningOutcome[]; coverage: unknown; achievement: unknown[] }>(api.get(`/lms/courses/${courseId}/outcomes`)),
  createOutcome: (courseId: number, payload: Record<string, unknown>) =>
    unwrap<LearningOutcome>(api.post(`/lms/courses/${courseId}/outcomes`, payload)),
  updateOutcome: (outcomeId: number, payload: Record<string, unknown>) =>
    unwrap<LearningOutcome>(api.put(`/lms/outcomes/${outcomeId}`, payload)),
  deleteOutcome: async (outcomeId: number) => {
    await api.delete(`/lms/outcomes/${outcomeId}`);
  },
  mapOutcome: (outcomeId: number, activityIds: number[]) =>
    unwrap<LearningOutcome[]>(api.post(`/lms/outcomes/${outcomeId}/map`, { activity_ids: activityIds })),
  recalculateOutcomes: (courseId: number) =>
    unwrap<{ rows: unknown[]; achievement: unknown[] }>(api.post(`/lms/courses/${courseId}/outcomes/recalculate`)),
  studentOutcomes: (courseId: number, studentId: number) =>
    unwrap<OutcomeAchievementRow[]>(api.get(`/lms/courses/${courseId}/outcomes/students/${studentId}`)),

  frameworks: () => unwrap<unknown[]>(api.get('/lms/competency-frameworks')),
  createFramework: (payload: Record<string, unknown>) => unwrap<unknown>(api.post('/lms/competency-frameworks', payload)),
  createCompetency: (frameworkId: number, payload: Record<string, unknown>) =>
    unwrap<unknown>(api.post(`/lms/competency-frameworks/${frameworkId}/competencies`, payload)),
  courseCompetencies: (courseId: number) =>
    unwrap<{ competencies: unknown[]; progress: unknown[] }>(api.get(`/lms/courses/${courseId}/competencies`)),
  mapCourseCompetencies: (courseId: number, competencyIds: number[]) =>
    unwrap<unknown[]>(api.post(`/lms/courses/${courseId}/competencies/map`, { competency_ids: competencyIds })),
  mapActivityCompetencies: (activityId: number, competencyIds: number[]) =>
    unwrap<unknown[]>(api.post(`/lms/activities/${activityId}/competencies/map`, { competency_ids: competencyIds })),
  assessCompetency: (competencyId: number, studentId: number, payload: Record<string, unknown>) =>
    unwrap<unknown>(api.post(`/lms/competencies/${competencyId}/students/${studentId}`, payload)),
  competencyEvidence: (competencyId: number, studentId: number, payload: Record<string, unknown>) =>
    unwrap<unknown>(api.post(`/lms/competencies/${competencyId}/students/${studentId}/evidence`, payload)),
  studentCompetencies: (courseId: number, studentId: number) =>
    unwrap<unknown[]>(api.get(`/lms/courses/${courseId}/competencies/students/${studentId}`)),

  // -------------------------------------------------------------- banding nilai
  reviews: (params: { course_id?: number; grade_item_id?: number } = {}) =>
    unwrap<GradeReview[]>(api.get('/lms/grade-reviews', { params })),
  requestReview: (itemId: number, payload: { reason: string; requested_score?: number | null }) =>
    unwrap<GradeReview>(api.post(`/lms/grade-items/${itemId}/grade-reviews`, payload)),
  showReview: (reviewId: number) => unwrap<GradeReview>(api.get(`/lms/grade-reviews/${reviewId}`)),
  startReview: (reviewId: number) => unwrap<GradeReview>(api.post(`/lms/grade-reviews/${reviewId}/start`)),
  approveReview: (reviewId: number, score: number, note: string) =>
    unwrap<{ review: GradeReview; previous: number | null; corrected: number | null }>(
      api.post(`/lms/grade-reviews/${reviewId}/approve`, { score, note }),
    ),
  rejectReview: (reviewId: number, note: string) => unwrap<GradeReview>(api.post(`/lms/grade-reviews/${reviewId}/reject`, { note })),
  closeReview: (reviewId: number, note?: string) => unwrap<GradeReview>(api.post(`/lms/grade-reviews/${reviewId}/close`, { note })),
  commentReview: (reviewId: number, note: string) => unwrap<GradeReview>(api.post(`/lms/grade-reviews/${reviewId}/comments`, { note })),
};
