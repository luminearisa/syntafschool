import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Trash2, Users } from 'lucide-react';
import { toast } from 'sonner';
import { halaqahsApi } from '../api/tahfizApi';
import { HalaqahFormModal } from '../components/HalaqahFormModal';
import { HALAQAH_STATUS_LABELS, toOptions } from '../lib/labels';
import { useProgramOptions, useLevelOptions } from '../hooks/useTahfizOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Modal } from '@/components/ui/Modal';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { ProgressBar } from '../components/TahfizUi';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizHalaqah,
  TahfizHalaqahPayload,
  TahfizHalaqahStatus,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

function halaqahStatusTone(status: TahfizHalaqahStatus) {
  switch (status) {
    case 'active':
      return 'green' as const;
    case 'merged':
      return 'amber' as const;
    default:
      return 'gray' as const;
  }
}

/** Detail anggota halaqah — dimuat terpisah saat modal dibuka. */
function HalaqahMembersModal({
  halaqah,
  onClose,
}: {
  halaqah: TahfizHalaqah | null;
  onClose: () => void;
}) {
  const detailQuery = useQuery({
    queryKey: ['tahfiz', 'halaqahs', 'detail', halaqah?.id ?? null],
    enabled: !!halaqah,
    queryFn: () => halaqahsApi.show(halaqah!.id),
  });

  const members = detailQuery.data?.members ?? [];

  return (
    <Modal
      open={!!halaqah}
      onClose={onClose}
      title={`Anggota Halaqah — ${halaqah?.name ?? ''}`}
      size="lg"
      footer={
        <Button variant="outline" onClick={onClose}>
          Tutup
        </Button>
      }
    >
      {detailQuery.isLoading ? (
        <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
          <Spinner /> Memuat anggota...
        </div>
      ) : detailQuery.isError ? (
        <EmptyState
          title="Gagal memuat anggota"
          description={getErrorMessage(detailQuery.error)}
        />
      ) : members.length === 0 ? (
        <EmptyState title="Belum ada anggota" description="Halaqah ini belum memiliki anggota." />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-3 py-3 font-medium">No</th>
                <th className="px-3 py-3 font-medium">Nama Peserta</th>
                <th className="px-3 py-3 font-medium">Bergabung</th>
              </tr>
            </thead>
            <tbody>
              {members.map((member, index) => (
                <tr key={member.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-3 py-3 text-slate-500">{index + 1}</td>
                  <td className="px-3 py-3 font-medium text-slate-800">
                    {member.participant?.name ?? `Peserta #${member.participant_id}`}
                  </td>
                  <td className="px-3 py-3 text-xs text-slate-600">{member.joined_at ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  );
}

/** Daftar halaqah (kelompok binaan) dengan kapasitas dan anggotanya. */
export function TahfizHalaqahsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [programId, setProgramId] = useState<number | ''>('');
  const [levelId, setLevelId] = useState<number | ''>('');
  const [status, setStatus] = useState<TahfizHalaqahStatus | ''>('');
  const [search, setSearch] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizHalaqah | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [viewingMembers, setViewingMembers] = useState<TahfizHalaqah | null>(null);
  const [deleting, setDeleting] = useState<TahfizHalaqah | null>(null);

  const { options: programOptions } = useProgramOptions();
  const { options: levelOptions } = useLevelOptions(programId);

  const filters = {
    ...(programId ? { program_id: programId } : {}),
    ...(levelId ? { level_id: levelId } : {}),
    ...(status ? { status } : {}),
  };

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'halaqahs', page, filters, search],
    queryFn: () =>
      halaqahsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'halaqahs'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizHalaqahPayload) =>
      editing ? halaqahsApi.update(editing.id, payload) : halaqahsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Halaqah diperbarui' : 'Halaqah dibuat');
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

  const deleteMutation = useMutation({
    mutationFn: (id: number) => halaqahsApi.remove(id),
    onSuccess: () => {
      toast.success('Halaqah dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Halaqah</h1>
          <p className="text-sm text-slate-500">
            Kelompok binaan hafalan beserta instruktur, musyrif, dan kapasitasnya.
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
              <Plus className="h-4 w-4" /> Tambah Halaqah
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Program</label>
            <SearchSelect
              options={programOptions}
              value={programId}
              onChange={(v) => {
                setProgramId(toId(v));
                setLevelId('');
                setPage(1);
              }}
              placeholder="-- Semua --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Level</label>
            <SearchSelect
              options={levelOptions}
              value={levelId}
              onChange={(v) => {
                setLevelId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as TahfizHalaqahStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {toOptions(HALAQAH_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari nama atau kode halaqah..."
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
            <Spinner /> Memuat halaqah...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat halaqah"
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
            title="Belum ada halaqah"
            description="Tambahkan halaqah pertama untuk mengelompokkan peserta."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Program</th>
                  <th className="px-4 py-2 font-medium">Level</th>
                  <th className="px-4 py-2 font-medium">Instruktur</th>
                  <th className="px-4 py-2 font-medium">Musyrif</th>
                  <th className="px-4 py-2 font-medium">Anggota</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-32 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const capacity = row.capacity ?? 0;
                  const percent = capacity > 0 ? (row.active_members_count / capacity) * 100 : 0;
                  return (
                    <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                      <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
                      <td className="px-4 py-2 font-medium text-slate-800">{row.name}</td>
                      <td className="px-4 py-2 text-slate-600">{row.program?.name ?? '-'}</td>
                      <td className="px-4 py-2 text-xs text-slate-600">{row.level?.name ?? '—'}</td>
                      <td className="px-4 py-2 text-xs text-slate-600">
                        {row.instructor?.full_name ?? '—'}
                      </td>
                      <td className="px-4 py-2 text-xs text-slate-600">
                        {row.musyrif?.full_name ?? '—'}
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex items-center gap-2">
                          <ProgressBar
                            percent={percent}
                            tone={percent >= 100 ? 'red' : 'brand'}
                            className="w-20"
                          />
                          <span className="whitespace-nowrap text-xs text-slate-500">
                            {row.active_members_count}/{row.capacity ?? '∞'}
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-2">
                        <Badge tone={halaqahStatusTone(row.status)}>
                          {HALAQAH_STATUS_LABELS[row.status] ?? row.status}
                        </Badge>
                      </td>
                      <td className="px-4 py-2">
                        <Can
                          perm={['tahfiz.update', 'tahfiz.delete']}
                          fallback={<span className="text-xs text-slate-400">—</span>}
                        >
                          <div className="flex flex-wrap gap-1">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Anggota"
                              onClick={() => setViewingMembers(row)}
                            >
                              <Users className="h-3.5 w-3.5" />
                            </Button>
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
                  );
                })}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <HalaqahFormModal
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

      <HalaqahMembersModal halaqah={viewingMembers} onClose={() => setViewingMembers(null)} />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Halaqah"
        message={`Hapus halaqah "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}