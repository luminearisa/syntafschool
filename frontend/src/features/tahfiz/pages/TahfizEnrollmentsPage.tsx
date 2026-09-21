import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, GitBranch, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { enrollmentsApi } from '../api/tahfizApi';
import { EnrollmentFormModal } from '../components/EnrollmentFormModal';
import { EnrollmentTransitionModal } from '../components/EnrollmentTransitionModal';
import {
  ENROLLMENT_STATUS_LABELS,
  enrollmentStatusTone,
  toOptions,
} from '../lib/labels';
import { useProgramOptions, useHalaqahOptions } from '../hooks/useTahfizOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizEnrollment,
  TahfizEnrollmentPayload,
  TahfizEnrollmentStatus,
  TahfizEnrollmentTransitionPayload,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Daftar pendaftaran program Tahfiz beserta alur transisi statusnya. */
export function TahfizEnrollmentsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<TahfizEnrollmentStatus | ''>('');
  const [programId, setProgramId] = useState<number | ''>('');
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [search, setSearch] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizEnrollment | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [transitioning, setTransitioning] = useState<TahfizEnrollment | null>(null);
  const [transitionErrors, setTransitionErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizEnrollment | null>(null);

  const { options: programOptions } = useProgramOptions();
  const { options: halaqahOptions } = useHalaqahOptions(programId);

  const filters = {
    ...(status ? { status } : {}),
    ...(programId ? { program_id: programId } : {}),
    ...(halaqahId ? { halaqah_id: halaqahId } : {}),
  };

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'enrollments', page, filters, search],
    queryFn: () =>
      enrollmentsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'enrollments'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizEnrollmentPayload) =>
      editing ? enrollmentsApi.update(editing.id, payload) : enrollmentsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Pendaftaran diperbarui' : 'Pendaftaran dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const transitionMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: TahfizEnrollmentTransitionPayload }) =>
      enrollmentsApi.transition(id, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setTransitioning(null);
      setTransitionErrors({});
      invalidate();
    },
    onError: (err) => {
      setTransitionErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const approveMutation = useMutation({
    mutationFn: (id: number) => enrollmentsApi.approve(id),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => enrollmentsApi.remove(id),
    onSuccess: () => {
      toast.success('Pendaftaran dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pendaftaran Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Pendaftaran peserta ke program, dengan alur pengajuan hingga aktif.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.create">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Pendaftaran
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as TahfizEnrollmentStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {toOptions(ENROLLMENT_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Program</label>
            <SearchSelect
              options={programOptions}
              value={programId}
              onChange={(v) => {
                setProgramId(toId(v));
                setHalaqahId('');
                setPage(1);
              }}
              placeholder="-- Semua --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Halaqah</label>
            <SearchSelect
              options={halaqahOptions}
              value={halaqahId}
              onChange={(v) => {
                setHalaqahId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari kode pendaftaran..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat pendaftaran...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat pendaftaran"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada pendaftaran"
            description="Tambahkan pendaftaran peserta ke program Tahfiz."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Peserta</th>
                  <th className="px-4 py-2 font-medium">Program</th>
                  <th className="px-4 py-2 font-medium">Level</th>
                  <th className="px-4 py-2 font-medium">Halaqah</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Mulai</th>
                  <th className="px-3 py-2 text-center font-medium">Berjalan</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">
                      {row.participant?.name ?? '-'}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.program?.name ?? '-'}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{row.level?.name ?? '—'}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{row.halaqah?.name ?? '—'}</td>
                    <td className="px-4 py-2">
                      <Badge tone={enrollmentStatusTone(row.status)}>
                        {ENROLLMENT_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{row.start_date ?? '—'}</td>
                    <td className="px-3 py-2 text-center">
                      {row.is_ongoing ? (
                        <Badge tone="green">Ya</Badge>
                      ) : (
                        <span className="text-xs text-slate-400">Tidak</span>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <Can
                        perm={['tahfiz.update', 'tahfiz.approve', 'tahfiz.delete']}
                        fallback={<span className="text-xs text-slate-400">—</span>}
                      >
                        <div className="flex flex-wrap gap-1">
                          <Can perm="tahfiz.approve">
                            {row.status === 'registered' && (
                              <Button
                                size="sm"
                                variant="outline"
                                loading={approveMutation.isPending}
                                onClick={() => approveMutation.mutate(row.id)}
                              >
                                <CheckCircle2 className="h-3.5 w-3.5" /> Setujui
                              </Button>
                            )}
                          </Can>
                          <Can perm="tahfiz.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Edit"
                              onClick={() => {
                                setEditing(row);
                                setServerErrors({});
                                setFormOpen(true);
                              }}
                            >
                              <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Transisi status"
                              onClick={() => {
                                setTransitionErrors({});
                                setTransitioning(row);
                              }}
                            >
                              <GitBranch className="h-3.5 w-3.5" />
                            </Button>
                          </Can>
                          <Can perm="tahfiz.delete">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Hapus"
                              onClick={() => setDeleting(row)}
                            >
                              <Trash2 className="h-3.5 w-3.5 text-red-500" />
                            </Button>
                          </Can>
                        </div>
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <EnrollmentFormModal
        open={formOpen}
        editing={editing}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <EnrollmentTransitionModal
        open={!!transitioning}
        enrollment={transitioning}
        submitting={transitionMutation.isPending}
        serverErrors={transitionErrors}
        onClose={() => setTransitioning(null)}
        onSubmit={(payload) => {
          if (transitioning) transitionMutation.mutate({ id: transitioning.id, payload });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Pendaftaran"
        message={`Hapus pendaftaran "${deleting?.code ?? ''}"? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}