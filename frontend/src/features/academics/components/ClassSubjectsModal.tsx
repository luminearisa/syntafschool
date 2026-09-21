import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookPlus, Copy, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { classesApi } from '../api/academicsApi';
import { classSubjectsApi } from '../api/classSubjectsApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useOptions } from '@/hooks/useOptions';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { getErrorMessage } from '@/lib/axios';
import type { ClassSubject, SchoolClass } from '@/types';

const CATEGORY_TONES: Record<string, 'brand' | 'amber' | 'blue'> = {
  Wajib: 'brand',
  'Muatan Lokal': 'amber',
  Pilihan: 'blue',
};

export function ClassSubjectsModal({
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
  const classId = schoolClass?.id ?? 0;

  const [subjectToAdd, setSubjectToAdd] = useState<string | number>('');
  const [teacherToAdd, setTeacherToAdd] = useState<string | number>('');
  const [showCopy, setShowCopy] = useState(false);
  const [copySource, setCopySource] = useState<string | number>('');

  const { options: subjectOptions } = useOptions('subjects');
  const { options: teacherOptions } = useEmployeeOptions(true);

  const subjectsQuery = useQuery({
    queryKey: ['class-subjects', classId],
    enabled: open && classId > 0,
    queryFn: () => classSubjectsApi.list(classId),
  });

  const classesQuery = useQuery({
    queryKey: ['classes', 'all-for-copy'],
    enabled: open,
    staleTime: 1000 * 60 * 5,
    queryFn: () => classesApi.list({ all: true, per_page: 200 }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['class-subjects', classId] });
    queryClient.invalidateQueries({ queryKey: ['classes'] });
  };

  const addMutation = useMutation({
    mutationFn: () =>
      classSubjectsApi.add(classId, [
        {
          subject_id: Number(subjectToAdd),
          employee_id: teacherToAdd ? Number(teacherToAdd) : null,
        },
      ]),
    onSuccess: (res) => {
      toast.success(res.message);
      setSubjectToAdd('');
      setTeacherToAdd('');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const teacherMutation = useMutation({
    mutationFn: ({ id, employeeId }: { id: number; employeeId: number | null }) =>
      classSubjectsApi.setTeacher(classId, id, employeeId),
    onSuccess: () => {
      toast.success('Guru pengajar diperbarui');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => classSubjectsApi.remove(classId, id),
    onSuccess: () => {
      toast.success('Mapel dikeluarkan dari kelas');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const copyMutation = useMutation({
    mutationFn: () => classSubjectsApi.copyFrom(classId, Number(copySource)),
    onSuccess: (res) => {
      toast.success(res.message);
      setCopySource('');
      setShowCopy(false);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const items = subjectsQuery.data ?? [];

  const availableSubjectOptions = useMemo(() => {
    const taken = new Set(items.map((i) => i.subject_id));
    return subjectOptions.filter((o) => !taken.has(Number(o.value)));
  }, [subjectOptions, items]);

  const classOptions = useMemo(
    () =>
      (classesQuery.data?.data ?? [])
        .filter((c) => c.id !== classId)
        .map((c) => ({ value: c.id, label: `${c.name} (${c.code})` })),
    [classesQuery.data, classId],
  );

  const totalCredit = items.reduce((sum, i) => {
    const credit = Number(i.subject?.credit ?? 0);
    return sum + (Number.isNaN(credit) ? 0 : credit);
  }, 0);

  const teacherBySubject = new Map(items.map((i) => [i.id, i]));

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={schoolClass ? `Mapel — ${schoolClass.name}` : 'Mapel Kelas'}
      size="xl"
      footer={<Button variant="outline" onClick={onClose}>Tutup</Button>}
    >
      <div className="space-y-4">
        {canEdit && (
          <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
              <div className="flex-1">
                <label className="mb-1 block text-sm font-medium text-slate-700">Mata Pelajaran</label>
                <SearchSelect
                  options={availableSubjectOptions}
                  value={subjectToAdd}
                  onChange={(v) => setSubjectToAdd(v)}
                  placeholder="-- Cari & pilih mapel --"
                />
              </div>
              <div className="flex-1">
                <label className="mb-1 block text-sm font-medium text-slate-700">
                  Guru Pengajar <span className="font-normal text-slate-400">(opsional)</span>
                </label>
                <SearchSelect
                  options={teacherOptions}
                  value={teacherToAdd}
                  onChange={(v) => setTeacherToAdd(v)}
                  placeholder="-- Pilih guru --"
                />
              </div>
              <Button
                disabled={!subjectToAdd}
                loading={addMutation.isPending}
                onClick={() => addMutation.mutate()}
              >
                <BookPlus className="h-4 w-4" /> Tambah
              </Button>
            </div>

            <div className="border-t border-slate-200 pt-2">
              {showCopy ? (
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                  <div className="flex-1">
                    <label className="mb-1 block text-sm font-medium text-slate-700">
                      Salin mapel dari kelas
                    </label>
                    <SearchSelect
                      options={classOptions}
                      value={copySource}
                      onChange={(v) => setCopySource(v)}
                      placeholder="-- Pilih kelas sumber --"
                    />
                  </div>
                  <Button
                    variant="outline"
                    disabled={!copySource}
                    loading={copyMutation.isPending}
                    onClick={() => copyMutation.mutate()}
                  >
                    Salin
                  </Button>
                  <Button variant="ghost" onClick={() => setShowCopy(false)}>Batal</Button>
                </div>
              ) : (
                <Button variant="ghost" size="sm" onClick={() => setShowCopy(true)}>
                  <Copy className="h-4 w-4" /> Salin mapel dari kelas lain
                </Button>
              )}
            </div>
          </div>
        )}

        {subjectsQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
            <Spinner /> Memuat...
          </div>
        ) : items.length === 0 ? (
          <EmptyState
            title="Belum ada mapel"
            description="Tambahkan mata pelajaran yang diajarkan di kelas ini."
          />
        ) : (
          <>
            <div className="overflow-hidden rounded-lg border border-slate-200">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-2.5 font-medium">Mata Pelajaran</th>
                    <th className="px-4 py-2.5 font-medium">Kategori</th>
                    <th className="px-4 py-2.5 text-center font-medium">JP</th>
                    <th className="px-4 py-2.5 font-medium">Guru Pengajar</th>
                    {canEdit && <th className="px-4 py-2.5 text-right font-medium">Aksi</th>}
                  </tr>
                </thead>
                <tbody>
                  {items.map((item: ClassSubject) => (
                    <tr key={item.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                      <td className="px-4 py-2.5">
                        <div className="text-slate-700">{item.subject?.name ?? '-'}</div>
                        <div className="font-mono text-xs text-slate-400">
                          {item.subject?.code ?? ''}
                        </div>
                      </td>
                      <td className="px-4 py-2.5">
                        <Badge tone={CATEGORY_TONES[item.subject?.category ?? ''] ?? 'gray'}>
                          {item.subject?.category ?? '-'}
                        </Badge>
                      </td>
                      <td className="px-4 py-2.5 text-center text-slate-600">
                        {item.subject?.credit != null ? Number(item.subject.credit) : '-'}
                      </td>
                      <td className="px-4 py-2.5">
                        {canEdit ? (
                          <SearchSelect
                            options={teacherOptions}
                            value={teacherBySubject.get(item.id)?.employee_id ?? ''}
                            onChange={(v) =>
                              teacherMutation.mutate({
                                id: item.id,
                                employeeId: v === '' ? null : Number(v),
                              })
                            }
                            placeholder="-- Belum ada guru --"
                            className="min-w-[180px]"
                          />
                        ) : (
                          <span className="text-slate-600">
                            {item.teacher?.full_name ?? '-'}
                          </span>
                        )}
                      </td>
                      {canEdit && (
                        <td className="px-4 py-2.5 text-right">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="text-red-500 hover:bg-red-50"
                            onClick={() => removeMutation.mutate(item.id)}
                            title="Keluarkan mapel"
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

            <div className="flex items-center justify-between text-xs text-slate-500">
              <span>{items.length} mata pelajaran</span>
              <span>
                Total JP: <span className="font-medium text-slate-700">{totalCredit}</span>
              </span>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}
