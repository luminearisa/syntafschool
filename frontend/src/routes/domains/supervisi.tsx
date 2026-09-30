import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import SupervisiSupervisorsPage from '@/features/supervisi/pages/SupervisiSupervisorsPage';
import SupervisiInstrumentsPage from '@/features/supervisi/pages/SupervisiInstrumentsPage';
import SupervisiInstrumentBuilderPage from '@/features/supervisi/pages/SupervisiInstrumentBuilderPage';
import SupervisiVisitsPage from '@/features/supervisi/pages/SupervisiVisitsPage';
import SupervisiVisitDetailPage from '@/features/supervisi/pages/SupervisiVisitDetailPage';
import SupervisiActionPlansPage from '@/features/supervisi/pages/SupervisiActionPlansPage';
import SupervisiAppraisalsPage from '@/features/supervisi/pages/SupervisiAppraisalsPage';
import SupervisiAppraisalDetailPage from '@/features/supervisi/pages/SupervisiAppraisalDetailPage';
import SupervisiGrowthPage from '@/features/supervisi/pages/SupervisiGrowthPage';
import SupervisiAnalyticsPage from '@/features/supervisi/pages/SupervisiAnalyticsPage';
import SupervisiCalibrationPage from '@/features/supervisi/pages/SupervisiCalibrationPage';
import SupervisiCippPage from '@/features/supervisi/pages/SupervisiCippPage';

/**
 * Modul Supervisi.
 *
 * Fase 1 — pengaturan: siapa yang boleh menilai (registry supervisor) dan
 * penyusunan instrumen (skala rubrik maupun entri angka).
 * Fase 2 — pelaksanaan: daftar kegiatan, pengisian instrumen di kelas,
 * wawancara, temuan, finalisasi, tanda tangan, dan keberatan.
 * Fase 3 — tindak lanjut: papan RTL, penilaian kinerja (PKG/NKKS/SKP/360°),
 * pengembangan kompetensi, coaching, dan PIP.
 * Fase 4 — analitik & laporan wajib (CSV/XLSX/PDF), kalibrasi penilai, dan
 * evaluasi program CIPP.
 *
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
  {
    path: 'supervisi/visits',
    element: (
      <PermissionGuard permission="supervisi.visit.view">
        <SupervisiVisitsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/action-plans',
    element: (
      <PermissionGuard permission="supervisi.action_plan.view">
        <SupervisiActionPlansPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/appraisals',
    element: (
      <PermissionGuard permission="supervisi.report.view">
        <SupervisiAppraisalsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/appraisals/:appraisalId',
    element: (
      <PermissionGuard permission="supervisi.report.view">
        <SupervisiAppraisalDetailPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/growth',
    element: (
      <PermissionGuard permission="supervisi.report.view">
        <SupervisiGrowthPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/analytics',
    element: (
      <PermissionGuard permission="supervisi.report.view">
        <SupervisiAnalyticsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/calibration',
    element: (
      <PermissionGuard permission="supervisi.calibration.view">
        <SupervisiCalibrationPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/evaluations',
    element: (
      <PermissionGuard permission="supervisi.evaluation.view">
        <SupervisiCippPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/visits/:visitId',
    element: (
      <PermissionGuard permission="supervisi.visit.view">
        <SupervisiVisitDetailPage />
      </PermissionGuard>
    ),
  },
];

export const domainMenu: MenuChild[] = [
  { label: 'Kegiatan Supervisi', to: '/supervisi/visits', permission: 'supervisi.visit.view' },
  { label: 'Rencana Tindak Lanjut', to: '/supervisi/action-plans', permission: 'supervisi.action_plan.view' },
  { label: 'Penilaian Kinerja', to: '/supervisi/appraisals', permission: 'supervisi.report.view' },
  { label: 'Pengembangan & Pembinaan', to: '/supervisi/growth', permission: 'supervisi.report.view' },
  { label: 'Analitik & Laporan', to: '/supervisi/analytics', permission: 'supervisi.report.view' },
  { label: 'Kalibrasi Penilai', to: '/supervisi/calibration', permission: 'supervisi.calibration.view' },
  { label: 'Evaluasi Program (CIPP)', to: '/supervisi/evaluations', permission: 'supervisi.evaluation.view' },
  { label: 'Pengaturan Supervisor', to: '/supervisi', permission: 'supervisi.view' },
  { label: 'Instrumen Supervisi', to: '/supervisi/instruments', permission: 'supervisi.instrument.view' },
];
