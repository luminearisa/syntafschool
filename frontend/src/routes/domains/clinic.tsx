import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import ClinicDashboardPage from '@/features/clinic/pages/ClinicDashboardPage';
import ClinicPatientsPage from '@/features/clinic/pages/ClinicPatientsPage';
import ClinicVisitsPage from '@/features/clinic/pages/ClinicVisitsPage';
import ClinicVisitWorkspacePage from '@/features/clinic/pages/ClinicVisitWorkspacePage';
import ClinicPharmacyPage from '@/features/clinic/pages/ClinicPharmacyPage';
import ClinicEmergencyPage from '@/features/clinic/pages/ClinicEmergencyPage';
import ClinicReferralPage from '@/features/clinic/pages/ClinicReferralPage';
import ClinicScreeningPage from '@/features/clinic/pages/ClinicScreeningPage';
import ClinicCertificatePage from '@/features/clinic/pages/ClinicCertificatePage';
import ClinicReportPage from '@/features/clinic/pages/ClinicReportPage';
import ClinicPortalPage from '@/features/clinic/pages/ClinicPortalPage';

/**
 * Modul Clinic / UKS.
 *
 * Izin layar ditegakkan backend (`clinic.*`); guard di sini hanya menyembunyikan
 * halaman yang pasti ditolak. Cakupan baris (sekolah / kelas / anak sendiri /
 * diri sendiri) juga ditegakkan backend lewat `ClinicAccessService`, sehingga
 * menembak URL secara langsung tetap berakhir 403.
 *
 * Halaman portal (`/clinic/portal`) adalah wajah untuk orang tua & siswa:
 * baca-saja, isinya disaring kebijakan privasi sekolah.
 */
const guard = (permission: string, element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'clinic', element: guard('clinic.view', <ClinicDashboardPage />) },
  { path: 'clinic/patients', element: guard('clinic.patient.view', <ClinicPatientsPage />) },
  { path: 'clinic/visits', element: guard('clinic.visit.view', <ClinicVisitsPage />) },
  {
    path: 'clinic/visits/:visitId',
    element: guard('clinic.record.view', <ClinicVisitWorkspacePage />),
  },
  { path: 'clinic/pharmacy', element: guard('clinic.prescription.view', <ClinicPharmacyPage />) },
  { path: 'clinic/emergency', element: guard('clinic.emergency.view', <ClinicEmergencyPage />) },
  { path: 'clinic/referrals', element: guard('clinic.referral.view', <ClinicReferralPage />) },
  { path: 'clinic/screening', element: guard('clinic.screening.view', <ClinicScreeningPage />) },
  { path: 'clinic/certificates', element: guard('clinic.certificate.view', <ClinicCertificatePage />) },
  { path: 'clinic/reports', element: guard('clinic.report.view', <ClinicReportPage />) },
  { path: 'clinic/portal', element: guard('clinic.portal.view', <ClinicPortalPage />) },
];

export const clinicMenu: MenuChild[] = [
  { label: 'Dasbor Clinic', to: '/clinic', permission: 'clinic.view' },
  { label: 'Pasien & Rekam Medis', to: '/clinic/patients', permission: 'clinic.patient.view' },
  { label: 'Kunjungan', to: '/clinic/visits', permission: 'clinic.visit.view' },
  { label: 'Farmasi & Obat', to: '/clinic/pharmacy', permission: 'clinic.prescription.view' },
  { label: 'Gawat Darurat & Cedera', to: '/clinic/emergency', permission: 'clinic.emergency.view' },
  { label: 'Rujukan & Tindak Lanjut', to: '/clinic/referrals', permission: 'clinic.referral.view' },
  { label: 'Skrining & Imunisasi', to: '/clinic/screening', permission: 'clinic.screening.view' },
  { label: 'Surat & Dokumen', to: '/clinic/certificates', permission: 'clinic.certificate.view' },
  { label: 'Laporan Klinik', to: '/clinic/reports', permission: 'clinic.report.view' },
  { label: 'Kesehatan Anak (Portal)', to: '/clinic/portal', permission: 'clinic.portal.view' },
];
