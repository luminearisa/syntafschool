import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { TeachingJournalsPage } from '@/features/teaching/pages/TeachingJournalsPage';
import { TeachingTodayPage } from '@/features/teaching/pages/TeachingTodayPage';
import { LessonPlansPage } from '@/features/teaching/pages/LessonPlansPage';
import { TeachingRecapPage } from '@/features/teaching/pages/TeachingRecapPage';

/**
 * A11a — Jurnal Mengajar & RPP / Modul Ajar.
 *
 * File ini di-glob otomatis oleh `src/routes/domains/index.ts`, jadi tidak
 * perlu menyunting `routes/index.tsx` maupun `constants/menu.ts`.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'academics/teaching/today',
    element: (
      <PermissionGuard permission="academic.view">
        <TeachingTodayPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/teaching/journals',
    element: (
      <PermissionGuard permission="academic.view">
        <TeachingJournalsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/teaching/lesson-plans',
    element: (
      <PermissionGuard permission="academic.view">
        <LessonPlansPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/teaching/recap',
    element: (
      <PermissionGuard permission="academic.view">
        <TeachingRecapPage />
      </PermissionGuard>
    ),
  },
];

export const domainMenu: MenuChild[] = [
  {
    label: 'Jurnal Hari Ini',
    to: '/academics/teaching/today',
    permission: 'academic.view',
  },
  {
    label: 'Jurnal Mengajar',
    to: '/academics/teaching/journals',
    permission: 'academic.view',
  },
  {
    label: 'RPP / Modul Ajar',
    to: '/academics/teaching/lesson-plans',
    permission: 'academic.view',
  },
  {
    label: 'Rekap Beban Mengajar',
    to: '/academics/teaching/recap',
    permission: 'academic.view',
  },
];
