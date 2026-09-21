import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, GitBranch, PackageCheck, Pencil, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { libraryAcquisitionsApi } from '../api/libraryApi';
import { AcquisitionFormModal } from '../components/AcquisitionFormModal';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDate, formatRupiah } from '@/lib/utils';
import type {
  AcquisitionPayload,
  AcquisitionStatus,
  LibraryAcquisition,
} from '@/types/libraryCirculation';

const ACQUISITION_STATUS_LABELS: Record<AcquisitionStatus, string> = {
  draft: 'Draf',
  submitted: 'Diajukan',
  approved: 'Disetujui',
  ordered: 'Dipesan',
  received: 'Diterima',
  cancelled: 'Dibatalkan',
};

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

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-slate-50 py-2 last:border-0 sm:flex-row sm:items-start sm:gap-3">
      <span className="w-40 shrink-0 text-xs font-medium text-slate-500">{label}</span>
      <span className="text-sm text-slate-800">{children}</span>
    </div>
  );
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
          <Button type="submit" form="library-acq-detail-transition-form" loading={submitting}>
            Ubah Status
          </Button>
        </>
      }
    >
      <form id="library-acq-detail-transition-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Status Tujuan</Label>
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
        </div>
        <div>
          <Label>Alasan</Label>
          <Textarea
            rows={2}
            value={reason}
            maxLength={500}
            error={serverErrors?.reason?.[0]}
            placeholder="Opsional"
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}

/* ============================ MODAL TERIMA ============================ */

function ReceiveModal({
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
  onSubmit: (receivedAt?: string) => void;
}) {
  const [receivedAt, setReceivedAt] = useState('');

  useEffect(() => {
    if (!open) return;
    setReceivedAt('');
  }, [open]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    onSubmit(receivedAt || undefined);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Terima Pengadaan"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-acq-receive-form" loading={submitting}>
            <PackageCheck className="h-4 w-4" /> Terima
          </Button>
        </>
      }
    >
      <form id="library-acq-receive-form" onSubmit={handleSubmit} className="space-y-4">
        <p className="text-sm text-slate-600">
          Penerimaan akan <span className="font-medium">membuat judul &amp; eksemplar baru</span> sesuai
          daftar, sehingga koleksi langsung tersedia di katalog dan OPAC. Tindakan ini bersifat final.
        </p>
        <div>
          <Label>Tanggal Penerimaan</Label>
          <Input
            type="date"
            value={receivedAt}
            error={serverErrors?.received_at?.[0]}
            onChange={(e) => setReceivedAt(e.target.value)}
          />
          <p className="mt-1 text-xs text-slate-400">Kosongkan untuk memakai tanggal hari ini.</p>
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Detail permintaan pengadaan.
 *
 * Menampilkan baris koleksi beserta kuantitas diterima, riwayat tanggal, dan
 * tindakan transisi/penerimaan. Setelah diterima, seksi panduan menautkan ke
 * daftar eksemplar per judul yang baru dibuat.
 */
export function LibraryAcquisitionDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const acquisitionId = Number(id);

  const [formOpen, setFormOpen] = useState(false);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [transitionOpen, setTransitionOpen] = useState(false);
  const [transitionErrors, setTransitionErrors] = useState<Record<string, string[]>>({});
  const [receiveOpen, setReceiveOpen] = useState(false);
  const [receiveErrors, setReceiveErrors] = useState<Record<string, string[]>>({});
  const [createdItems, setCreatedItems] = useState<number | null>(null);

  const detailQuery = useQuery({
    queryKey: ['library', 'acquisitions', 'detail', acquisitionId],
    enabled: Number.isFinite(acquisitionId),
    queryFn: () => libraryAcquisitionsApi.show(acquisitionId),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });

  const saveMutation = useMutation({
    mutationFn: (payload: AcquisitionPayload) =>
      libraryAcquisitionsApi.update(acquisitionId, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setFormOpen(false);
      setFormErrors({});
      invalidate();
    },
    onError: (err) => {
      setFormErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const transitionMutation = useMutation({
    mutationFn: ({ status, reason }: { status: AcquisitionStatus; reason?: string }) =>
      libraryAcquisitionsApi.transition(acquisitionId, status, reason),
    onSuccess: (res) => {
      toast.success(res.message);
      setTransitionOpen(false);
      setTransitionErrors({});
      invalidate();
    },
    onError: (err) => {
      setTransitionErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const receiveMutation = useMutation({
    mutationFn: (receivedAt?: string) => libraryAcquisitionsApi.receive(acquisitionId, receivedAt),
    onSuccess: (res) => {
      toast.success(res.message);
      setCreatedItems(res.data.created_items);
      setReceiveOpen(false);
      setReceiveErrors({});
      invalidate();
    },
    onError: (err) => {
      setReceiveErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const acquisition = detailQuery.data;

  if (!Number.isFinite(acquisitionId)) {
    return (
      <Card>
        <EmptyState title="Pengadaan tidak ditemukan" description="ID pengadaan tidak valid." />
      </Card>
    );
  }

  if (detailQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat pengadaan...
        </div>
      </Card>
    );
  }

  if (detailQuery.isError || !acquisition) {
    return (
      <Card>
        <EmptyState
          title="Gagal memuat pengadaan"
          description={detailQuery.error ? getErrorMessage(detailQuery.error) : undefined}
          action={
            <Button size="sm" variant="outline" className="mt-2" onClick={() => detailQuery.refetch()}>
              Coba lagi
            </Button>
          }
        />
      </Card>
    );
  }

  const isReceived = acquisition.status === 'received';
  const canReceive = acquisition.status === 'ordered' || acquisition.status === 'approved';

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <Button variant="ghost" size="icon" title="Kembali" onClick={() => navigate(-1)}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <h1 className="font-mono text-lg font-semibold text-slate-900">{acquisition.code}</h1>
            <p className="text-sm text-slate-500">
              {acquisition.supplier?.name ?? orDash(acquisition.supplier_name)}
            </p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => detailQuery.refetch()}>
            <RefreshCw className={detailQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.acquisition.manage">
            {acquisition.allowed_transitions.length > 0 && (
              <Button
                variant="outline"
                onClick={() => {
                  setTransitionErrors({});
                  setTransitionOpen(true);
                }}
              >
                <GitBranch className="h-4 w-4" /> Transisi
              </Button>
            )}
          </Can>
          <Can perm="library.acquisition.manage">
            {canReceive && (
              <Button
                onClick={() => {
                  setReceiveErrors({});
                  setReceiveOpen(true);
                }}
              >
                <PackageCheck className="h-4 w-4" /> Terima
              </Button>
            )}
          </Can>
          <Can perm="library.acquisition.create">
            <Button
              variant="outline"
              disabled={isReceived}
              title={isReceived ? 'Pengadaan yang sudah diterima tidak dapat diubah' : undefined}
              onClick={() => {
                setFormErrors({});
                setFormOpen(true);
              }}
            >
              <Pencil className="h-4 w-4" /> Edit
            </Button>
          </Can>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-4 lg:col-span-2">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-sm font-semibold text-slate-900">Ringkasan</h2>
            <div className="flex items-center gap-2">
              <Badge tone={acquisitionStatusTone(acquisition.status)}>
                {ACQUISITION_STATUS_LABELS[acquisition.status] ?? acquisition.status}
              </Badge>
              <span className="text-sm font-semibold text-slate-900">
                {formatRupiah(acquisition.total_amount)}
              </span>
            </div>
          </div>
          <div className="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            <div>
              <InfoRow label="Perpustakaan">
                {orDash(acquisition.library_id)}
              </InfoRow>
              <InfoRow label="Pemasok">
                {acquisition.supplier?.name ?? orDash(acquisition.supplier_name)}
              </InfoRow>
              <InfoRow label="Jumlah Judul">{acquisition.items_count}</InfoRow>
            </div>
            <div>
              <InfoRow label="Diajukan">{formatDate(acquisition.requested_at)}</InfoRow>
              <InfoRow label="Perkiraan Tiba">{formatDate(acquisition.expected_at)}</InfoRow>
              <InfoRow label="Disetujui">{formatDate(acquisition.approved_at)}</InfoRow>
              <InfoRow label="Diterima">{formatDate(acquisition.received_at)}</InfoRow>
            </div>
          </div>
          {acquisition.notes && (
            <p className="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{acquisition.notes}</p>
          )}
        </Card>

        <Card className="p-4">
          <h2 className="mb-2 text-sm font-semibold text-slate-900">Linimasa</h2>
          <ol className="space-y-2 text-sm">
            <li className="flex justify-between border-b border-slate-50 pb-1.5">
              <span className="text-xs text-slate-500">Diajukan</span>
              <span className="text-slate-800">{formatDate(acquisition.requested_at)}</span>
            </li>
            <li className="flex justify-between border-b border-slate-50 pb-1.5">
              <span className="text-xs text-slate-500">Perkiraan Tiba</span>
              <span className="text-slate-800">{formatDate(acquisition.expected_at)}</span>
            </li>
            <li className="flex justify-between border-b border-slate-50 pb-1.5">
              <span className="text-xs text-slate-500">Disetujui</span>
              <span className="text-slate-800">{formatDate(acquisition.approved_at)}</span>
            </li>
            <li className="flex justify-between">
              <span className="text-xs text-slate-500">Diterima</span>
              <span className="text-slate-800">{formatDate(acquisition.received_at)}</span>
            </li>
          </ol>
        </Card>
      </div>

      {createdItems != null && (
        <Card className="border-green-200 bg-green-50/60 p-4">
          <div className="flex items-center gap-2">
            <CheckCircle2 className="h-4 w-4 text-green-600" />
            <span className="text-sm font-medium text-green-800">
              {createdItems} eksemplar dibuat dari pengadaan ini.
            </span>
          </div>
          <p className="mt-2 text-xs text-green-800">
            Koleksi baru kini tersedia. Periksa eksemplar per judul melalui tautan berikut:
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            {(acquisition.items ?? [])
              .filter((item) => item.bibliography_id != null)
              .map((item) => (
                <Link
                  key={item.id}
                  to={`/library/items?bibliography_id=${item.bibliography_id}`}
                  className="rounded-lg border border-green-200 bg-white px-3 py-1.5 text-xs font-medium text-green-800 hover:bg-green-100"
                >
                  {item.title}
                </Link>
              ))}
          </div>
        </Card>
      )}

      <Card>
        <div className="border-b border-slate-100 px-4 py-3">
          <h2 className="text-sm font-semibold text-slate-900">Daftar Koleksi</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-4 py-2 font-medium">Judul</th>
                <th className="px-4 py-2 font-medium">ISBN</th>
                <th className="px-4 py-2 text-center font-medium">Jumlah</th>
                <th className="px-4 py-2 text-center font-medium">Diterima</th>
                <th className="px-4 py-2 text-right font-medium">Harga Satuan</th>
                <th className="px-4 py-2 text-right font-medium">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              {(acquisition.items ?? []).map((item) => (
                <tr key={item.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-4 py-2 text-slate-800">
                    {item.bibliography_id ? (
                      <Link
                        to={`/library/items?bibliography_id=${item.bibliography_id}`}
                        className="text-brand-700 hover:underline"
                      >
                        {item.title}
                      </Link>
                    ) : (
                      item.title
                    )}
                  </td>
                  <td className="px-4 py-2 font-mono text-xs text-slate-600">{orDash(item.isbn)}</td>
                  <td className="px-4 py-2 text-center text-slate-600">{item.quantity}</td>
                  <td className="px-4 py-2 text-center text-slate-600">{item.received_quantity}</td>
                  <td className="px-4 py-2 text-right text-slate-600">
                    {formatRupiah(item.unit_price)}
                  </td>
                  <td className="px-4 py-2 text-right font-medium text-slate-800">
                    {formatRupiah(item.subtotal)}
                  </td>
                </tr>
              ))}
              {(acquisition.items ?? []).length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-6 text-center text-xs text-slate-400">
                    Belum ada baris koleksi.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </Card>

      <AcquisitionFormModal
        open={formOpen}
        editing={acquisition}
        submitting={saveMutation.isPending}
        serverErrors={formErrors}
        onClose={() => setFormOpen(false)}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <TransitionModal
        acquisition={transitionOpen ? acquisition : null}
        submitting={transitionMutation.isPending}
        serverErrors={transitionErrors}
        onClose={() => {
          setTransitionOpen(false);
          setTransitionErrors({});
        }}
        onSubmit={(status, reason) => transitionMutation.mutate({ status, reason })}
      />

      <ReceiveModal
        open={receiveOpen}
        submitting={receiveMutation.isPending}
        serverErrors={receiveErrors}
        onClose={() => setReceiveOpen(false)}
        onSubmit={(receivedAt) => receiveMutation.mutate(receivedAt)}
      />
    </div>
  );
}