import { LmsDashboardView } from '../components/dashboard/LmsDashboards';

/**
 * Dashboard LMS per peran (§26–§29).
 *
 * Guru, siswa, wali, dan admin memakai satu entry point yang sama; isi
 * dashboard ditentukan backend berdasarkan identitas pemanggil sehingga tidak
 * ada percabangan hak di sisi klien.
 */
export default function LmsDashboardPage() {
  return <LmsDashboardView />;
}
