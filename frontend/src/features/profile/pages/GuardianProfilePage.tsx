import { useParams } from 'react-router-dom';
import { ProfileShell } from '@/features/profile/components/ProfileShell';

/** Profil 360° wali murid: anak-anak, keuangan seluruh anak, persetujuan, akun. */
export function GuardianProfilePage() {
  const { guardianId } = useParams();

  return <ProfileShell type="guardians" id={Number(guardianId)} backTo="/students/guardians" backLabel="Wali Murid" />;
}
