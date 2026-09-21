import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { TahfizDashboardPage } from '@/features/tahfiz/pages/TahfizDashboardPage';
import { TahfizParticipantsPage } from '@/features/tahfiz/pages/TahfizParticipantsPage';
import { TahfizParticipantDetailPage } from '@/features/tahfiz/pages/TahfizParticipantDetailPage';
import { TahfizRegistrationsPage } from '@/features/tahfiz/pages/TahfizRegistrationsPage';
import { TahfizEnrollmentsPage } from '@/features/tahfiz/pages/TahfizEnrollmentsPage';
import { TahfizHalaqahsPage } from '@/features/tahfiz/pages/TahfizHalaqahsPage';
import { TahfizSchedulesPage } from '@/features/tahfiz/pages/TahfizSchedulesPage';
import { TahfizSessionsPage } from '@/features/tahfiz/pages/TahfizSessionsPage';
import { TahfizAttendancesPage } from '@/features/tahfiz/pages/TahfizAttendancesPage';
import { TahfizMemorizationRecordsPage } from '@/features/tahfiz/pages/TahfizMemorizationRecordsPage';
import { TahfizRevisionRecordsPage } from '@/features/tahfiz/pages/TahfizRevisionRecordsPage';
import { TahfizTilawahRecordsPage } from '@/features/tahfiz/pages/TahfizTilawahRecordsPage';
import { TahfizTahsinRecordsPage } from '@/features/tahfiz/pages/TahfizTahsinRecordsPage';
import { TahfizTasmiRecordsPage } from '@/features/tahfiz/pages/TahfizTasmiRecordsPage';
import { TahfizMutabaahPage } from '@/features/tahfiz/pages/TahfizMutabaahPage';
import { TahfizTargetsPage } from '@/features/tahfiz/pages/TahfizTargetsPage';
import { TahfizIndividualTargetsPage } from '@/features/tahfiz/pages/TahfizIndividualTargetsPage';
import { TahfizExamsPage } from '@/features/tahfiz/pages/TahfizExamsPage';
import { TahfizExamDetailPage } from '@/features/tahfiz/pages/TahfizExamDetailPage';
import { TahfizCertificatesPage } from '@/features/tahfiz/pages/TahfizCertificatesPage';
import { TahfizReportsPage } from '@/features/tahfiz/pages/TahfizReportsPage';
import { TahfizInterventionsPage } from '@/features/tahfiz/pages/TahfizInterventionsPage';
import { TahfizNotificationsPage } from '@/features/tahfiz/pages/TahfizNotificationsPage';
import { TahfizSettingsPage } from '@/features/tahfiz/pages/TahfizSettingsPage';

/**
 * Modul Tahfiz / Al-Qur'an Management System.
 *
 * `domainRoutes` di-glob otomatis oleh `src/routes/domains/index.ts`.
 * `tahfizMenu` SENGAJA tidak dinamai `domainMenu`: `domainMenu` dari seluruh
 * domain digabung ke grup "Akademik", sedangkan Tahfiz punya grup sendiri —
 * lihat `src/constants/menu.ts`.
 *
 * Halaman pendaftaran mandiri publik (`/tahfiz/daftar`) dipasang terpisah di
 * `routes/index.tsx` karena berada di luar cangkang aplikasi (tanpa login).
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'tahfiz',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizDashboardPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/participants',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizParticipantsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/participants/:id',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizParticipantDetailPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/registrations',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizRegistrationsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/enrollments',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizEnrollmentsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/halaqahs',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizHalaqahsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/schedules',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizSchedulesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/sessions',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizSessionsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/attendances',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizAttendancesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/setoran',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizMemorizationRecordsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/murojaah',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizRevisionRecordsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/tilawah',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizTilawahRecordsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/tahsin',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizTahsinRecordsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/tasmi',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizTasmiRecordsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/mutabaah',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizMutabaahPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/targets',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizTargetsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/individual-targets',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizIndividualTargetsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/exams',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizExamsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/exams/:id',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizExamDetailPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/certificates',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizCertificatesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/reports',
    element: (
      <PermissionGuard permission="tahfiz.report">
        <TahfizReportsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/interventions',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizInterventionsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/notifications',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizNotificationsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'tahfiz/settings',
    element: (
      <PermissionGuard permission="tahfiz.view">
        <TahfizSettingsPage />
      </PermissionGuard>
    ),
  },
];

export const tahfizMenu: MenuChild[] = [
  { label: 'Dashboard Tahfiz', to: '/tahfiz', permission: 'tahfiz.view' },
  { label: 'Peserta', to: '/tahfiz/participants', permission: 'tahfiz.view' },
  { label: 'Pendaftaran', to: '/tahfiz/registrations', permission: 'tahfiz.view' },
  { label: 'Enrollment', to: '/tahfiz/enrollments', permission: 'tahfiz.view' },
  { label: 'Halaqah', to: '/tahfiz/halaqahs', permission: 'tahfiz.view' },
  { label: 'Jadwal', to: '/tahfiz/schedules', permission: 'tahfiz.view' },
  { label: 'Sesi', to: '/tahfiz/sessions', permission: 'tahfiz.view' },
  { label: 'Kehadiran', to: '/tahfiz/attendances', permission: 'tahfiz.view' },
  { label: 'Setoran Hafalan', to: '/tahfiz/setoran', permission: 'tahfiz.view' },
  { label: 'Murojaah', to: '/tahfiz/murojaah', permission: 'tahfiz.view' },
  { label: 'Tilawah', to: '/tahfiz/tilawah', permission: 'tahfiz.view' },
  { label: 'Tahsin', to: '/tahfiz/tahsin', permission: 'tahfiz.view' },
  { label: "Tasmi'", to: '/tahfiz/tasmi', permission: 'tahfiz.view' },
  { label: 'Mutabaah Harian', to: '/tahfiz/mutabaah', permission: 'tahfiz.view' },
  { label: 'Target', to: '/tahfiz/targets', permission: 'tahfiz.view' },
  { label: 'Target Individual', to: '/tahfiz/individual-targets', permission: 'tahfiz.view' },
  { label: 'Ujian', to: '/tahfiz/exams', permission: 'tahfiz.view' },
  { label: 'Sertifikat', to: '/tahfiz/certificates', permission: 'tahfiz.view' },
  { label: 'Laporan & Rapor', to: '/tahfiz/reports', permission: 'tahfiz.report' },
  { label: 'Intervensi', to: '/tahfiz/interventions', permission: 'tahfiz.view' },
  { label: 'Notifikasi', to: '/tahfiz/notifications', permission: 'tahfiz.view' },
  { label: 'Pengaturan Tahfiz', to: '/tahfiz/settings', permission: 'tahfiz.view' },
];