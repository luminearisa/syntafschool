import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus, Pencil, Trash2, Search, Power, Eye, Users } from 'lucide-react';
import { toast } from 'sonner';
import { studentsApi } from '@/features/students/api/studentsApi';
import { StudentFormModal } from '@/features/students/components/StudentFormModal';
import { academicsApi, classesApi } from '@/features/academics/api/academicsApi';
import { Can } from '@/components/common/Can';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { Student, StudentPayload } from '@/types';

export function StudentsPage() {
  const qc = useQueryClient();
  const navigate = useNavigate();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<number | ''>('');
  const [genderFilter, setGenderFilter] = useState<number | ''>('');
  const [activeFilter, setActiveFilter] = useState('');
  const [classFilter, setClassFilter] = useState<number | ''>('');
  const [unassigned, setUnassigned] = useState(false);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Student | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [deleteTarget, setDeleteTarget] = useState<Student | null>(null);

  const { options: statusOptions } = useOptions('student-statuses');
  const { options: genderOptions } = useOptions('genders');

  const periodQuery = useQuery({
    queryKey: ['academic', 'active'],
    staleTime: 1000 * 60 * 10,
    queryFn: () => academicsApi.activePeriod(),
  });

  const activeYearId = periodQuery.data?.academic_year?.id;

  const classQuery = useQuery({
    queryKey: ['classes', 'options', activeYearId],
    staleTime: 1000 * 60 * 5,
    queryFn: () =>
      classesApi.list({
        all: true,
        ...(activeYearId ? { filter: { academic_year_id: activeYearId } } : {}),
      }),
  });

  const listQuery = useQuery({
    queryKey: [
      'students',
      { page, search, statusFilter, genderFilter, activeFilter, classFilter, unassigned },
    ],
    queryFn: () =>
      studentsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        status_id: statusFilter,
        gender_id: genderFilter,
        is_active: activeFilter === '' ? undefined : activeFilter === 'active',
        class_id: unassigned ? '' : classFilter,
        unassigned: unassigned || undefined,
      }),
  });

  const students = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const classes = classQuery.data?.data ?? [];

  const saveMutation = useMutation({
    mutationFn: (payload: StudentPayload) =>
      editing ? studentsApi.update(editing.id, payload) : studentsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Data siswa diperbarui' : 'Siswa ditambahkan');
      qc.invalidateQueries({ queryKey: ['students'] });
      qc.invalidateQueries({ queryKey: ['lookup', 'students'] });
      closeModal();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const toggleMutation = useMutation({
    mutationFn: (id: number) => studentsApi.toggleActive(id),
    onSuccess: () => {
      toast.success('Status siswa diubah');
      qc.invalidateQueries({ queryKey: ['students'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => studentsApi.remove(id),
    onSuccess: () => {
      toast.success('Siswa dihapus');
      qc.invalidateQueries({ queryKey: ['students'] });
      qc.invalidateQueries({ queryKey: ['lookup', 'students'] });
      setDeleteTarget(null);
    },
    onError: (err) => {
      toast.error(getErrorMessage(err));
      setDeleteTarget(null);
    },
  });

  function openCreate() {
    setEditing(null);
    setErrors({});
    setModalOpen(true);
  }

  function openEdit(student: Student) {
    setEditing(student);
    setErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
    setEditing(null);
  }

  return (
    <PermissionGuard permission="student.view">
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-800">Data Siswa</h1>
            <p className="text-sm text-slate-500">
              Kelola biodata siswa, wilayah berjenjang, wali murid, dan riwayat kelas.
            </p>
          </div>
          <Can perm="student.create">
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" /> Tambah Siswa
            </Button>
          </Can>
        </div>

        <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
          <div className="relative min-w-[220px] flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Cari nama / NIS / NISN..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="w-44">
            <Select
              value={classFilter}
              onChange={(e) => {
                setClassFilter(e.target.value === '' ? '' : Number(e.target.value));
                setUnassigned(false);
                setPage(1);
              }}
            >
              <option value="">Semua Kelas</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="w-44">
            <Select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value === '' ? '' : Number(e.target.value));
                setPage(1);
              }}
            >
              <option value="">Semua Status</option>
              {statusOptions.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="w-40">
            <Select
              value={genderFilter}
              onChange={(e) => {
                setGenderFilter(e.target.value === '' ? '' : Number(e.target.value));
                setPage(1);
              }}
            >
              <option value="">Semua Gender</option>
              {genderOptions.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="w-36">
            <Select
              value={activeFilter}
              onChange={(e) => {
                setActiveFilter(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua</option>
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
            </Select>
          </div>
          <label className="flex cursor-pointer items-center gap-2 pb-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              checked={unassigned}
              onChange={(e) => {
                setUnassigned(e.target.checked);
                setClassFilter('');
                setPage(1);
              }}
            />
            Belum ada kelas
          </label>
        </div>

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          {listQuery.isLoading ? (
            <div className="flex justify-center py-16">
              <Spinner />
            </div>
          ) : students.length === 0 ? (
            <EmptyState title="Belum ada data siswa" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Siswa</th>
                    <th className="px-4 py-3 font-medium">Kelas</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 font-medium">Wali</th>
                    <th className="px-4 py-3 font-medium">Akun</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {students.map((s) => (
                    <tr key={s.id} className="hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <Link to={`/students/${s.id}`} className="font-medium text-slate-800 hover:text-brand-700 hover:underline">
                          {s.full_name}
                        </Link>
                        <p className="text-xs text-slate-500">
                          NIS {s.nis}
                          {s.nisn ? ` · NISN ${s.nisn}` : ''}
                        </p>
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {s.current_class?.name ?? <span className="text-slate-400">Belum ada</span>}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap items-center gap-1">
                          {s.status && <Badge tone="blue">{s.status.name}</Badge>}
                          {s.is_active ? (
                            <Badge tone="green">Aktif</Badge>
                          ) : (
                            <Badge tone="red">Nonaktif</Badge>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span className="inline-flex items-center gap-1 text-xs text-slate-600">
                          <Users className="h-3.5 w-3.5 text-slate-400" />
                          {s.guardians_count ?? 0}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-500">
                        {s.has_account ? 'Ada' : '-'}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex justify-end gap-1">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Buka profil lengkap"
                            onClick={() => navigate(`/students/${s.id}`)}
                          >
                            <Eye className="h-4 w-4" />
                          </Button>
                          <Can perm="student.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title={s.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                              onClick={() => toggleMutation.mutate(s.id)}
                            >
                              <Power className="h-4 w-4" />
                            </Button>
                          </Can>
                          <Can perm="student.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Ubah"
                              onClick={() => openEdit(s)}
                            >
                              <Pencil className="h-4 w-4" />
                            </Button>
                          </Can>
                          <Can perm="student.delete">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Hapus"
                              className="text-red-600 hover:bg-red-50"
                              onClick={() => setDeleteTarget(s)}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </Can>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </div>
      </div>

      <StudentFormModal
        open={modalOpen}
        editing={editing}
        submitting={saveMutation.isPending}
        serverErrors={errors}
        onClose={closeModal}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleteTarget}
        title="Hapus Siswa"
        message={`Yakin ingin menghapus "${deleteTarget?.full_name}"? Tindakan ini tidak dapat dibatalkan.`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        onCancel={() => setDeleteTarget(null)}
      />
    </PermissionGuard>
  );
}

export default StudentsPage;
