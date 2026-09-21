import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { AcademicCalendarPage } from '@/features/academic-calendar/pages/AcademicCalendarPage';
import { EffectiveDaysPage } from '@/features/academic-calendar/pages/EffectiveDaysPage';

/**
 * A4 — Kalender Akademik & Hari Efektif.
 *
 * File ini di-glob otomatis oleh `src/routes/domains/index.ts`, jadi tidak
 * perlu menyunting `routes/index.tsx` maupun `constants/menu.ts`.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'academics/calendar',
    element: (
      <PermissionGuard permission="academic.view">
        <AcademicCalendarPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/effective-days',
    element: (
      <PermissionGuard permission="academic.view">
        <EffectiveDaysPage />
      </PermissionGuard>
    ),
  },
];

export const domainMenu: MenuChild[] = [
  { label: 'Kalender Akademik', to: '/academics/calendar', permission: 'academic.view' },
  { label: 'Hari Efektif', to: '/academics/effective-days', permission: 'academic.view' },
];
