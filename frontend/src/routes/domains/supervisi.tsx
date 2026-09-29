import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import SupervisiSupervisorsPage from '@/features/supervisi/pages/SupervisiSupervisorsPage';
import SupervisiInstrumentsPage from '@/features/supervisi/pages/SupervisiInstrumentsPage';
import SupervisiInstrumentBuilderPage from '@/features/supervisi/pages/SupervisiInstrumentBuilderPage';

/**
 * Modul Supervisi (Fase 1).
 *
 * Berisi pengaturan supervisor (memilih siapa saja yang boleh menilai) dan
 * pustaka/pembuat instrumen (indikator berskala maupun bertipe angka).
 * Otorisasi tetap ditegakkan backend (`supervisi.*`); guard di sini hanya
 * menyembunyikan halaman yang pasti ditolak.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'supervisi',
    element: (
      <PermissionGuard permission="supervisi.view">
        <SupervisiSupervisorsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/instruments',
    element: (
      <PermissionGuard permission="supervisi.instrument.view">
        <SupervisiInstrumentsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/instruments/:instrumentId/versions/:versionId',
    element: (
      <PermissionGuard permission="supervisi.instrument.view">
        <SupervisiInstrumentBuilderPage />
      </PermissionGuard>
    ),
  },
];

export const domainMenu: MenuChild[] = [
  { label: 'Pengaturan Supervisor', to: '/supervisi', permission: 'supervisi.view' },
  { label: 'Instrumen Supervisi', to: '/supervisi/instruments', permission: 'supervisi.instrument.view' },
];
