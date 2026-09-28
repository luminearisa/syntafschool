import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  ActivityGradeRecord,
  ActivityTypeOption,
  AssignmentOverview,
  AssignmentStudentView,
  AssignmentSubmission,
  ChoiceResults,
  ChoiceView,
  CourseGradeSummary,
  ForumDiscussionView,
  ForumView,
  LessonView,
  LmsActivity,
  LmsActivityShowPayload,
  QuestionBank,
  QuestionBankTree,
  QuestionPayload,
  QuizAttemptPayload,
  QuizBuilderPayload,
  QuizStatistics,
  QuizStudentView,
  SurveyResults,
  SurveyView,
} from '../types.phase2';

/**
 * Klien API aktivitas Phase 2.
 *
 * Dipisah dari `lmsApi.ts` (Phase 1) agar modul aktivitas dapat dibaca utuh.
 * Semua endpoint berada di bawah `/lms/*` dan sudah dijaga izin di backend.
 */
export const activityApi = {
  // ---------------------------------------------------------------- aktivitas
  async types() {
    const response = await api.get<ApiResponse<{ types: ActivityTypeOption[]; later: ActivityTypeOption[] }>>('/lms/activity-types');
    return response.data.data;
  },

  /** Daftar aktivitas sebuah course (bentuk paginasi lengkap dengan `meta`). */
  async listForCourse(courseId: number, params: { type?: string; status?: string; section_id?: number } = {}) {
    const response = await api.get<PaginatedResponse<LmsActivity>>(`/lms/courses/${courseId}/activities`, { params });
    return response.data;
  },

  async show(activityId: number) {
    const response = await api.get<ApiResponse<LmsActivityShowPayload>>(`/lms/activities/${activityId}`);
    return response.data.data;
  },

  async create(sectionId: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<LmsActivity>>(`/lms/sections/${sectionId}/activities`, payload);
    return response.data.data;
  },

  async update(activityId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<LmsActivity>>(`/lms/activities/${activityId}`, payload);
    return response.data.data;
  },

  async remove(activityId: number) {
    await api.delete(`/lms/activities/${activityId}`);
  },

  async publish(activityId: number) {
    const response = await api.post<ApiResponse<LmsActivity>>(`/lms/activities/${activityId}/publish`);
    return response.data.data;
  },

  async unpublish(activityId: number) {
    const response = await api.post<ApiResponse<LmsActivity>>(`/lms/activities/${activityId}/unpublish`);
    return response.data.data;
  },

  async archive(activityId: number) {
    const response = await api.post<ApiResponse<LmsActivity>>(`/lms/activities/${activityId}/archive`);
    return response.data.data;
  },

  async duplicate(activityId: number, title?: string) {
    const response = await api.post<ApiResponse<LmsActivity>>(`/lms/activities/${activityId}/duplicate`, { title });
    return response.data.data;
  },

  // --------------------------------------------------------------- assignment
  async assignment(activityId: number) {
    const response = await api.get<ApiResponse<AssignmentStudentView>>(`/lms/activities/${activityId}/assignment`);
    return response.data.data;
  },

  async updateAssignment(activityId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<Record<string, unknown>>>(`/lms/activities/${activityId}/assignment`, payload);
    return response.data.data;
  },

  async assignmentOverview(activityId: number, params: { status?: string; late?: string; graded?: string } = {}) {
    const response = await api.get<ApiResponse<AssignmentOverview>>(`/lms/activities/${activityId}/assignment/overview`, { params });
    return response.data.data;
  },

  async saveDraft(activityId: number, text_content: string) {
    const response = await api.post<ApiResponse<AssignmentSubmission>>(`/lms/activities/${activityId}/submissions/draft`, { text_content });
    return response.data.data;
  },

  async submitAssignment(activityId: number, text_content: string) {
    const response = await api.post<ApiResponse<AssignmentSubmission>>(`/lms/activities/${activityId}/submissions/submit`, { text_content });
    return response.data.data;
  },

  async uploadSubmissionFile(submissionId: number, file: File) {
    const form = new FormData();
    form.append('file', file);
    const response = await api.post<ApiResponse<{ id: number; name: string }>>(`/lms/submissions/${submissionId}/files`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data.data;
  },

  async deleteSubmissionFile(fileId: number) {
    await api.delete(`/lms/submission-files/${fileId}`);
  },

  submissionFileUrl(fileId: number) {
    return `/api/v1/lms/submission-files/${fileId}`;
  },

  async gradeSubmission(submissionId: number, payload: { grade: number; feedback?: string | null }) {
    const response = await api.post<ApiResponse<AssignmentSubmission>>(`/lms/submissions/${submissionId}/grade`, payload);
    return response.data.data;
  },

  async returnSubmission(submissionId: number, reason: string) {
    const response = await api.post<ApiResponse<AssignmentSubmission>>(`/lms/submissions/${submissionId}/return`, { reason });
    return response.data.data;
  },

  // --------------------------------------------------------------------- quiz
  async quiz(activityId: number) {
    const response = await api.get<ApiResponse<QuizStudentView>>(`/lms/activities/${activityId}/quiz`);
    return response.data.data;
  },

  async quizBuilder(activityId: number) {
    const response = await api.get<ApiResponse<QuizBuilderPayload>>(`/lms/activities/${activityId}/quiz/builder`);
    return response.data.data;
  },

  async updateQuiz(activityId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<QuizBuilderPayload>>(`/lms/activities/${activityId}/quiz`, payload);
    return response.data.data;
  },

  async addQuizQuestions(activityId: number, questions: Array<Record<string, unknown>>) {
    const response = await api.post<ApiResponse<QuizBuilderPayload>>(`/lms/activities/${activityId}/quiz/questions`, { questions });
    return response.data.data;
  },

  async updateQuizSlot(slotId: number, marks: number) {
    const response = await api.put<ApiResponse<QuizBuilderPayload>>(`/lms/quiz-slots/${slotId}`, { marks });
    return response.data.data;
  },

  async removeQuizSlot(slotId: number) {
    await api.delete(`/lms/quiz-slots/${slotId}`);
  },

  async quizStatistics(activityId: number) {
    const response = await api.get<ApiResponse<QuizStatistics>>(`/lms/activities/${activityId}/quiz/statistics`);
    return response.data.data;
  },

  async quizAttempts(activityId: number, params: { status?: string } = {}) {
    const response = await api.get<PaginatedResponse<Record<string, unknown>>>(`/lms/activities/${activityId}/quiz/attempts`, { params });
    return response.data;
  },

  async attemptDetail(attemptId: number) {
    const response = await api.get<ApiResponse<{ attempt: Record<string, unknown>; questions: Array<Record<string, unknown>> }>>(
      `/lms/attempts/${attemptId}/detail`,
    );
    return response.data.data;
  },

  async startAttempt(activityId: number, password?: string) {
    const response = await api.post<ApiResponse<QuizAttemptPayload>>(`/lms/activities/${activityId}/quiz/attempts`, { password });
    return response.data.data;
  },

  async attempt(attemptId: number) {
    const response = await api.get<ApiResponse<QuizAttemptPayload>>(`/lms/attempts/${attemptId}`);
    return response.data.data;
  },

  async saveAnswer(attemptId: number, questionId: number, answerData: Record<string, unknown>, text?: string) {
    const response = await api.post<ApiResponse<{ question_id: number; seconds_remaining: number | null }>>(
      `/lms/attempts/${attemptId}/answers`,
      { question_id: questionId, answer_data: answerData, text },
    );
    return response.data.data;
  },

  async submitAttempt(attemptId: number) {
    const response = await api.post<ApiResponse<QuizAttemptPayload>>(`/lms/attempts/${attemptId}/submit`);
    return response.data.data;
  },

  async attemptResult(attemptId: number) {
    const response = await api.get<ApiResponse<QuizAttemptPayload>>(`/lms/attempts/${attemptId}/result`);
    return response.data.data;
  },

  async gradeQuizAnswer(answerId: number, marks: number, feedback?: string) {
    const response = await api.post<ApiResponse<Record<string, unknown>>>(`/lms/quiz-answers/${answerId}/grade`, { marks, feedback });
    return response.data.data;
  },

  // ------------------------------------------------------------- bank soal
  async questionBanks(params: { course_id?: number } = {}) {
    const response = await api.get<ApiResponse<QuestionBank[]>>('/lms/question-banks', { params });
    return response.data.data;
  },

  async createQuestionBank(payload: { name: string; description?: string; course_id?: number | null; visibility?: string }) {
    const response = await api.post<ApiResponse<{ id: number; name: string }>>('/lms/question-banks', payload);
    return response.data.data;
  },

  async questionBank(bankId: number) {
    const response = await api.get<ApiResponse<QuestionBankTree>>(`/lms/question-banks/${bankId}`);
    return response.data.data;
  },

  async deleteQuestionBank(bankId: number) {
    await api.delete(`/lms/question-banks/${bankId}`);
  },

  async createQuestionCategory(bankId: number, payload: { name: string; parent_id?: number | null; description?: string }) {
    const response = await api.post<ApiResponse<QuestionBankTree>>(`/lms/question-banks/${bankId}/categories`, payload);
    return response.data.data;
  },

  async deleteQuestionCategory(categoryId: number) {
    await api.delete(`/lms/question-categories/${categoryId}`);
  },

  async questions(params: { bank_id?: number; category_id?: number; question_type?: string; search?: string; page?: number } = {}) {
    const response = await api.get<PaginatedResponse<QuestionPayload>>('/lms/questions', { params });
    return response.data;
  },

  async createQuestion(payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<QuestionPayload>>('/lms/questions', payload);
    return response.data.data;
  },

  async updateQuestion(questionId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<QuestionPayload>>(`/lms/questions/${questionId}`, payload);
    return response.data.data;
  },

  async deleteQuestion(questionId: number) {
    await api.delete(`/lms/questions/${questionId}`);
  },

  async duplicateQuestion(questionId: number, overrides: Record<string, unknown> = {}) {
    const response = await api.post<ApiResponse<QuestionPayload>>(`/lms/questions/${questionId}/duplicate`, overrides);
    return response.data.data;
  },

  // ------------------------------------------------------------------ forum
  async forum(activityId: number) {
    const response = await api.get<ApiResponse<ForumView>>(`/lms/activities/${activityId}/forum`);
    return response.data.data;
  },

  async updateForum(activityId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<Record<string, unknown>>>(`/lms/activities/${activityId}/forum`, payload);
    return response.data.data;
  },

  async createDiscussion(activityId: number, payload: { title: string; content: string }) {
    const response = await api.post<ApiResponse<{ id: number; status: string; status_label: string }>>(
      `/lms/activities/${activityId}/forum/discussions`,
      payload,
    );
    return response.data.data;
  },

  async discussion(discussionId: number) {
    const response = await api.get<ApiResponse<ForumDiscussionView>>(`/lms/discussions/${discussionId}`);
    return response.data.data;
  },

  async createPost(discussionId: number, payload: { content: string; parent_id?: number | null }) {
    const response = await api.post<ApiResponse<{ id: number; status: string; depth: number }>>(`/lms/discussions/${discussionId}/posts`, payload);
    return response.data.data;
  },

  async updatePost(postId: number, content: string) {
    await api.put(`/lms/forum-posts/${postId}`, { content });
  },

  async deletePost(postId: number) {
    await api.delete(`/lms/forum-posts/${postId}`);
  },

  async subscribeForum(activityId: number, payload: Record<string, unknown> = {}) {
    const response = await api.post<ApiResponse<{ subscribed: boolean }>>(`/lms/activities/${activityId}/forum/subscribe`, payload);
    return response.data.data;
  },

  async unsubscribeForum(activityId: number) {
    await api.delete(`/lms/activities/${activityId}/forum/subscribe`);
  },

  async forumModerationQueue(activityId: number) {
    const response = await api.get<ApiResponse<{ posts: Array<Record<string, unknown>>; discussions: Array<Record<string, unknown>> }>>(
      `/lms/activities/${activityId}/forum/moderation`,
    );
    return response.data.data;
  },

  async moderateForum(type: 'post' | 'discussion', id: number, action: 'approve' | 'hide' | 'delete') {
    if (action === 'delete') {
      await api.delete(`/lms/forum-moderation/${type}/${id}`);
      return;
    }
    await api.post(`/lms/forum-moderation/${type}/${id}/${action}`);
  },

  async pinDiscussion(discussionId: number) {
    const response = await api.post<ApiResponse<{ is_pinned: boolean }>>(`/lms/discussions/${discussionId}/pin`);
    return response.data.data;
  },

  async lockDiscussion(discussionId: number) {
    const response = await api.post<ApiResponse<{ is_locked: boolean }>>(`/lms/discussions/${discussionId}/lock`);
    return response.data.data;
  },

  async forumStatistics(activityId: number) {
    const response = await api.get<ApiResponse<Record<string, unknown>>>(`/lms/activities/${activityId}/forum/statistics`);
    return response.data.data;
  },

  // ----------------------------------------------------------------- lesson
  async lesson(activityId: number, pageId?: number) {
    const response = await api.get<ApiResponse<LessonView>>(`/lms/activities/${activityId}/lesson`, {
      params: pageId ? { page_id: pageId } : {},
    });
    return response.data.data;
  },

  async saveLessonProgress(activityId: number, pageId: number, answerKey?: string) {
    const response = await api.post<
      ApiResponse<{ answer_correct: boolean | null; next_page: unknown; progress: { visited_pages: number[]; percent: number; completed: boolean } }>
    >(`/lms/activities/${activityId}/lesson/progress`, { page_id: pageId, answer_key: answerKey });
    return response.data.data;
  },

  async lessonBuilder(activityId: number) {
    const response = await api.get<ApiResponse<Record<string, unknown>>>(`/lms/activities/${activityId}/lesson/builder`);
    return response.data.data;
  },

  async updateLesson(activityId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<Record<string, unknown>>>(`/lms/activities/${activityId}/lesson`, payload);
    return response.data.data;
  },

  async createLessonPage(activityId: number, payload: Record<string, unknown>) {
    const response = await api.post<ApiResponse<{ id: number }>>(`/lms/activities/${activityId}/lesson/pages`, payload);
    return response.data.data;
  },

  async updateLessonPage(pageId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<Record<string, unknown>>>(`/lms/lesson-pages/${pageId}`, payload);
    return response.data.data;
  },

  async deleteLessonPage(pageId: number) {
    await api.delete(`/lms/lesson-pages/${pageId}`);
  },

  // ----------------------------------------------------------------- choice
  async choice(activityId: number) {
    const response = await api.get<ApiResponse<ChoiceView>>(`/lms/activities/${activityId}/choice`);
    return response.data.data;
  },

  async updateChoice(activityId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<ChoiceView>>(`/lms/activities/${activityId}/choice`, payload);
    return response.data.data;
  },

  async respondChoice(activityId: number, optionIds: number[]) {
    const response = await api.post<ApiResponse<ChoiceView>>(`/lms/activities/${activityId}/choice/respond`, { option_ids: optionIds });
    return response.data.data;
  },

  async choiceResults(activityId: number) {
    const response = await api.get<ApiResponse<ChoiceResults>>(`/lms/activities/${activityId}/choice/results`);
    return response.data.data;
  },

  // ----------------------------------------------------------------- survey
  async survey(activityId: number) {
    const response = await api.get<ApiResponse<SurveyView>>(`/lms/activities/${activityId}/survey`);
    return response.data.data;
  },

  async updateSurvey(activityId: number, payload: Record<string, unknown>) {
    const response = await api.put<ApiResponse<SurveyView>>(`/lms/activities/${activityId}/survey`, payload);
    return response.data.data;
  },

  async submitSurvey(activityId: number, answers: Array<Record<string, unknown>>) {
    const response = await api.post<ApiResponse<{ submitted: boolean; anonymous: boolean; results: SurveyResults | null }>>(
      `/lms/activities/${activityId}/survey/submit`,
      { answers },
    );
    return response.data.data;
  },

  async surveyResults(activityId: number) {
    const response = await api.get<ApiResponse<SurveyResults>>(`/lms/activities/${activityId}/survey/results`);
    return response.data.data;
  },

  // ------------------------------------------------------------------ nilai
  async courseGrades(courseId: number, userId?: number) {
    const response = await api.get<ApiResponse<{ summary: CourseGradeSummary; read_only: boolean }>>(`/lms/courses/${courseId}/grades`, {
      params: userId ? { user_id: userId } : {},
    });
    return response.data.data;
  },

  async activityGrades(activityId: number) {
    const response = await api.get<ApiResponse<ActivityGradeRecord[]>>(`/lms/activities/${activityId}/grades`);
    return response.data.data;
  },

  async storeGrade(activityId: number, payload: { user_id: number; grade: number; feedback?: string }) {
    const response = await api.post<ApiResponse<ActivityGradeRecord>>(`/lms/activities/${activityId}/grades`, payload);
    return response.data.data;
  },

  async pushGrade(activityId: number, userId: number) {
    const response = await api.post<ApiResponse<Record<string, unknown>>>(`/lms/activities/${activityId}/grades/push`, { user_id: userId });
    return response.data.data;
  },
};
