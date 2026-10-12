import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { Settings2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Can } from '@/components/common/Can';
import { StudentDetailModal } from '@/features/students/components/StudentDetailModal';
import { ProfileShell } from '@/features/profile/components/ProfileShell';

/** Profil 360° siswa. Pengelolaan wali, riwayat, dan akun memakai dialog yang sudah ada. */
export function StudentProfilePage() {
  const { studentId } = useParams();
  const id = Number(studentId);
  const qc = useQueryClient();
  const [manage, setManage] = useState(false);

  return (
    <>
      <ProfileShell
        type="students"
        id={id}
        backTo="/students"
        backLabel="Data Siswa"
        actions={
          <Can perm="student.update">
            <Button variant="outline" onClick={() => setManage(true)}>
              <Settings2 className="h-4 w-4" /> Kelola wali & akun
            </Button>
          </Can>
        }
      />
      <StudentDetailModal
        open={manage}
        studentId={manage ? id : null}
        onClose={() => {
          setManage(false);
          qc.invalidateQueries({ queryKey: ['profile', 'students', id] });
        }}
      />
    </>
  );
}
