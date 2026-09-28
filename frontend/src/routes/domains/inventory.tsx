import type { RouteObject } from 'react-router-dom';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { InventoryPage } from '@/features/inventory/pages/InventoryPage';

export const domainRoutes: RouteObject[] = [
  {
    path: 'inventory',
    element: <PermissionGuard permission="inventory.view"><InventoryPage /></PermissionGuard>,
  },
];
