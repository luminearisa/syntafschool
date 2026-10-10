import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { PromotionDecisionsPage } from '@/features/promotion/pages/PromotionDecisionsPage';
import { PromotionRulesPage } from '@/features/promotion/pages/PromotionRulesPage';
import { GraduationsPage } from '@/features/promotion/pages/GraduationsPage';

export const domainRoutes: RouteObject[] = [
  {
    path: 'academic/promotion-decisions',
    element: <PermissionGuard permission="promotion.view"><PromotionDecisionsPage /></PermissionGuard>,
  },
  {
    path: 'academic/promotion-rules',
    element: <PermissionGuard permission="promotion.view"><PromotionRulesPage /></PermissionGuard>,
  },
  {
    path: 'academic/graduations',
    element: <PermissionGuard permission="graduation.view"><GraduationsPage /></PermissionGuard>,
  },
];

export const domainMenu: MenuChild[] = [
  { label: 'Kenaikan Kelas', to: '/academic/promotion-decisions', permission: 'promotion.view' },
  { label: 'Aturan Kenaikan', to: '/academic/promotion-rules', permission: 'promotion.view' },
  { label: 'Kelulusan', to: '/academic/graduations', permission: 'graduation.view' },
];
