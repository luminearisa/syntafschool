import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardList, Pencil, Plus, RefreshCw, Search, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { assessmentsApi } from '../api/assessmentsApi';
import { AssessmentFormModal } from '../components/AssessmentFormModal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { AssessmentPayload, AssessmentRow } from '../types';

const STATUS_OPTIONS = [
  { value: '', label: 'Semua status' },
  { value: 'draft', label: 'Draft' },
  { value: 'submitted', label: 'Diajukan' },
  { value: 'verified', label: 'Diverifikasi' },
  { value: 'finalized', label: 'Final' },
  { value: 'revision_requested', label: 'Revisi diminta' },
];

const statusTone = (status: string): 'green' | 'amber' | 'blue' | 'red' | 'gray' => {
  if (status === 'finalized') return 'green';
  if (status === 'verified') return 'blue';
  if (status === 'submitted') return 'amber';
  if (status === 'revision_requested') return 'red';
  return 'gray';
};

const statusLabel = (status: string) => ({
  draft: 'Draft', submitted: 'Diajukan', verified: 'Diverifikasi', finalized: 'Final', revision_requested: 'Revisi diminta',
}[status] ?? status);

export function AssessmentsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<AssessmentRow | null>(null);
  const [deleting, setDeleting] = useState<AssessmentRow | null>(null);

  const query = useQuery({
    queryKey: ['assessments', page, status, search],
    queryFn: () => assessmentsApi.list({ page, per_page: 15, status, search }),
  });
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['assessments'] });

  const createMutation = useMutation({
    mutationFn: (payload: AssessmentPayload) => assessmentsApi.create(payload),
    onSuccess: () => { toast.success('Penilaian berhasil dibuat'); setFormOpen(false); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: AssessmentPayload }) => assessmentsApi.update(id, payload),
    onSuccess: () => { toast.success('Penilaian berhasil diperbarui'); setFormOpen(false); setEditing(null); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const deleteMutation = useMutation({
    mutationFn: (id: number) => assessmentsApi.remove(id),
    onSuccess: () => { toast.success('Penilaian berhasil dihapus'); setDeleting(null); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const applySearch = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  };
  const openCreate = () => { setEditing(null); setFormOpen(true); };
  const openEdit = (row: AssessmentRow) => { setEditing(row); setFormOpen(true); };
  const rows = query.data?.data ?? [];
  const mutationError = createMutation.error || updateMutation.error;
  const serverErrors = getValidationErrors(mutationError);

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Penilaian</h1>
          <p className="text-sm text-slate-500">Susun penilaian per kelas dan mata pelajaran, input nilai, lalu ajukan untuk verifikasi.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button size="icon" variant="ghost" title="Muat ulang" onClick={() => query.refetch()}><RefreshCw className={query.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button>
          <Can perm="assessment.create"><Button onClick={openCreate}><Plus className="h-4 w-4" /> Buat Penilaian</Button></Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <form onSubmit={applySearch} className="flex flex-1 items-center gap-2">
            <div className="relative w-full max-w-sm"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari nama penilaian..." className="pl-9" /></div>
            <Button type="submit" variant="outline">Cari</Button>
          </form>
          <div className="w-full sm:w-52"><Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>{STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select></div>
        </div>
      </Card>

      <Card>
        {query.isLoading ? <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat daftar penilaian...</div>
          : query.isError ? <EmptyState title="Gagal memuat penilaian" description={getErrorMessage(query.error)} action={<Button size="sm" variant="outline" className="mt-2" onClick={() => query.refetch()}>Coba lagi</Button>} />
            : rows.length === 0 ? <EmptyState title="Belum ada penilaian" description={search ? 'Coba kata kunci lain.' : 'Buat penilaian pertama untuk memulai input nilai.'} action={<Can perm="assessment.create"><Button size="sm" className="mt-2" onClick={openCreate}><Plus className="h-4 w-4" /> Buat Penilaian</Button></Can>} />
              : <div className="overflow-x-auto"><table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Penilaian</th><th className="px-4 py-3">Kelas / Mapel</th><th className="px-4 py-3">Jenis / Semester</th><th className="px-4 py-3">Tanggal</th><th className="px-4 py-3 text-center">Nilai</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody>{rows.map((row) => <tr key={row.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                  <td className="px-4 py-3"><div className="font-medium text-slate-800">{row.name}</div><div className="text-xs text-slate-400">Maks. {row.max_score}{row.weight !== null ? ` · Bobot ${row.weight}%` : ''}</div></td>
                  <td className="px-4 py-3"><div className="font-medium text-slate-700">{row.school_class?.name ?? '—'}</div><div className="text-xs text-slate-500">{row.subject?.name ?? '—'}</div></td>
                  <td className="px-4 py-3"><div className="text-slate-700">{row.type?.name ?? '—'}</div><div className="text-xs text-slate-400">{row.semester?.name ?? '—'}</div></td>
                  <td className="px-4 py-3 text-slate-600">{row.date ?? '—'}</td>
                  <td className="px-4 py-3 text-center text-slate-700">{row.graded_count}/{row.scores_count || '—'}</td>
                  <td className="px-4 py-3"><Badge tone={statusTone(row.status)}>{statusLabel(row.status)}</Badge></td>
                  <td className="px-4 py-3"><div className="flex justify-end gap-1">
                    <Link to={`/assessments/${row.id}/grades`} className="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-xs font-medium text-brand-700 hover:bg-brand-50"><ClipboardList className="h-4 w-4" /> Nilai</Link>
                    {!row.is_locked && <Can perm="assessment.update"><Button size="icon" variant="ghost" title="Edit penilaian" onClick={() => openEdit(row)}><Pencil className="h-4 w-4" /></Button></Can>}
                    {!row.is_locked && <Can perm="assessment.delete"><Button size="icon" variant="ghost" title="Hapus penilaian" onClick={() => setDeleting(row)}><Trash2 className="h-4 w-4 text-red-500" /></Button></Can>}
                  </div></td>
                </tr>)}</tbody>
              </table></div>}
        {query.data?.meta && <Pagination meta={query.data.meta} onPageChange={setPage} />}
      </Card>

      <AssessmentFormModal
        open={formOpen}
        editing={editing}
        submitting={createMutation.isPending || updateMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        onSubmit={(payload) => editing ? updateMutation.mutate({ id: editing.id, payload }) : createMutation.mutate(payload)}
      />
      <ConfirmDialog
        open={!!deleting}
        title="Hapus Penilaian"
        message={`Hapus penilaian ${deleting?.name ?? ''}? Skor terkait juga akan dihapus.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => { if (deleting) deleteMutation.mutate(deleting.id); }}
      />
    </div>
  );
}
