import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { CurriculumStructuresPage } from '@/features/curriculum/pages/CurriculumStructuresPage';
import { SubjectGroupsPage } from '@/features/curriculum/pages/SubjectGroupsPage';
import { ProgramsPage } from '@/features/curriculum/pages/ProgramsPage';
import { SubjectPrerequisitesPage } from '@/features/curriculum/pages/SubjectPrerequisitesPage';

/**
 * A5 — Kurikulum Engine.
 *
 * File ini di-glob otomatis oleh `src/routes/domains/index.ts`, jadi tidak
 * perlu menyunting `routes/index.tsx` maupun `constants/menu.ts`.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'academics/curriculum/structures',
    element: (
      <PermissionGuard permission="academic.view">
        <CurriculumStructuresPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/curriculum/subject-groups',
    element: (
      <PermissionGuard permission="academic.view">
        <SubjectGroupsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/curriculum/programs',
    element: (
      <PermissionGuard permission="academic.view">
        <ProgramsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/curriculum/prerequisites',
    element: (
      <PermissionGuard permission="academic.view">
        <SubjectPrerequisitesPage />
      </PermissionGuard>
    ),
  },
];

export const domainMenu: MenuChild[] = [
  {
    label: 'Struktur Kurikulum',
    to: '/academics/curriculum/structures',
    permission: 'academic.view',
  },
  {
    label: 'Kelompok Mapel',
    to: '/academics/curriculum/subject-groups',
    permission: 'academic.view',
  },
  {
    label: 'Program & Peminatan',
    to: '/academics/curriculum/programs',
    permission: 'academic.view',
  },
  {
    label: 'Prasyarat Mapel',
    to: '/academics/curriculum/prerequisites',
    permission: 'academic.view',
  },
];
