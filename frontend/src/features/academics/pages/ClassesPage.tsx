import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookOpen, Pencil, Plus, RefreshCw, Search, Trash2, Users } from 'lucide-react';
import { toast } from 'sonner';
import { classesApi } from '../api/academicsApi';
import { ClassFormModal } from '../components/ClassFormModal';
import { ClassStudentsModal } from '../components/ClassStudentsModal';
import { ClassSubjectsModal } from '../components/ClassSubjectsModal';
import { masterDataApi } from '@/features/master-data/api/masterDataApi';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { AcademicYear, SchoolClass, SchoolClassPayload } from '@/types';

const PER_PAGE = 15;

export function ClassesPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [yearFilter, setYearFilter] = useState<string | number>('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<SchoolClass | null>(null);
  const [deleting, setDeleting] = useState<SchoolClass | null>(null);
  const [rombelFor, setRombelFor] = useState<SchoolClass | null>(null);
  const [subjectsFor, setSubjectsFor] = useState<SchoolClass | null>(null);

  const { options: yearOptions } = useOptions('academic-years');

  const activeYearQuery = useQuery({
    queryKey: ['academic', 'active'],
    queryFn: async () => {
      const res = await masterDataApi.list('academic-years', { filter: { is_active: 1 }, all: true });
      return (res.data?.[0] ?? null) as unknown as AcademicYear | null;
    },
  });

  const queryKey = ['classes', page, search, yearFilter];

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey,
    queryFn: () =>
      classesApi.list({
        page,
        per_page: PER_PAGE,
        search,
        filter: yearFilter ? { academic_year_id: yearFilter } : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['classes'] });

  const createMutation = useMutation({
    mutationFn: (payload: SchoolClassPayload) => classesApi.create(payload),
    onSuccess: () => {
      toast.success('Kelas berhasil dibuat');
      invalidate();
      setFormOpen(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: SchoolClassPayload }) =>
      classesApi.update(id, payload),
    onSuccess: () => {
      toast.success('Kelas berhasil diperbarui');
      invalidate();
      setFormOpen(false);
      setEditing(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => classesApi.remove(id),
    onSuccess: () => {
      toast.success('Kelas berhasil dihapus');
      invalidate();
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];
  const meta = data?.meta;

  const submitForm = (payload: SchoolClassPayload) => {
    if (editing) updateMutation.mutate({ id: editing.id, payload });
    else createMutation.mutate(payload);
  };

  const activeError = createMutation.error || updateMutation.error;
  const serverErrors = getValidationErrors(activeError);

  const openCreate = () => {
    setEditing(null);
    createMutation.reset();
    updateMutation.reset();
    setFormOpen(true);
  };

  const openEdit = (row: SchoolClass) => {
    setEditing(row);
    createMutation.reset();
    updateMutation.reset();
    setFormOpen(true);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kelas & Rombel</h1>
          <p className="text-sm text-slate-500">
            {activeYearQuery.data ? `Tahun ajaran aktif: ${activeYearQuery.data.name}` : 'Kelola kelas dan anggota rombel.'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" onClick={() => refetch()} title="Muat ulang">
            <RefreshCw className={isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="academic.create">
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" /> Tambah Kelas
            </Button>
          </Can>
        </div>
      </div>

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <form
          className="flex flex-1 items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            setPage(1);
            setSearch(searchInput);
          }}
        >
          <div className="relative w-full max-w-xs">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Cari nama/kode kelas..."
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
            />
          </div>
          <Button type="submit" variant="outline">Cari</Button>
        </form>
        <div className="w-full sm:w-56">
          <SearchSelect
            options={yearOptions}
            value={yearFilter}
            onChange={(v) => {
              setPage(1);
              setYearFilter(v);
            }}
            placeholder="Semua Tahun Ajaran"
          />
        </div>
      </div>

      <Card>
        {isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat data...
          </div>
        ) : isError ? (
          <EmptyState
            title="Gagal memuat data"
            description={getErrorMessage(error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => refetch()}>
                Coba lagi
              </Button>
            }
          />
        ) : rows.length === 0 ? (
          <EmptyState
            title="Belum ada kelas"
            description={search ? 'Coba kata kunci lain.' : 'Tambahkan kelas baru untuk memulai.'}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-3 font-medium">Kelas</th>
                  <th className="px-4 py-3 font-medium">Tahun Ajaran</th>
                  <th className="px-4 py-3 font-medium">Wali Kelas</th>
                  <th className="px-4 py-3 text-center font-medium">Siswa</th>
                  <th className="px-4 py-3 text-center font-medium">Mapel</th>
                  <th className="px-4 py-3 text-center font-medium">Kapasitas</th>
                  <th className="px-4 py-3 text-center font-medium">Status</th>
                  <th className="px-4 py-3 text-right font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                    <td className="px-4 py-3">
                      <div className="font-medium text-slate-800">{row.name}</div>
                      <div className="font-mono text-xs text-slate-400">{row.code}</div>
                    </td>
                    <td className="px-4 py-3 text-slate-600">{row.academic_year?.name ?? '-'}</td>
                    <td className="px-4 py-3 text-slate-600">{row.homeroom_teacher?.full_name ?? '-'}</td>
                    <td className="px-4 py-3 text-center text-slate-700">{row.students_count ?? 0}</td>
                    <td className="px-4 py-3 text-center text-slate-700">{row.class_subjects_count ?? 0}</td>
                    <td className="px-4 py-3 text-center text-slate-600">{row.capacity}</td>
                    <td className="px-4 py-3 text-center">
                      {row.is_active ? <Badge tone="green">Aktif</Badge> : <Badge tone="gray">Nonaktif</Badge>}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => setRombelFor(row)}
                          title="Kelola Rombel"
                        >
                          <Users className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => setSubjectsFor(row)}
                          title="Kelola Mapel"
                        >
                          <BookOpen className="h-4 w-4" />
                        </Button>
                        <Can perm="academic.update">
                          <Button variant="ghost" size="icon" onClick={() => openEdit(row)} title="Edit">
                            <Pencil className="h-4 w-4" />
                          </Button>
                        </Can>
                        <Can perm="academic.delete">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="text-red-500 hover:bg-red-50"
                            onClick={() => setDeleting(row)}
                            title="Hapus"
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
      </Card>

      <ClassFormModal
        open={formOpen}
        editing={editing}
        submitting={createMutation.isPending || updateMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={submitForm}
      />

      <ClassStudentsModal
        open={!!rombelFor}
        schoolClass={rombelFor}
        canEdit={can('academic.update')}
        onClose={() => setRombelFor(null)}
      />

      <ClassSubjectsModal
        open={!!subjectsFor}
        schoolClass={subjectsFor}
        canEdit={can('academic.update')}
        onClose={() => setSubjectsFor(null)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Kelas"
        message={`Yakin ingin menghapus kelas "${deleting?.name ?? ''}"? Tindakan ini tidak dapat dibatalkan.`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
