import { api } from '@/lib/axios';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type {
  AdminDashboard,
  ChildOverview,
  ChildSummary,
  CourseDetailPayload,
  CourseFormPayload,
  CourseProgressSummary,
  CourseReadiness,
  CourseScopeValue,
  CourseStructureSection,
  LmsActor,
  LmsAgenda,
  LmsAnnouncement,
  LmsBookmark,
  LmsCategory,
  LmsCategoryOption,
  LmsCourse,
  LmsEnrollment,
  LmsGroup,
  LmsLearningItem,
  LmsLookups,
  LmsProgress,
  LmsSearchResults,
  LmsSection,
  LearningItemPayload,
  ParentDashboard,
  StudentDashboard,
  TeacherDashboard,
  CourseAccessInfo,
} from '../types';

function toQuery(params: object = {}): Record<string, string | number> {
  const query: Record<string, string | number> = {};
  Object.entries(params as Record<string, unknown>).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return;
    if (typeof value === 'boolean') {
      query[key] = value ? 1 : 0;
      return;
    }
    query[key] = value as string | number;
  });
  return query;
}

export interface CourseListParams {
  page?: number;
  per_page?: number;
  all?: boolean;
  scope?: CourseScopeValue;
  mine?: boolean;
  search?: string;
  category_id?: number | '';
  status?: string;
  visibility?: string;
  course_type?: string;
  level?: string;
  academic_year_id?: number | '';
  class_id?: number | '';
  teacher_id?: number | '';
  featured?: boolean;
  sort?: string;
  direction?: string;
}

export interface CourseProgressResult {
  progress: CourseProgressSummary;
  next: { id: number; title: string } | null;
}

export const lmsApi = {
  // ------------------------------------------------------------------ auth
  async session() {
    const response = await api.get<ApiResponse<{ authenticated: boolean; actor: LmsActor | null; mode: string }>>(
      '/lms/auth/session',
    );
    return response.data.data;
  },

  async me() {
    const response = await api.get<ApiResponse<{ actor: LmsActor; mode: string }>>('/lms/auth/me');
    return response.data.data;
  },

  // -------------------------------------------------------------- lookups
  async lookups() {
    const response = await api.get<ApiResponse<LmsLookups>>('/lms/courses/lookups');
    return response.data.data;
  },

  async categories(visibleOnly = false) {
    const response = await api.get<ApiResponse<{ tree: LmsCategory[]; options: LmsCategoryOption[] }>>(
      '/lms/categories',
      { params: toQuery({ visible_only: visibleOnly }) },
    );
    return response.data.data;
  },

  async createCategory(payload: Partial<LmsCategory>) {
    const response = await api.post<ApiResponse<LmsCategory>>('/lms/categories', payload);
    return response.data.data;
  },

  async updateCategory(id: number, payload: Partial<LmsCategory>) {
    const response = await api.put<ApiResponse<LmsCategory>>(`/lms/categories/${id}`, payload);
    return response.data.data;
  },

  async deleteCategory(id: number) {
    await api.delete(`/lms/categories/${id}`);
  },

  async reorderCategories(ids: number[], parentId: number | null = null) {
    await api.post('/lms/categories/reorder', { ids, parent_id: parentId });
  },

  // -------------------------------------------------------------- courses
  async courses(params: CourseListParams = {}) {
    const response = await api.get<PaginatedResponse<LmsCourse>>('/lms/courses', { params: toQuery(params) });
    return response.data;
  },

  async coursesAll(params: CourseListParams = {}) {
    const response = await api.get<ApiResponse<LmsCourse[]>>('/lms/courses', {
      params: toQuery({ ...params, all: true }),
    });
    return response.data.data;
  },

  async course(id: number) {
    const response = await api.get<ApiResponse<CourseDetailPayload>>(`/lms/courses/${id}`);
    return response.data.data;
  },

  async createCourse(payload: CourseFormPayload) {
    const response = await api.post<ApiResponse<LmsCourse>>('/lms/courses', payload);
    return response.data.data;
  },

  async updateCourse(id: number, payload: Partial<CourseFormPayload>) {
    const response = await api.put<ApiResponse<LmsCourse>>(`/lms/courses/${id}`, payload);
    return response.data.data;
  },

  async deleteCourse(id: number) {
    await api.delete(`/lms/courses/${id}`);
  },

  async duplicateCourse(id: number, withContent = true) {
    const response = await api.post<ApiResponse<LmsCourse>>(`/lms/courses/${id}/duplicate`, {
      with_content: withContent,
    });
    return response.data.data;
  },

  async courseReadiness(id: number) {
    const response = await api.get<ApiResponse<CourseReadiness>>(`/lms/courses/${id}/readiness`);
    return response.data.data;
  },

  async publishCourse(id: number, force = false) {
    const response = await api.post<ApiResponse<LmsCourse>>(`/lms/courses/${id}/publish`, { force });
    return response.data.data;
  },

  async unpublishCourse(id: number) {
    const response = await api.post<ApiResponse<LmsCourse>>(`/lms/courses/${id}/unpublish`);
    return response.data.data;
  },

  async archiveCourse(id: number) {
    const response = await api.post<ApiResponse<LmsCourse>>(`/lms/courses/${id}/archive`);
    return response.data.data;
  },

  async restoreCourse(id: number) {
    const response = await api.post<ApiResponse<LmsCourse>>(`/lms/courses/${id}/restore`);
    return response.data.data;
  },

  async courseStatistics(id: number) {
    const response = await api.get<
      ApiResponse<{ statistics: Record<string, number>; enrollments: Record<string, number>; readiness: CourseReadiness }>
    >(`/lms/courses/${id}/statistics`);
    return response.data.data;
  },

  // ------------------------------------------------------------- structure
  async createSection(courseId: number, payload: Partial<LmsSection>) {
    const response = await api.post<ApiResponse<LmsSection>>(`/lms/courses/${courseId}/sections`, payload);
    return response.data.data;
  },

  async updateSection(sectionId: number, payload: Partial<LmsSection>) {
    const response = await api.put<ApiResponse<LmsSection>>(`/lms/sections/${sectionId}`, payload);
    return response.data.data;
  },

  async deleteSection(sectionId: number) {
    await api.delete(`/lms/sections/${sectionId}`);
  },

  async duplicateSection(sectionId: number) {
    const response = await api.post<ApiResponse<LmsSection>>(`/lms/sections/${sectionId}/duplicate`);
    return response.data.data;
  },

  async reorderSections(courseId: number, ids: number[]) {
    await api.post(`/lms/courses/${courseId}/sections/reorder`, { ids });
  },

  async createItem(sectionId: number, payload: LearningItemPayload) {
    const response = await api.post<ApiResponse<LmsLearningItem>>(`/lms/sections/${sectionId}/items`, payload);
    return response.data.data;
  },

  async updateItem(itemId: number, payload: Partial<LearningItemPayload>) {
    const response = await api.put<ApiResponse<LmsLearningItem>>(`/lms/items/${itemId}`, payload);
    return response.data.data;
  },

  async deleteItem(itemId: number) {
    await api.delete(`/lms/items/${itemId}`);
  },

  async duplicateItem(itemId: number) {
    const response = await api.post<ApiResponse<LmsLearningItem>>(`/lms/items/${itemId}/duplicate`);
    return response.data.data;
  },

  async moveItem(itemId: number, sectionId: number) {
    const response = await api.post<ApiResponse<LmsLearningItem>>(`/lms/items/${itemId}/move`, {
      section_id: sectionId,
    });
    return response.data.data;
  },

  async reorderItems(sectionId: number, ids: number[]) {
    await api.post(`/lms/sections/${sectionId}/items/reorder`, { ids });
  },

  async uploadItemFile(itemId: number, file: File) {
    const form = new FormData();
    form.append('file', file);
    const response = await api.post<ApiResponse<{ resource: { id: number; file_name: string; download_url: string } }>>(
      `/lms/items/${itemId}/upload`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    );
    return response.data.data;
  },

  async saveChapters(itemId: number, chapters: Array<{ id?: number; title: string; content?: string | null }>) {
    const response = await api.put<ApiResponse<LmsLearningItem['chapters']>>(`/lms/items/${itemId}/chapters`, {
      chapters,
    });
    return response.data.data;
  },

  // ------------------------------------------------------------- learning
  async player(courseId: number) {
    const response = await api.get<
      ApiResponse<{
        course: Pick<LmsCourse, 'id' | 'name' | 'code' | 'slug' | 'thumbnail' | 'cover_image' | 'level' | 'status' | 'start_date' | 'end_date' | 'teachers'>;
        structure: CourseStructureSection[];
        progress: CourseProgressSummary;
        resume: { next_item_id: number | null; last_item_id: number | null };
        announcements: LmsAnnouncement[];
        capabilities: { manage: boolean; read_only: boolean };
      }>
    >(`/lms/courses/${courseId}/learn`);
    return response.data.data;
  },

  async learningItem(courseId: number, itemId: number) {
    const response = await api.get<
      ApiResponse<{
        item: LmsLearningItem;
        access: CourseAccessInfo;
        completed: boolean;
        completion: { type: string; can_mark_complete: boolean };
        navigation: {
          previous: { id: number; title: string; section_title: string | null } | null;
          next: { id: number; title: string; section_title: string | null } | null;
        };
        progress: CourseProgressSummary | null;
      }>
    >(`/lms/courses/${courseId}/items/${itemId}`);
    return response.data.data;
  },

  async trackView(courseId: number, itemId: number) {
    const response = await api.post<
      ApiResponse<{ auto_completed: boolean; progress: CourseProgressSummary | null; next_item_id: number | null }>
    >(`/lms/courses/${courseId}/items/${itemId}/view`);
    return response.data.data;
  },

  async completeItem(courseId: number, itemId: number, grade?: number) {
    const response = await api.post<ApiResponse<CourseProgressResult>>(
      `/lms/courses/${courseId}/items/${itemId}/complete`,
      grade === undefined ? {} : { grade },
    );
    return response.data.data;
  },

  async uncompleteItem(courseId: number, itemId: number) {
    const response = await api.delete<ApiResponse<CourseProgressSummary>>(
      `/lms/courses/${courseId}/items/${itemId}/complete`,
    );
    return response.data.data;
  },

  async courseProgress(courseId: number) {
    const response = await api.get<ApiResponse<CourseProgressSummary>>(`/lms/courses/${courseId}/progress`);
    return response.data.data;
  },

  async continueLearning(courseId: number) {
    const response = await api.get<
      ApiResponse<{
        enrolled: boolean;
        progress_percent: number;
        next_item: { id: number; title: string; section_id: number } | null;
      }>
    >(`/lms/courses/${courseId}/continue`);
    return response.data.data;
  },

  async myCourses(params: { status?: string } = {}) {
    const response = await api.get<
      ApiResponse<{
        summary: { total: number; active: number; completed: number; average_progress: number };
        courses: Array<{
          enrollment_id: number;
          status: string;
          progress_percent: number;
          completed_items: number;
          required_items: number;
          next_item_id: number | null;
          last_accessed_at: string | null;
          course: Pick<LmsCourse, 'id' | 'name' | 'code' | 'slug' | 'thumbnail' | 'cover_image' | 'level' | 'status'>;
        }>;
      }>
    >('/lms/my/courses', { params: toQuery(params) });
    return response.data.data;
  },

  async myProgress() {
    const response = await api.get<
      ApiResponse<{
        courses: Array<{ course_id: number; enrollment_id: number; status: string; progress_percent: number; completed_items: number; required_items: number; completed_at: string | null }>;
        totals: { courses: number; completed_courses: number; completed_items: number; average_progress: number };
      }>
    >('/lms/my/progress');
    return response.data.data;
  },

  // ---------------------------------------------------------- enrollments
  async enrollments(courseId: number, params: Record<string, unknown> = {}) {
    const response = await api.get<
      ApiResponse<{ summary: Record<string, number>; enrollments: PaginatedResponse<LmsEnrollment> }>
    >(`/lms/courses/${courseId}/enrollments`, { params: toQuery(params) });
    return response.data;
  },

  async enroll(courseId: number, payload: { user_id?: number; student_id?: number; teacher_id?: number; role?: string }) {
    const response = await api.post<ApiResponse<LmsEnrollment>>(`/lms/courses/${courseId}/enrollments`, payload);
    return response.data.data;
  },

  async bulkEnroll(courseId: number, payload: { student_ids?: number[]; user_ids?: number[]; method?: string }) {
    const response = await api.post<
      ApiResponse<{ created: number; updated?: number; skipped: Array<{ student_id?: number; user_id?: number; reason: string }>; total_enrolled: number }>
    >(`/lms/courses/${courseId}/enrollments/bulk`, payload);
    return response.data.data;
  },

  async enrollmentCandidates(courseId: number, search = '') {
    const response = await api.get<
      ApiResponse<Array<{ id: number; user_id: number | null; name: string; code: string | null; class_name: string | null; has_account: boolean }>>
    >(`/lms/courses/${courseId}/enrollments/candidates`, { params: toQuery({ search }) });
    return response.data.data;
  },

  async syncAcademicEnrollments(courseId: number) {
    const response = await api.post<
      ApiResponse<{ learners_found: number; created: number; updated: number; skipped: Array<{ name: string; reason: string }>; total_enrolled: number }>
    >(`/lms/courses/${courseId}/enrollments/sync-academic`);
    return response.data.data;
  },

  async updateEnrollment(id: number, payload: Partial<{ role: string; status: string; start_at: string | null; end_at: string | null }>) {
    const response = await api.put<ApiResponse<LmsEnrollment>>(`/lms/enrollments/${id}`, payload);
    return response.data.data;
  },

  async suspendEnrollment(id: number, reason?: string) {
    const response = await api.post<ApiResponse<LmsEnrollment>>(`/lms/enrollments/${id}/suspend`, { reason });
    return response.data.data;
  },

  async cancelEnrollment(id: number, reason?: string) {
    const response = await api.post<ApiResponse<LmsEnrollment>>(`/lms/enrollments/${id}/cancel`, { reason });
    return response.data.data;
  },

  async reactivateEnrollment(id: number) {
    const response = await api.post<ApiResponse<LmsEnrollment>>(`/lms/enrollments/${id}/reactivate`);
    return response.data.data;
  },

  async completeEnrollment(id: number) {
    const response = await api.post<ApiResponse<LmsEnrollment>>(`/lms/enrollments/${id}/complete`);
    return response.data.data;
  },

  async removeEnrollment(id: number) {
    await api.delete(`/lms/enrollments/${id}`);
  },

  async selfEnroll(courseId: number) {
    const response = await api.post<ApiResponse<LmsEnrollment>>(`/lms/courses/${courseId}/enroll`);
    return response.data.data;
  },

  // --------------------------------------------------------------- groups
  async groups(courseId: number) {
    const response = await api.get<ApiResponse<LmsGroup[]>>(`/lms/courses/${courseId}/groups`);
    return response.data.data;
  },

  async createGroup(courseId: number, payload: Partial<LmsGroup>) {
    const response = await api.post<ApiResponse<LmsGroup>>(`/lms/courses/${courseId}/groups`, payload);
    return response.data.data;
  },

  async updateGroup(id: number, payload: Partial<LmsGroup>) {
    const response = await api.put<ApiResponse<LmsGroup>>(`/lms/groups/${id}`, payload);
    return response.data.data;
  },

  async deleteGroup(id: number) {
    await api.delete(`/lms/groups/${id}`);
  },

  async addGroupMembers(groupId: number, enrollmentIds: number[]) {
    const response = await api.post<ApiResponse<LmsGroup>>(`/lms/groups/${groupId}/members`, {
      enrollment_ids: enrollmentIds,
    });
    return response.data.data;
  },

  async removeGroupMembers(groupId: number, enrollmentIds: number[]) {
    const response = await api.delete<ApiResponse<LmsGroup>>(`/lms/groups/${groupId}/members`, {
      data: { enrollment_ids: enrollmentIds },
    });
    return response.data.data;
  },

  // -------------------------------------------------------- announcements
  async announcements(courseId: number, page = 1) {
    const response = await api.get<PaginatedResponse<LmsAnnouncement>>(`/lms/courses/${courseId}/announcements`, {
      params: toQuery({ page }),
    });
    return response.data;
  },

  async createAnnouncement(courseId: number, payload: { title: string; content: string; pinned?: boolean }) {
    const response = await api.post<ApiResponse<LmsAnnouncement>>(`/lms/courses/${courseId}/announcements`, payload);
    return response.data.data;
  },

  async updateAnnouncement(id: number, payload: Partial<{ title: string; content: string; pinned: boolean }>) {
    const response = await api.put<ApiResponse<LmsAnnouncement>>(`/lms/announcements/${id}`, payload);
    return response.data.data;
  },

  async toggleAnnouncementPin(id: number) {
    const response = await api.post<ApiResponse<LmsAnnouncement>>(`/lms/announcements/${id}/pin`);
    return response.data.data;
  },

  async deleteAnnouncement(id: number) {
    await api.delete(`/lms/announcements/${id}`);
  },

  // ------------------------------------------------------------ bookmarks
  async bookmarks(page = 1) {
    const response = await api.get<PaginatedResponse<LmsBookmark>>('/lms/bookmarks', { params: toQuery({ page }) });
    return response.data;
  },

  async toggleBookmark(courseId: number, itemId?: number, note?: string) {
    const response = await api.post<ApiResponse<{ bookmarked: boolean; id: number | null }>>(
      `/lms/courses/${courseId}/bookmark`,
      { learning_item_id: itemId, note },
    );
    return response.data.data;
  },

  async deleteBookmark(id: number) {
    await api.delete(`/lms/bookmarks/${id}`);
  },

  // ------------------------------------------------------------ dashboard
  async dashboard() {
    const response = await api.get<ApiResponse<StudentDashboard | TeacherDashboard | ParentDashboard | AdminDashboard>>(
      '/lms/dashboard',
    );
    return response.data.data;
  },

  async studentDashboard() {
    const response = await api.get<ApiResponse<StudentDashboard>>('/lms/dashboard/student');
    return response.data.data;
  },

  async teacherDashboard() {
    const response = await api.get<ApiResponse<TeacherDashboard>>('/lms/dashboard/teacher');
    return response.data.data;
  },

  async parentDashboard() {
    const response = await api.get<ApiResponse<ParentDashboard>>('/lms/dashboard/parent');
    return response.data.data;
  },

  async adminDashboard() {
    const response = await api.get<ApiResponse<AdminDashboard>>('/lms/dashboard/admin');
    return response.data.data;
  },

  async children() {
    const response = await api.get<ApiResponse<ChildSummary['student'][]>>('/lms/parent/children');
    return response.data.data;
  },

  async child(studentId: number) {
    const response = await api.get<ApiResponse<ChildOverview>>(`/lms/parent/children/${studentId}`);
    return response.data.data;
  },

  // -------------------------------------------------------------- calendar
  async calendar(params: { from?: string; until?: string } = {}) {
    const response = await api.get<ApiResponse<LmsAgenda>>('/lms/calendar', { params: toQuery(params) });
    return response.data.data;
  },

  // ---------------------------------------------------------------- search
  async search(params: { q?: string; category_id?: number | ''; level?: string; course_type?: string; teacher_id?: number | ''; per_page?: number }) {
    const response = await api.get<
      ApiResponse<LmsSearchResults & { filters: { categories: LmsCategoryOption[]; levels: string[]; course_types: string[] } }>
    >('/lms/search', { params: toQuery(params) });
    return response.data.data;
  },

  async suggestions(q: string) {
    const response = await api.get<ApiResponse<LmsCourse[]>>('/lms/search/suggestions', { params: toQuery({ q }) });
    return response.data.data;
  },

  // -------------------------------------------------------------- settings
  async settings() {
    const response = await api.get<
      ApiResponse<{ settings: Record<string, string | number | boolean>; integration: Record<string, unknown> }>
    >('/lms/settings');
    return response.data.data;
  },

  async updateSettings(payload: Record<string, string | number | boolean>) {
    const response = await api.put<ApiResponse<{ settings: Record<string, string | number | boolean> }>>(
      '/lms/settings',
      payload,
    );
    return response.data.data;
  },

  // ---------------------------------------------------------------- public
  async publicHome() {
    const response = await api.get<
      ApiResponse<{
        hero: { title: string; subtitle: string; stats: { courses: number; categories: number; lessons: number } };
        featured_courses: LmsCourse[];
        categories: LmsCategory[];
        teachers: Array<{ id: number; user_id: number | null; name: string; email: string | null; nip: string | null }>;
        integration: Record<string, unknown>;
      }>
    >('/lms/public/home');
    return response.data.data;
  },

  async publicCourses(params: { page?: number; per_page?: number; search?: string; category_id?: number | ''; level?: string; course_type?: string; featured?: boolean; sort?: string } = {}) {
    const response = await api.get<PaginatedResponse<LmsCourse>>('/lms/public/courses', { params: toQuery(params) });
    return response.data;
  },

  async publicCourse(slug: string) {
    const response = await api.get<
      ApiResponse<{
        course: LmsCourse;
        sections: Array<{ id: number; title: string; summary: string | null; items_count: number }>;
        preview_items: Array<{
          id: number;
          title: string;
          item_type: string;
          resource_type: string | null;
          activity_type: string | null;
          is_preview: boolean;
          section_title: string;
          locked: boolean;
        }>;
        syllabus_locked: boolean;
      }>
    >(`/lms/public/courses/${slug}`);
    return response.data.data;
  },

  async publicCategories() {
    const response = await api.get<ApiResponse<LmsCategory[]>>('/lms/public/categories');
    return response.data.data;
  },

  async publicTeachers(search = '') {
    const response = await api.get<
      ApiResponse<Array<{ id: number; user_id: number | null; name: string; email: string | null; nip: string | null; public_courses: number }>>
    >('/lms/public/teachers', { params: toQuery({ search }) });
    return response.data.data;
  },
};

export type LmsProgressRecord = LmsProgress;
