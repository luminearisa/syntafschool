import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { AssessmentsPage, AssessmentGradeSheetPage } from '@/features/assessments/pages';
import { ReportCardsPage } from '@/features/report-cards/pages/ReportCardsPage';
import { TranscriptsPage } from '@/features/report-cards/pages/TranscriptsPage';

export const domainRoutes: RouteObject[] = [
  {
    path: 'assessments',
    element: <PermissionGuard permission="assessment.view"><AssessmentsPage /></PermissionGuard>,
  },
  {
    path: 'assessments/:id/grades',
    element: <PermissionGuard permission="assessment.view"><AssessmentGradeSheetPage /></PermissionGuard>,
  },
  {
    path: 'assessments/report-cards',
    element: <PermissionGuard permission="report-card.view"><ReportCardsPage /></PermissionGuard>,
  },
  {
    path: 'assessments/transcripts',
    element: <PermissionGuard permission="report-card.view"><TranscriptsPage /></PermissionGuard>,
  },
];

export const assessmentsMenu: MenuChild[] = [
  { label: 'Penilaian', to: '/assessments', permission: 'assessment.view' },
  { label: 'Rapor', to: '/assessments/report-cards', permission: 'report-card.view' },
  { label: 'Transkrip', to: '/assessments/transcripts', permission: 'report-card.view' },
];
