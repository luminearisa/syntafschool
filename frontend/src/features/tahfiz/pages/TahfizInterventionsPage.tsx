import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Repeat, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { interventionsApi } from '../api/tahfizApi';
import {
  InterventionFormModal,
} from '../components/InterventionFormModal';
import {
  InterventionTransitionModal,
  type InterventionTransitionPayload,
} from '../components/InterventionTransitionModal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useHalaqahOptions, useParticipantOptions } from '../hooks/useTahfizOptions';
import {
  INTERVENTION_STATUS_LABELS,
  INTERVENTION_TRANSITIONS,
  INTERVENTION_TYPE_LABELS,
  interventionStatusTone,
} from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizIntervention,
  TahfizInterventionPayload,
  TahfizInterventionStatus,
  TahfizInterventionType,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const isFinished = (status: TahfizInterventionStatus) => status === 'done' || status === 'cancelled';

/**
 * Daftar intervensi peserta.
 *
 * Intervensi yang sudah selesai/dibatalkan bersifat final — tidak dapat
 * diubah maupun ditransisikan lagi.
 */
export function TahfizInterventionsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [participantId, setParticipantId] = useState<number | ''>('');
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [type, setType] = useState<TahfizInterventionType | ''>('');
  const [status, setStatus] = useState<TahfizInterventionStatus | ''>('');
  const [openOnly, setOpenOnly] = useState(false);
  const [dueBefore, setDueBefore] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizIntervention | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [transitioning, setTransitioning] = useState<TahfizIntervention | null>(null);
  const [transitionErrors, setTransitionErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizIntervention | null>(null);

  const { options: participantOptions } = useParticipantOptions();
  const { options: halaqahOptions } = useHalaqahOptions();

  const filters: Record<string, string | number | boolean> = {};
  if (participantId) filters.participant_id = participantId;
  if (halaqahId) filters.halaqah_id = halaqahId;
  if (type) filters.type = type;
  if (status) filters.status = status;
  if (openOnly) filters.open = 1;
  if (dueBefore) filters.due_before = dueBefore;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'interventions', page, filters],
    queryFn: () =>
      interventionsApi.list({
        page,
        per_page: 15,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'interventions'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizInterventionPayload) =>
      editing ? interventionsApi.update(editing.id, payload) : interventionsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Intervensi diperbarui' : 'Intervensi dibuat');
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
    mutationFn: ({ id, payload }: { id: number; payload: InterventionTransitionPayload }) =>
      interventionsApi.transition(id, payload),
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

  const deleteMutation = useMutation({
    mutationFn: (id: number) => interventionsApi.remove(id),
    onSuccess: () => {
      toast.success('Intervensi dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Intervensi Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Tindakan perbaikan bagi peserta yang membutuhkan pendampingan khusus.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.update">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Intervensi
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Peserta</label>
            <SearchSelect
              options={participantOptions}
              value={participantId}
              onChange={(v) => {
                setParticipantId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua peserta --"
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
              placeholder="-- Semua halaqah --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis</label>
            <Select
              value={type}
              onChange={(e) => {
                setType(e.target.value as TahfizInterventionType | '');
                setPage(1);
              }}
            >
              <option value="">Semua jenis</option>
              {(Object.keys(INTERVENTION_TYPE_LABELS) as TahfizInterventionType[]).map((key) => (
                <option key={key} value={key}>
                  {INTERVENTION_TYPE_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as TahfizInterventionStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {(Object.keys(INTERVENTION_STATUS_LABELS) as TahfizInterventionStatus[]).map((key) => (
                <option key={key} value={key}>
                  {INTERVENTION_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Jatuh Tempo Sebelum</label>
            <Input
              type="date"
              value={dueBefore}
              onChange={(e) => {
                setDueBefore(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="flex items-end">
            <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={openOnly}
                onChange={(e) => {
                  setOpenOnly(e.target.checked);
                  setPage(1);
                }}
              />
              Hanya yang masih berjalan
            </label>
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat intervensi...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat intervensi"
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
            title="Belum ada intervensi"
            description="Tambahkan intervensi untuk peserta yang membutuhkan pendampingan."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Peserta</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 font-medium">Alasan</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Jatuh Tempo</th>
                  <th className="px-4 py-2 font-medium">Selesai</th>
                  <th className="px-4 py-2 font-medium">Hasil</th>
                  <th className="px-4 py-2 font-medium">Ditugaskan</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-medium text-slate-800">
                      {row.participant?.name ?? `#${row.participant_id}`}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.type_label}</td>
                    <td className="max-w-[16rem] px-4 py-2 text-slate-600">
                      <span className="line-clamp-2">{row.reason}</span>
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={interventionStatusTone(row.status)}>{row.status_label}</Badge>
                    </td>
                    <td className="px-4 py-2">
                      {row.due_date ? (
                        <span
                          className={
                            row.is_overdue ? 'font-medium text-red-600' : 'text-slate-600'
                          }
                          title={row.is_overdue ? 'Terlambat' : undefined}
                        >
                          {row.due_date}
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.completed_at ?? '—'}</td>
                    <td className="max-w-[12rem] px-4 py-2 text-slate-600">
                      <span className="line-clamp-2">{row.result ?? '—'}</span>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-500">
                      {row.assigned_by_user?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2">
                      <Can perm="tahfiz.update" fallback={<span className="text-xs text-slate-400">—</span>}>
                        <div className="flex flex-wrap gap-1">
                          <Button
                            variant="ghost"
                            size="icon"
                            title={isFinished(row.status) ? 'Intervensi sudah final' : 'Edit'}
                            disabled={isFinished(row.status)}
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
                            title={
                              INTERVENTION_TRANSITIONS[row.status].length === 0
                                ? 'Tidak ada transisi'
                                : 'Transisi status'
                            }
                            disabled={INTERVENTION_TRANSITIONS[row.status].length === 0}
                            onClick={() => {
                              setTransitionErrors({});
                              setTransitioning(row);
                            }}
                          >
                            <Repeat className="h-3.5 w-3.5" />
                          </Button>
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

      <InterventionFormModal
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

      <InterventionTransitionModal
        open={!!transitioning}
        intervention={transitioning}
        submitting={transitionMutation.isPending}
        serverErrors={transitionErrors}
        onClose={() => setTransitioning(null)}
        onSubmit={(payload) => {
          if (transitioning) transitionMutation.mutate({ id: transitioning.id, payload });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Intervensi"
        message={`Hapus intervensi "${deleting?.type_label ?? ''}" untuk ${
          deleting?.participant?.name ?? 'peserta'
        }? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}