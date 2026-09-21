import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, CalendarPlus, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { sessionsApi } from '../api/tahfizApi';
import { SessionFormModal } from '../components/SessionFormModal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useHalaqahOptions } from '../hooks/useTahfizOptions';
import { ACTIVITY_TYPE_LABELS, SESSION_STATUS_LABELS, sessionStatusTone, toOptions } from '../lib/labels';
import { api, getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { PaginatedResponse } from '@/types';
import type {
  TahfizActivityType,
  TahfizSession,
  TahfizSessionPayload,
  TahfizSessionStatus,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const ACTIVITY_OPTIONS = toOptions(ACTIVITY_TYPE_LABELS);
const STATUS_OPTIONS = toOptions(SESSION_STATUS_LABELS);

function timeRange(row: TahfizSession): string {
  if (!row.start_time && !row.end_time) return '—';
  return `${row.start_time ?? '—'} – ${row.end_time ?? '—'}`;
}

/* ============================ MODAL TRANSISI ============================ */

function TransitionModal({
  session,
  submitting,
  onClose,
  onSubmit,
}: {
  session: TahfizSession | null;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (status: TahfizSessionStatus) => void;
}) {
  const [status, setStatus] = useState<TahfizSessionStatus>('planned');

  useEffect(() => {
    if (session) setStatus(session.status);
  }, [session]);

  return (
    <Modal
      open={!!session}
      onClose={onClose}
      title="Ubah Status Sesi"
      size="sm"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button
            loading={submitting}
            disabled={!session || status === session.status}
            onClick={() => onSubmit(status)}
          >
            Ubah Status
          </Button>
        </>
      }
    >
      <div className="space-y-2">
        <p className="text-sm text-slate-600">
          Status saat ini:{' '}
          <span className="font-medium text-slate-800">
            {session ? SESSION_STATUS_LABELS[session.status] : '—'}
          </span>
        </p>
        <div>
          <Label required>Status Baru</Label>
          <Select
            value={status}
            onChange={(e) => setStatus(e.target.value as TahfizSessionStatus)}
          >
            {STATUS_OPTIONS.map((o) => (
              <option key={String(o.value)} value={String(o.value)}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
      </div>
    </Modal>
  );
}

/* ============================ MODAL GENERATE ============================ */

function GenerateModal({
  open,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: { halaqah_id: number | ''; from: string; to: string }) => void;
}) {
  const { options: halaqahOptions } = useHalaqahOptions();

  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setHalaqahId('');
    setFrom('');
    setTo('');
  }, [open]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!halaqahId) local.halaqah_id = 'Halaqah wajib dipilih';
    if (!from) local.from = 'Tanggal mulai wajib diisi';
    if (!to) local.to = 'Tanggal selesai wajib diisi';
    if (from && to && to < from) local.to = 'Tanggal selesai tidak boleh sebelum tanggal mulai';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit({ halaqah_id: halaqahId, from, to });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Generate Sesi dari Jadwal"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="generate-session-form" loading={submitting}>
            Generate
          </Button>
        </>
      }
    >
      <form id="generate-session-form" onSubmit={handleSubmit} className="space-y-4">
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Sesi dibuat untuk setiap jadwal aktif halaqah pada rentang tanggal. Rentang maksimal 60
          hari; sesi yang sudah ada akan dilewati.
        </p>
        <div>
          <Label required>Halaqah</Label>
          <SearchSelect
            options={halaqahOptions}
            value={halaqahId}
            error={err('halaqah_id')}
            onChange={(v) => setHalaqahId(toId(v))}
            placeholder="-- Pilih Halaqah --"
          />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Dari Tanggal</Label>
            <Input
              type="date"
              value={from}
              error={err('from')}
              onChange={(e) => setFrom(e.target.value)}
            />
          </div>
          <div>
            <Label required>Sampai Tanggal</Label>
            <Input
              type="date"
              value={to}
              error={err('to')}
              onChange={(e) => setTo(e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Sesi konkret Tahfiz: satu pertemuan pada satu tanggal.
 *
 * Sesi dapat dibuat manual, di-generate dari jadwal berulang, serta
 * dipindahkan statusnya (direncanakan → berlangsung → selesai/dibatalkan).
 */
export function TahfizSessionsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [activityType, setActivityType] = useState<TahfizActivityType | ''>('');
  const [status, setStatus] = useState<TahfizSessionStatus | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizSession | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [transitioning, setTransitioning] = useState<TahfizSession | null>(null);
  const [generateOpen, setGenerateOpen] = useState(false);
  const [generateErrors, setGenerateErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizSession | null>(null);

  const { options: halaqahOptions } = useHalaqahOptions();

  const filters: Record<string, string | number> = {};
  if (halaqahId) filters.halaqah_id = halaqahId;
  if (activityType) filters.activity_type = activityType;
  if (status) filters.status = status;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'sessions', page, filters, from, to],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<TahfizSession>>('/tahfiz/sessions', {
        params: {
          page,
          per_page: 15,
          filter: Object.keys(filters).length ? filters : undefined,
          from: from || undefined,
          to: to || undefined,
        },
      });
      return res.data;
    },
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'sessions'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizSessionPayload) =>
      editing ? sessionsApi.update(editing.id, payload) : sessionsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Sesi diperbarui' : 'Sesi dibuat');
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
    mutationFn: ({ id, status: next }: { id: number; status: TahfizSessionStatus }) =>
      sessionsApi.transition(id, next),
    onSuccess: (res) => {
      toast.success(res.message);
      setTransitioning(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const generateMutation = useMutation({
    mutationFn: (payload: { halaqah_id: number | ''; from: string; to: string }) =>
      sessionsApi.generate(payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setGenerateOpen(false);
      setGenerateErrors({});
      invalidate();
    },
    onError: (err) => {
      setGenerateErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => sessionsApi.remove(id),
    onSuccess: () => {
      toast.success('Sesi dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Sesi Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Pertemuan konkret per tanggal, menjadi induk kehadiran dan setoran.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.create">
            <Button
              variant="outline"
              onClick={() => {
                setGenerateErrors({});
                setGenerateOpen(true);
              }}
            >
              <CalendarPlus className="h-4 w-4" /> Generate dari Jadwal
            </Button>
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Sesi
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Aktivitas</label>
            <Select
              value={activityType}
              onChange={(e) => {
                setActivityType(e.target.value as TahfizActivityType | '');
                setPage(1);
              }}
            >
              <option value="">Semua aktivitas</option>
              {ACTIVITY_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as TahfizSessionStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {STATUS_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Dari Tanggal</label>
            <Input
              type="date"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Sampai Tanggal</label>
            <Input
              type="date"
              value={to}
              onChange={(e) => {
                setTo(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat sesi...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat sesi"
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
            title="Belum ada sesi"
            description="Tambah sesi manual, atau generate dari jadwal berulang halaqah."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Waktu</th>
                  <th className="px-4 py-2 font-medium">Aktivitas</th>
                  <th className="px-4 py-2 font-medium">Halaqah</th>
                  <th className="px-4 py-2 font-medium">Topik</th>
                  <th className="px-4 py-2 font-medium">Instruktur</th>
                  <th className="px-3 py-2 text-center font-medium">Hadir</th>
                  <th className="px-3 py-2 text-center font-medium">Setoran</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 text-slate-700">{row.date ?? '-'}</td>
                    <td className="px-4 py-2 text-slate-600">{timeRange(row)}</td>
                    <td className="px-4 py-2 text-slate-700">
                      {ACTIVITY_TYPE_LABELS[row.activity_type] ?? row.activity_type}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{row.halaqah?.name ?? '—'}</td>
                    <td className="max-w-[14rem] px-4 py-2 text-slate-600">{row.topic ?? '—'}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.instructor?.full_name ?? '—'}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">
                      {row.attendances_count ?? 0}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">
                      {row.memorization_records_count ?? 0}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={sessionStatusTone(row.status)}>
                        {SESSION_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="tahfiz.update">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Transisi status"
                            onClick={() => setTransitioning(row)}
                          >
                            <ArrowRightLeft className="h-3.5 w-3.5" />
                          </Button>
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
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <SessionFormModal
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

      <TransitionModal
        session={transitioning}
        submitting={transitionMutation.isPending}
        onClose={() => setTransitioning(null)}
        onSubmit={(next) => {
          if (transitioning) transitionMutation.mutate({ id: transitioning.id, status: next });
        }}
      />

      <GenerateModal
        open={generateOpen}
        submitting={generateMutation.isPending}
        serverErrors={generateErrors}
        onClose={() => setGenerateOpen(false)}
        onSubmit={(payload) => generateMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Sesi"
        message={`Hapus sesi ${deleting?.date ?? ''} (${deleting ? ACTIVITY_TYPE_LABELS[deleting.activity_type] : ''})? Kehadiran & setoran terkait dapat ikut terdampak.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}