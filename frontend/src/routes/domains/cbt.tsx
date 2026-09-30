import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import CbtDashboardPage from '@/features/cbt/pages/CbtDashboardPage';
import CbtQuestionsPage from '@/features/cbt/pages/CbtQuestionsPage';
import CbtBlueprintsPage from '@/features/cbt/pages/CbtBlueprintsPage';
import CbtSessionsPage from '@/features/cbt/pages/CbtSessionsPage';
import CbtMonitorPage from '@/features/cbt/pages/CbtMonitorPage';
import CbtMyExamsPage from '@/features/cbt/pages/CbtMyExamsPage';
import CbtPlayerPage from '@/features/cbt/pages/CbtPlayerPage';
import CbtGradingPage from '@/features/cbt/pages/CbtGradingPage';

export const domainRoutes: RouteObject[] = [
  { path: 'cbt', element: <PermissionGuard permission="cbt.view"><CbtDashboardPage /></PermissionGuard> },
  { path: 'cbt/questions', element: <PermissionGuard permission="cbt.question.view"><CbtQuestionsPage /></PermissionGuard> },
  { path: 'cbt/blueprints', element: <PermissionGuard permission="cbt.blueprint.view"><CbtBlueprintsPage /></PermissionGuard> },
  { path: 'cbt/sessions', element: <PermissionGuard permission="cbt.session.view"><CbtSessionsPage /></PermissionGuard> },
  { path: 'cbt/sessions/:id', element: <PermissionGuard permission="cbt.proctor"><CbtMonitorPage /></PermissionGuard> },
  { path: 'cbt/grading', element: <PermissionGuard permission="cbt.grade"><CbtGradingPage /></PermissionGuard> },
  { path: 'cbt/my', element: <PermissionGuard permission="cbt.attempt"><CbtMyExamsPage /></PermissionGuard> },
  { path: 'cbt/play/:attemptId', element: <PermissionGuard permission="cbt.attempt"><CbtPlayerPage /></PermissionGuard> },
];

export const cbtMenu: MenuChild[] = [
  { label: 'Dasbor CBT', to: '/cbt', permission: 'cbt.view' },
  { label: 'Bank Soal', to: '/cbt/questions', permission: 'cbt.question.view' },
  { label: 'Kisi Ujian', to: '/cbt/blueprints', permission: 'cbt.blueprint.view' },
  { label: 'Sesi Ujian', to: '/cbt/sessions', permission: 'cbt.session.view' },
  { label: 'Penilaian Esai', to: '/cbt/grading', permission: 'cbt.grade' },
  { label: 'Ujian Saya', to: '/cbt/my', permission: 'cbt.attempt' },
];
