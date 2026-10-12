import { useParams } from 'react-router-dom';
import { ProfileShell } from '@/features/profile/components/ProfileShell';

/** Profil 360° pegawai: kepegawaian, mengajar, kehadiran, cuti, gaji, kinerja, peran. */
export function EmployeeProfilePage() {
  const { employeeId } = useParams();

  return <ProfileShell type="employees" id={Number(employeeId)} backTo="/employees" backLabel="Data Pegawai" />;
}
