import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import CompetitionOverviewPage from '@/features/competitions/pages/CompetitionOverviewPage';
import CompetitionIdentifierPage from '@/features/competitions/pages/CompetitionIdentifierPage';
import MyCompetitionsPage from '@/features/competitions/pages/MyCompetitionsPage';
import CompetitionDiscoverPage from '@/features/competitions/pages/CompetitionDiscoverPage';
import JudgeAssignmentsPage from '@/features/competitions/pages/JudgeAssignmentsPage';
import JudgeScorePage from '@/features/competitions/pages/JudgeScorePage';

const guard = (permission: string, element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'competitions', element: guard('competition.view', <CompetitionOverviewPage />) },
  { path: 'competitions/discover', element: guard('registration.create', <CompetitionDiscoverPage />) },
  { path: 'my-competitions', element: guard('competition.portal.student', <MyCompetitionsPage />) },
  { path: 'competitions/parent', element: guard('competition.portal.parent', <MyCompetitionsPage />) },
  { path: 'judge/assignments', element: guard('score.enter', <JudgeAssignmentsPage />) },
  { path: 'judge/matches/:matchId', element: guard('score.enter', <JudgeScorePage />) },
];

export const domainPublicRoutes: RouteObject[] = [
  { path: '/competitions/:slug', element: <CompetitionIdentifierPage /> },
];

export const competitionMenu: MenuChild[] = [
  { label: 'Jelajahi kompetisi', to: '/competitions/discover', permission: 'registration.create' },
  { label: 'Competition Management', to: '/competitions', permission: 'competition.view' },
  { label: 'Kompetisi Saya', to: '/my-competitions', permission: 'competition.portal.student' },
  { label: 'Kompetisi Anak', to: '/competitions/parent', permission: 'competition.portal.parent' },
  { label: 'Penugasan Juri', to: '/judge/assignments', permission: 'score.enter' },
];
