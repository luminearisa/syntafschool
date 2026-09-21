import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ChevronDown,
  ChevronUp,
  ClipboardCheck,
  Pencil,
  Plus,
  RefreshCw,
  Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import { memorizationRecordsApi } from '../api/tahfizApi';
import { MemorizationRecordFormModal } from '../components/MemorizationRecordFormModal';
import { MemorizationReviewModal } from '../components/MemorizationReviewModal';
import {
  useHalaqahOptions,
  useParticipantOptions,
} from '../hooks/useTahfizOptions';
import {
  MEMORIZATION_STATUS_LABELS,
  MEMORIZATION_TYPE_LABELS,
  memorizationStatusTone,
} from '../lib/labels';
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
  TahfizMemorizationRecord,
  TahfizMemorizationRecordPayload,
  TahfizMemorizationReviewPayload,
  TahfizMemorizationStatus,
  TahfizMemorizationType,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/**
 * Daftar setoran hafalan.
 *
 * Status setoran tidak diubah dari form pencatatan — record dibuat sebagai
 * `submitted` lalu ditinjau lewat tombol "Tinjau" (butuh izin penilaian).
 */
export function TahfizMemorizationRecordsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [participantId, setParticipantId] = useState<number | ''>('');
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [type, setType] = useState<TahfizMemorizationType | ''>('');
  const [status, setStatus] = useState<TahfizMemorizationStatus | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [search, setSearch] = useState('');
  const [expandedId, setExpandedId] = useState<number | null>(null);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizMemorizationRecord | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [reviewing, setReviewing] = useState<TahfizMemorizationRecord | null>(null);
  const [reviewErrors, setReviewErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizMemorizationRecord | null>(null);

  const { options: participantOptions } = useParticipantOptions();
  const { options: halaqahOptions } = useHalaqahOptions();

  const filters = {
    ...(participantId ? { participant_id: participantId } : {}),
    ...(halaqahId ? { halaqah_id: halaqahId } : {}),
    ...(type ? { type } : {}),
    ...(status ? { status } : {}),
    ...(from ? { date_from: from } : {}),
    ...(to ? { date_to: to } : {}),
  };

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'memorization-records', page, filters, search],
    queryFn: () =>
      memorizationRecordsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'memorization-records'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizMemorizationRecordPayload) =>
      editing
        ? memorizationRecordsApi.update(editing.id, payload)
        : memorizationRecordsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Setoran diperbarui' : 'Setoran dicatat');
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

  const reviewMutation = useMutation({
    mutationFn: (payload: TahfizMemorizationReviewPayload) =>
      memorizationRecordsApi.review(reviewing!.id, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setReviewing(null);
      setReviewErrors({});
      invalidate();
    },
    onError: (err) => {
      setReviewErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => memorizationRecordsApi.remove(id),
    onSuccess: () => {
      toast.success('Setoran dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const isEditable = (row: TahfizMemorizationRecord) =>
    row.status === 'submitted' || row.status === 'reviewed';

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Setoran Hafalan</h1>
          <p className="text-sm text-slate-500">
            Catatan setoran hafalan baru, ziyadah, dan tasmi' beserta penilaiannya.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.setoran">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Catat Setoran
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Peserta</label>
            <SearchSelect
              options={participantOptions}
              value={participantId}
              onChange={(v) => {
                setParticipantId(toId(v));
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis</label>
            <Select
              value={type}
              onChange={(e) => {
                setType(e.target.value as TahfizMemorizationType | '');
                setPage(1);
              }}
            >
              <option value="">Semua jenis</option>
              {(Object.keys(MEMORIZATION_TYPE_LABELS) as TahfizMemorizationType[]).map((key) => (
                <option key={key} value={key}>
                  {MEMORIZATION_TYPE_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as TahfizMemorizationStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {(Object.keys(MEMORIZATION_STATUS_LABELS) as TahfizMemorizationStatus[]).map(
                (key) => (
                  <option key={key} value={key}>
                    {MEMORIZATION_STATUS_LABELS[key]}
                  </option>
                ),
              )}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Dari</label>
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Sampai</label>
            <Input
              type="date"
              value={to}
              onChange={(e) => {
                setTo(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="sm:col-span-2 lg:col-span-3 xl:col-span-4">
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari peserta atau surah..."
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
            <Spinner /> Memuat setoran...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat setoran"
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
            title="Belum ada setoran"
            description="Ubah filter atau catat setoran baru."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="w-8 px-3 py-2" />
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Peserta</th>
                  <th className="px-4 py-2 font-medium">Surah / Ayat</th>
                  <th className="px-3 py-2 text-center font-medium">Ayat</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-3 py-2 text-center font-medium">Nilai</th>
                  <th className="px-3 py-2 text-center font-medium">Kesalahan</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const hasMistakes = (row.mistakes?.length ?? 0) > 0;
                  const isExpanded = expandedId === row.id;
                  return (
                    <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                      <td className="px-3 py-2">
                        {hasMistakes && (
                          <button
                            type="button"
                            className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            title="Lihat rincian kesalahan"
                            onClick={() => setExpandedId(isExpanded ? null : row.id)}
                          >
                            {isExpanded ? (
                              <ChevronUp className="h-4 w-4" />
                            ) : (
                              <ChevronDown className="h-4 w-4" />
                            )}
                          </button>
                        )}
                      </td>
                      <td className="px-4 py-2 font-mono text-xs text-slate-700">{row.date ?? '—'}</td>
                      <td className="px-4 py-2 text-slate-700">
                        {row.participant?.name ?? `Peserta #${row.participant_id}`}
                        {row.halaqah && (
                          <div className="text-xs text-slate-400">{row.halaqah.name}</div>
                        )}
                      </td>
                      <td className="px-4 py-2 text-slate-700">
                        {row.surah?.name ?? `Surah #${row.surah_id}`}
                        <div className="text-xs text-slate-400">
                          ayat {row.ayah_start}–{row.ayah_end}
                        </div>
                      </td>
                      <td className="px-3 py-2 text-center text-slate-700">{row.ayah_count}</td>
                      <td className="px-4 py-2 text-xs text-slate-600">{row.type_label}</td>
                      <td className="px-3 py-2 text-center text-slate-700">{row.score ?? '—'}</td>
                      <td className="px-3 py-2 text-center text-slate-700">
                        {row.mistakes_count ?? 0}
                      </td>
                      <td className="px-4 py-2">
                        <Badge tone={memorizationStatusTone(row.status)}>
                          {row.status_label ?? MEMORIZATION_STATUS_LABELS[row.status]}
                        </Badge>
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex flex-wrap gap-1">
                          {row.status === 'submitted' && (
                            <Can
                              perm="tahfiz.assess"
                              fallback={<span className="text-xs text-slate-400">Menunggu nilai</span>}
                            >
                              <Button
                                size="sm"
                                variant="outline"
                                title="Tinjau setoran"
                                onClick={() => {
                                  setReviewErrors({});
                                  setReviewing(row);
                                }}
                              >
                                <ClipboardCheck className="h-3.5 w-3.5" /> Tinjau
                              </Button>
                            </Can>
                          )}
                          <Can
                            perm="tahfiz.setoran"
                            fallback={row.status === 'submitted' ? null : <span className="text-xs text-slate-400">—</span>}
                          >
                            <Button
                              variant="ghost"
                              size="icon"
                              title={isEditable(row) ? 'Edit' : 'Tidak bisa diubah'}
                              disabled={!isEditable(row)}
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
                  );
                })}
                {rows.map((row) => {
                  const isExpanded = expandedId === row.id;
                  if (!isExpanded || !row.mistakes?.length) return null;
                  return (
                    <tr key={`${row.id}-detail`} className="border-b border-slate-50 last:border-0">
                      <td />
                      <td colSpan={9} className="px-4 pb-3">
                        <div className="rounded-lg bg-slate-50 p-3">
                          <p className="mb-2 text-xs font-semibold text-slate-700">
                            Rincian Kesalahan
                          </p>
                          <ul className="space-y-1">
                            {row.mistakes.map((m) => (
                              <li key={m.id} className="text-xs text-slate-600">
                                <span className="font-medium">
                                  {m.type_label ?? m.mistake_type?.label ?? 'Kesalahan'}
                                </span>
                                {m.ayah != null && ` · ayat ${m.ayah}`}
                                {m.count != null && ` · ${m.count}×`}
                                {m.note && ` — ${m.note}`}
                              </li>
                            ))}
                          </ul>
                          {row.teacher_note && (
                            <p className="mt-2 text-xs text-slate-500">
                              Catatan guru: {row.teacher_note}
                            </p>
                          )}
                        </div>
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

      <MemorizationRecordFormModal
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

      <MemorizationReviewModal
        open={!!reviewing}
        record={reviewing}
        submitting={reviewMutation.isPending}
        serverErrors={reviewErrors}
        onClose={() => setReviewing(null)}
        onSubmit={(payload) => reviewMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Setoran Hafalan"
        message={`Hapus setoran tanggal ${deleting?.date ?? ''} atas nama ${
          deleting?.participant?.name ?? ''
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