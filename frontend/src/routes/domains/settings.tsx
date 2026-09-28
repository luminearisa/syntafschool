import type { RouteObject } from 'react-router-dom';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { GeneralSettingsPage } from '@/features/settings/pages/GeneralSettingsPage';

export const domainRoutes: RouteObject[] = [
  {
    path: 'settings/general',
    element: <PermissionGuard permission="setting.view"><GeneralSettingsPage /></PermissionGuard>,
  },
];
