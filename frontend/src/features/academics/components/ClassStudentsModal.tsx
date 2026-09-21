import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2, UserPlus } from 'lucide-react';
import { toast } from 'sonner';
import { classesApi } from '../api/academicsApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useStudentOptions } from '@/hooks/useLookupOptions';
import { getErrorMessage } from '@/lib/axios';
import type { SchoolClass } from '@/types';

export function ClassStudentsModal({
  open,
  schoolClass,
  canEdit,
  onClose,
}: {
  open: boolean;
  schoolClass: SchoolClass | null;
  canEdit: boolean;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [selected, setSelected] = useState<string | number>('');
  const classId = schoolClass?.id ?? 0;

  const { options: studentOptions } = useStudentOptions();

  const studentsQuery = useQuery({
    queryKey: ['class-students', classId],
    enabled: open && classId > 0,
    queryFn: () => classesApi.students(classId),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['class-students', classId] });
    queryClient.invalidateQueries({ queryKey: ['classes'] });
  };

  const addMutation = useMutation({
    mutationFn: (studentId: number) => classesApi.addStudents(classId, [studentId]),
    onSuccess: () => {
      toast.success('Siswa ditambahkan ke kelas');
      setSelected('');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const removeMutation = useMutation({
    mutationFn: (studentId: number) => classesApi.removeStudent(classId, studentId),
    onSuccess: () => {
      toast.success('Siswa dikeluarkan dari kelas');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const items = studentsQuery.data ?? [];
  const enrolledIds = items.map((i) => i.student?.id);
  const availableOptions = studentOptions.filter((o) => !enrolledIds.includes(Number(o.value)));

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={schoolClass ? `Rombel — ${schoolClass.name}` : 'Rombel'}
      size="lg"
      footer={<Button variant="outline" onClick={onClose}>Tutup</Button>}
    >
      <div className="space-y-4">
        {canEdit && (
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div className="flex-1">
              <label className="mb-1 block text-sm font-medium text-slate-700">Tambah Siswa</label>
              <SearchSelect
                options={availableOptions}
                value={selected}
                onChange={(v) => setSelected(v)}
                placeholder="-- Cari & pilih siswa --"
              />
            </div>
            <Button
              disabled={!selected}
              loading={addMutation.isPending}
              onClick={() => selected && addMutation.mutate(Number(selected))}
            >
              <UserPlus className="h-4 w-4" /> Tambah
            </Button>
          </div>
        )}

        {studentsQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
            <Spinner /> Memuat...
          </div>
        ) : items.length === 0 ? (
          <EmptyState title="Belum ada siswa" description="Tambahkan siswa ke rombel kelas ini." />
        ) : (
          <div className="overflow-hidden rounded-lg border border-slate-200">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2.5 font-medium">NIS</th>
                  <th className="px-4 py-2.5 font-medium">Nama Siswa</th>
                  <th className="px-4 py-2.5 text-center font-medium">Status</th>
                  {canEdit && <th className="px-4 py-2.5 text-right font-medium">Aksi</th>}
                </tr>
              </thead>
              <tbody>
                {items.map((it) => (
                  <tr key={it.class_student_id} className="border-b border-slate-50 hover:bg-slate-50/60">
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{it.student?.nis}</td>
                    <td className="px-4 py-2.5 text-slate-700">{it.student?.full_name}</td>
                    <td className="px-4 py-2.5 text-center">
                      <Badge tone={it.status === 'aktif' ? 'green' : 'amber'}>{it.status}</Badge>
                    </td>
                    {canEdit && (
                      <td className="px-4 py-2.5 text-right">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="text-red-500 hover:bg-red-50"
                          onClick={() => it.student && removeMutation.mutate(it.student.id)}
                          title="Keluarkan"
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </Modal>
  );
}
