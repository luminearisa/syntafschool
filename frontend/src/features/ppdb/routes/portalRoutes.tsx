import type { RouteObject } from 'react-router-dom';
import { PpdbPublicLandingPage } from '../pages/public/PpdbPublicLandingPage';
import { PpdbPublicPeriodPage } from '../pages/public/PpdbPublicPeriodPage';
import { PpdbRegisterPage } from '../pages/public/PpdbRegisterPage';
import { PpdbLoginPage } from '../pages/public/PpdbLoginPage';
import { PpdbNotFoundPage } from '../pages/public/PpdbNotFoundPage';
import { PpdbPortalLayout } from '../pages/portal/PpdbPortalLayout';
import { PpdbPortalDashboardPage } from '../pages/portal/PpdbPortalDashboardPage';
import { PpdbPortalProfilePage } from '../pages/portal/PpdbPortalProfilePage';
import { PpdbPortalApplicantsPage } from '../pages/portal/PpdbPortalApplicantsPage';
import { PpdbPortalRegistrationListPage } from '../pages/portal/PpdbPortalRegistrationListPage';
import { PpdbRegistrationWizardPage } from '../pages/portal/PpdbRegistrationWizardPage';
import { PpdbPortalSelectionPage } from '../pages/portal/PpdbPortalSelectionPage';
import { PpdbPortalNotificationsPage } from '../pages/portal/PpdbPortalNotificationsPage';

/**
 * Rute permukaan publik + portal orang tua PPDB.
 *
 * Semuanya berada di luar `ProtectedRoute`/`AppLayout`: halaman publik bersifat
 * terbuka dan portal memakai sesi orang tua dengan cangkang chromeless miliknya
 * sendiri. `ppdb/portal` menjaga `registrations/:id` tetap berada di bawah
 * cangkang portal, dan `ppdb/:slug` dipasang terakhir agar tidak menelan rute
 * statis seperti `ppdb/register`.
 */
export const ppdbPublicRoutes: RouteObject[] = [
  { path: 'ppdb', element: <PpdbPublicLandingPage /> },
  { path: 'ppdb/register', element: <PpdbRegisterPage /> },
  { path: 'ppdb/login', element: <PpdbLoginPage /> },
  {
    path: 'ppdb/portal',
    element: <PpdbPortalLayout />,
    children: [
      { index: true, element: <PpdbPortalDashboardPage /> },
      { path: 'profil', element: <PpdbPortalProfilePage /> },
      { path: 'applicants', element: <PpdbPortalApplicantsPage /> },
      { path: 'pendaftaran', element: <PpdbPortalRegistrationListPage /> },
      { path: 'registrations/:id', element: <PpdbRegistrationWizardPage /> },
      { path: 'registrations/:id/seleksi', element: <PpdbPortalSelectionPage /> },
      { path: 'notifikasi', element: <PpdbPortalNotificationsPage /> },
    ],
  },
  { path: 'ppdb/:slug', element: <PpdbPublicPeriodPage /> },
  { path: 'ppdb/*', element: <PpdbNotFoundPage /> },
];

export default ppdbPublicRoutes;
