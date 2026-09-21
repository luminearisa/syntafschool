import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { GitBranch, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { libraryAcquisitionsApi } from '../api/libraryApi';
import { AcquisitionFormModal } from '../components/AcquisitionFormModal';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { api, getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDate, formatRupiah } from '@/lib/utils';
import type { AcquisitionPayload, AcquisitionStatus, LibraryAcquisition } from '@/types/libraryCirculation';

const ACQUISITION_STATUS_LABELS: Record<AcquisitionStatus, string> = {
  draft: 'Draf',
  submitted: 'Diajukan',
  approved: 'Disetujui',
  ordered: 'Dipesan',
  received: 'Diterima',
  cancelled: 'Dibatalkan',
};

const ACQUISITION_STATUS_OPTIONS = Object.keys(ACQUISITION_STATUS_LABELS) as AcquisitionStatus[];

function acquisitionStatusTone(status: AcquisitionStatus): 'gray' | 'blue' | 'brand' | 'amber' | 'green' | 'red' {
  switch (status) {
    case 'approved':
      return 'brand';
    case 'ordered':
      return 'amber';
    case 'received':
      return 'green';
    case 'cancelled':
      return 'red';
    case 'submitted':
      return 'blue';
    default:
      return 'gray';
  }
}

/* ============================ MODAL TRANSISI ============================ */

function TransitionModal({
  acquisition,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  acquisition: LibraryAcquisition | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (status: AcquisitionStatus, reason?: string) => void;
}) {
  const options = acquisition?.allowed_transitions ?? [];
  const [status, setStatus] = useState<AcquisitionStatus | ''>('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!acquisition) return;
    setStatus(acquisition.allowed_transitions[0] ?? '');
    setReason('');
    setError('');
  }, [acquisition]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!status) {
      setError('Status tujuan wajib dipilih');
      return;
    }
    setError('');
    onSubmit(status, reason.trim() || undefined);
  };

  return (
    <Modal
      open={!!acquisition}
      onClose={onClose}
      title="Ubah Status Pengadaan"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-acquisition-transition-form" loading={submitting}>
            Ubah Status
          </Button>
        </>
      }
    >
      <form id="library-acquisition-transition-form" onSubmit={handleSubmit} className="space-y-4">
        {acquisition && (
          <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3 text-sm">
            <div className="font-mono text-xs text-slate-500">{acquisition.code}</div>
            <div className="mt-1 flex items-center gap-2">
              <Badge tone={acquisitionStatusTone(acquisition.status)}>
                {ACQUISITION_STATUS_LABELS[acquisition.status]}
              </Badge>
              <span className="text-xs text-slate-500">status saat ini</span>
            </div>
          </div>
        )}

        <div>
          <Label required>Status Tujuan</Label>
          {options.length === 0 ? (
            <p className="text-xs text-slate-500">
              Tidak ada transisi lanjutan. Pengadaan yang sudah diterima atau dibatalkan bersifat final.
            </p>
          ) : (
            <Select
              value={status}
              error={error || serverErrors?.status?.[0]}
              onChange={(e) => setStatus(e.target.value as AcquisitionStatus)}
            >
              {options.map((option) => (
                <option key={option} value={option}>
                  {ACQUISITION_STATUS_LABELS[option] ?? option}
                </option>
              ))}
            </Select>
          )}
        </div>

        <div>
          <Label>Alasan</Label>
          <Textarea
            rows={2}
            value={reason}
            maxLength={500}
            error={serverErrors?.reason?.[0]}
            placeholder="Opsional, mis. alasan pembatalan"
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Daftar permintaan pengadaan koleksi.
 *
 * Siklus: draft → submitted → approved → ordered → received; pembatalan
 * diizinkan dari status terbuka, sedangkan received/cancelled bersifat final.
 */
export function LibraryAcquisitionsPage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions } = useLibraryOptions();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<AcquisitionStatus | ''>('');
  const [openOnly, setOpenOnly] = useState(false);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [libraryId, setLibraryId] = useState<number | ''>('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<LibraryAcquisition | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [transitioning, setTransitioning] = useState<LibraryAcquisition | null>(null);
  const [transitionErrors, setTransitionErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<LibraryAcquisition | null>(null);

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (libraryId !== '') out.library_id = libraryId;
    if (status) out.status = status;
    return out;
  }, [libraryId, status]);

  const listQuery = useQuery({
    queryKey: ['library', 'acquisitions', page, search, filters, openOnly, from, to],
    queryFn: () =>
      libraryAcquisitionsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
        open: openOnly || undefined,
        from: from || undefined,
        to: to || undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });

  const saveMutation = useMutation({
    mutationFn: (payload: AcquisitionPayload) =>
      editing
        ? libraryAcquisitionsApi.update(editing.id, payload)
        : libraryAcquisitionsApi.create(payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setFormOpen(false);
      setEditing(null);
      setFormErrors({});
      invalidate();
    },
    onError: (err) => {
      setFormErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const transitionMutation = useMutation({
    mutationFn: ({ id, status: next, reason }: { id: number; status: AcquisitionStatus; reason?: string }) =>
      libraryAcquisitionsApi.transition(id, next, reason),
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
    // Wrapper API tidak menyediakan `remove`; panggil endpoint langsung.
    // Backend menolak (422) bila pengadaan sudah diterima.
    mutationFn: (id: number) => api.delete(`/library/acquisitions/${id}`),
    onSuccess: () => {
      toast.success('Pengadaan dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => {
      const errors = getValidationErrors(err);
      const first = errors ? Object.values(errors)[0]?.[0] : undefined;
      toast.error(first ?? getErrorMessage(err));
    },
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pengadaan Koleksi</h1>
          <p className="text-sm text-slate-500">
            Usulan → persetujuan → pesanan → penerimaan. Penerimaan membuat judul &amp; eksemplar baru.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.acquisition.create">
            <Button
              onClick={() => {
                setEditing(null);
                setFormErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Pengadaan
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Kode / pemasok..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as AcquisitionStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {ACQUISITION_STATUS_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {ACQUISITION_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Perpustakaan</label>
            <SearchSelect
              options={libraryOptions}
              value={libraryId}
              placeholder="-- Semua --"
              onChange={(v) => {
                setLibraryId(v === '' ? '' : Number(v));
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Diajukan Dari</label>
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
          <label className="flex items-end gap-2 pb-2 text-xs font-medium text-slate-600">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={openOnly}
              onChange={(e) => {
                setOpenOnly(e.target.checked);
                setPage(1);
              }}
            />
            Hanya pengadaan terbuka
          </label>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat pengadaan...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat pengadaan"
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
            title="Belum ada pengadaan"
            description="Buat permintaan pengadaan untuk menambah koleksi baru."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Pemasok</th>
                  <th className="px-4 py-2 text-center font-medium">Judul</th>
                  <th className="px-4 py-2 text-right font-medium">Total</th>
                  <th className="px-4 py-2 font-medium">Diajukan</th>
                  <th className="px-4 py-2 font-medium">Perkiraan Tiba</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-52 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-4 py-2">
                      <Link
                        to={`/library/acquisitions/${row.id}`}
                        className="font-mono text-xs font-medium text-brand-700 hover:underline"
                      >
                        {row.code}
                      </Link>
                    </td>
                    <td className="px-4 py-2 text-slate-700">
                      {row.supplier?.name ?? orDash(row.supplier_name)}
                    </td>
                    <td className="px-4 py-2 text-center text-slate-600">{row.items_count}</td>
                    <td className="px-4 py-2 text-right text-slate-700">
                      {formatRupiah(row.total_amount)}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{formatDate(row.requested_at)}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{formatDate(row.expected_at)}</td>
                    <td className="px-4 py-2">
                      <Badge tone={acquisitionStatusTone(row.status)}>
                        {ACQUISITION_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap items-center gap-1">
                        <Link
                          to={`/library/acquisitions/${row.id}`}
                          className="rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-slate-100"
                        >
                          Detail
                        </Link>
                        {row.allowed_transitions.length > 0 && (
                          <Can perm="library.acquisition.manage">
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => {
                                setTransitionErrors({});
                                setTransitioning(row);
                              }}
                            >
                              <GitBranch className="h-3.5 w-3.5" /> Transisi
                            </Button>
                          </Can>
                        )}
                        {row.status !== 'received' && row.status !== 'cancelled' && (
                          <Can perm="library.acquisition.manage">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Hapus"
                              onClick={() => setDeleting(row)}
                            >
                              <Trash2 className="h-3.5 w-3.5 text-red-500" />
                            </Button>
                          </Can>
                        )}
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

      <AcquisitionFormModal
        open={formOpen}
        editing={editing}
        submitting={saveMutation.isPending}
        serverErrors={formErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <TransitionModal
        acquisition={transitioning}
        submitting={transitionMutation.isPending}
        serverErrors={transitionErrors}
        onClose={() => {
          setTransitioning(null);
          setTransitionErrors({});
        }}
        onSubmit={(next, reason) => {
          if (transitioning) {
            transitionMutation.mutate({ id: transitioning.id, status: next, reason });
          }
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Pengadaan"
        message={`Hapus pengadaan "${deleting?.code ?? ''}"? Pengadaan yang sudah diterima tidak dapat dihapus.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}