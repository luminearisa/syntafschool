import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import LmsDashboardPage from '@/features/lms/pages/LmsDashboardPage';
import LmsCoursesPage from '@/features/lms/pages/LmsCoursesPage';
import LmsCourseFormPage from '@/features/lms/pages/LmsCourseFormPage';
import LmsCourseDetailPage from '@/features/lms/pages/LmsCourseDetailPage';
import LmsLearnPage from '@/features/lms/pages/LmsLearnPage';
import LmsMyCoursesPage from '@/features/lms/pages/LmsMyCoursesPage';
import LmsCalendarPage from '@/features/lms/pages/LmsCalendarPage';
import LmsBookmarksPage from '@/features/lms/pages/LmsBookmarksPage';
import LmsSearchPage from '@/features/lms/pages/LmsSearchPage';
import LmsCategoriesPage from '@/features/lms/pages/LmsCategoriesPage';
import LmsSettingsPage from '@/features/lms/pages/LmsSettingsPage';
import LmsParentChildPage from '@/features/lms/pages/LmsParentChildPage';
import LmsPublicHomePage from '@/features/lms/pages/LmsPublicHomePage';
import LmsPublicCoursesPage from '@/features/lms/pages/LmsPublicCoursesPage';
import LmsPublicCoursePage from '@/features/lms/pages/LmsPublicCoursePage';
import LmsPublicTeachersPage from '@/features/lms/pages/LmsPublicTeachersPage';

/**
 * Modul LMS (§37, §38).
 *
 * Berisi tiga permukaan sekaligus:
 *   1. `domainRoutes`       — area terautentikasi (dashboard, course, belajar…)
 *   2. `domainPublicRoutes` — LMS publik `/learn` yang dapat diakses tamu
 *   3. `domainMenu`         — submenu grup Akademik + entri "Ruang Belajar LMS"
 *
 * Otorisasi tetap ditegakkan backend (`lms.permission:*` + aturan kepemilikan
 * course); guard di sini hanya menyembunyikan halaman yang pasti ditolak.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'lms',
    element: (
      <PermissionGuard permission="lms.view">
        <LmsDashboardPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/courses',
    element: (
      <PermissionGuard permission="lms.course.view">
        <LmsCoursesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/courses/new',
    element: (
      <PermissionGuard permission="lms.course.create">
        <LmsCourseFormPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/courses/:courseId',
    element: (
      <PermissionGuard permission="lms.course.view">
        <LmsCourseDetailPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/courses/:courseId/edit',
    element: (
      <PermissionGuard permission="lms.course.update">
        <LmsCourseFormPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/courses/:courseId/learn',
    element: (
      <PermissionGuard permission="lms.content.view">
        <LmsLearnPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/courses/:courseId/learn/:itemId',
    element: (
      <PermissionGuard permission="lms.content.view">
        <LmsLearnPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/my-courses',
    element: (
      <PermissionGuard permission="lms.view">
        <LmsMyCoursesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/calendar',
    element: (
      <PermissionGuard permission="lms.view">
        <LmsCalendarPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/bookmarks',
    element: (
      <PermissionGuard permission={['lms.bookmark.manage', 'lms.view']}>
        <LmsBookmarksPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/search',
    element: (
      <PermissionGuard permission="lms.view">
        <LmsSearchPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/parent/:studentId',
    element: (
      <PermissionGuard permission={['lms.parent.view', 'lms.manage']}>
        <LmsParentChildPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/categories',
    element: (
      <PermissionGuard permission="lms.category.view">
        <LmsCategoriesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'lms/settings',
    element: (
      <PermissionGuard permission="lms.settings.manage">
        <LmsSettingsPage />
      </PermissionGuard>
    ),
  },
];

/** LMS publik — di luar cangkang aplikasi, dapat diakses tanpa login (§30). */
export const domainPublicRoutes: RouteObject[] = [
  { path: '/learn', element: <LmsPublicHomePage /> },
  { path: '/learn/courses', element: <LmsPublicCoursesPage /> },
  { path: '/learn/courses/:slug', element: <LmsPublicCoursePage /> },
  { path: '/learn/teachers', element: <LmsPublicTeachersPage /> },
];

export const lmsMenu: MenuChild[] = [
  { label: 'Ruang Belajar LMS', to: '/lms', permission: 'lms.view' },
  { label: 'Course LMS', to: '/lms/courses', permission: 'lms.course.view' },
  { label: 'Course Saya', to: '/lms/my-courses', permission: 'lms.view' },
  { label: 'Kalender Belajar', to: '/lms/calendar', permission: 'lms.view' },
  { label: 'Pencarian LMS', to: '/lms/search', permission: 'lms.view' },
  { label: 'Bookmark', to: '/lms/bookmarks', permission: ['lms.bookmark.manage', 'lms.view'] },
  { label: 'Kategori LMS', to: '/lms/categories', permission: 'lms.category.view' },
  { label: 'Pengaturan LMS', to: '/lms/settings', permission: 'lms.settings.manage' },
  { label: 'LMS Publik (/learn)', to: '/learn' },
];

export const domainMenu: MenuChild[] = lmsMenu;
