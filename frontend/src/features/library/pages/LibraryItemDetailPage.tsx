import { useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, History, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { libraryItemsApi } from '../api/libraryApi';
import { ItemFormModal } from '../components/ItemFormModal';
import {
  ITEM_STATUS_LABELS,
  RFID_STATUS_LABELS,
  itemStatusTone,
} from '../lib/catalogLabels';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { ItemStatus, LibraryItemPayload } from '@/types/libraryCatalog';

function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-slate-50 py-2 last:border-0 sm:flex-row sm:items-start sm:gap-3">
      <span className="w-44 shrink-0 text-xs font-medium text-slate-500">{label}</span>
      <span className="text-sm text-slate-800">{children}</span>
    </div>
  );
}

function formatDateTime(value?: string | null): string {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function formatPrice(value: number | null): string {
  if (value === null || value === undefined) return '—';
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(value);
}

/* ============================ MODAL UBAH STATUS ============================ */

function StatusModal({
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
  onSubmit: (status: ItemStatus, reason: string) => void;
}) {
  const [status, setStatus] = useState<ItemStatus>('available');
  const [reason, setReason] = useState('');

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    onSubmit(status, reason.trim());
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ubah Status Eksemplar"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-item-status-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="library-item-status-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Status</Label>
          <Select
            value={status}
            error={serverErrors?.status?.[0]}
            onChange={(e) => setStatus(e.target.value as ItemStatus)}
          >
            {(Object.keys(ITEM_STATUS_LABELS) as ItemStatus[]).map((key) => (
              <option key={key} value={key}>
                {ITEM_STATUS_LABELS[key]}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Alasan</Label>
          <Textarea
            rows={3}
            value={reason}
            error={serverErrors?.reason?.[0]}
            placeholder="Alasan perubahan status (opsional)"
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Detail satu eksemplar (koleksi).
 *
 * Menampilkan identitas fisik eksemplar beserta data perolehan. Riwayat
 * transaksi peminjaman baru tersedia pada modul Circulation (Fase 4).
 */
export function LibraryItemDetailPage() {
  const params = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const itemId = Number(params.id);

  const [formOpen, setFormOpen] = useState(false);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [statusOpen, setStatusOpen] = useState(false);
  const [statusErrors, setStatusErrors] = useState<Record<string, string[]>>({});
  const [deleteOpen, setDeleteOpen] = useState(false);

  const itemQuery = useQuery({
    queryKey: ['library', 'items', itemId],
    enabled: Number.isFinite(itemId),
    queryFn: () => libraryItemsApi.show(itemId),
  });

  const item = itemQuery.data;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library', 'items'] });

  const saveMutation = useMutation({
    mutationFn: (payload: LibraryItemPayload) => libraryItemsApi.update(itemId, payload),
    onSuccess: () => {
      toast.success('Eksemplar diperbarui');
      setFormOpen(false);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const statusMutation = useMutation({
    mutationFn: ({ status, reason }: { status: ItemStatus; reason: string }) =>
      libraryItemsApi.setStatus(itemId, status, reason || undefined),
    onSuccess: (res) => {
      toast.success(res.message);
      setStatusOpen(false);
      setStatusErrors({});
      invalidate();
    },
    onError: (err) => {
      setStatusErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: () => libraryItemsApi.remove(itemId),
    onSuccess: () => {
      toast.success('Eksemplar dihapus');
      setDeleteOpen(false);
      navigate('/library/items');
    },
    onError: (err) => {
      const errors = getValidationErrors(err);
      const first = errors ? Object.values(errors)[0]?.[0] : undefined;
      toast.error(first ?? getErrorMessage(err));
    },
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <Link
            to="/library/items"
            className="mb-1 inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700"
          >
            <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke koleksi
          </Link>
          <h1 className="text-lg font-semibold text-slate-900">
            {item ? item.item_code : 'Detail Eksemplar'}
          </h1>
          {item?.bibliography && (
            <p className="text-sm text-slate-500">
              <Link to={`/library/catalog/${item.bibliography.id}`} className="text-brand-700 hover:underline">
                {item.bibliography.title}
              </Link>
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => itemQuery.refetch()}>
            <RefreshCw className={itemQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.item.update">
            <Button variant="outline" onClick={() => { setStatusErrors({}); setStatusOpen(true); }} disabled={!item}>
              Ubah Status
            </Button>
            <Button
              onClick={() => {
                setServerErrors({});
                setFormOpen(true);
              }}
              disabled={!item}
            >
              <Pencil className="h-4 w-4" /> Edit
            </Button>
          </Can>
          <Can perm="library.item.delete">
            <Button variant="danger" onClick={() => setDeleteOpen(true)} disabled={!item}>
              <Trash2 className="h-4 w-4" /> Hapus
            </Button>
          </Can>
        </div>
      </div>

      {itemQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat eksemplar...
          </div>
        </Card>
      ) : itemQuery.isError || !item ? (
        <Card>
          <EmptyState
            title="Gagal memuat eksemplar"
            description={getErrorMessage(itemQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => itemQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card className="p-4">
            <h2 className="mb-2 text-sm font-semibold text-slate-900">Identitas Eksemplar</h2>
            <InfoRow label="Judul (Record)">
              {item.bibliography ? (
                <Link to={`/library/catalog/${item.bibliography.id}`} className="text-brand-700 hover:underline">
                  {item.bibliography.title}
                </Link>
              ) : (
                '—'
              )}
            </InfoRow>
            <InfoRow label="Kode Eksemplar">
              <span className="font-mono text-xs">{item.item_code}</span>
            </InfoRow>
            <InfoRow label="Nomor Inventaris">
              <span className="font-mono text-xs">{orDash(item.accession_number)}</span>
            </InfoRow>
            <InfoRow label="Barcode">
              <span className="font-mono text-xs">{orDash(item.barcode)}</span>
            </InfoRow>
            <InfoRow label="Tag RFID">
              {item.rfid_tag ? (
                <span className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-xs">{item.rfid_tag}</span>
                  <Badge tone={item.rfid_status === 'assigned' || item.rfid_status === 'active' ? 'green' : 'gray'}>
                    {RFID_STATUS_LABELS[item.rfid_status] ?? item.rfid_status}
                  </Badge>
                </span>
              ) : (
                <span className="flex items-center gap-2">
                  <span className="text-slate-400">—</span>
                  <Badge tone="gray">{RFID_STATUS_LABELS[item.rfid_status] ?? item.rfid_status}</Badge>
                </span>
              )}
            </InfoRow>
            <InfoRow label="Perpustakaan">{item.library?.name ?? '—'}</InfoRow>
            <InfoRow label="Lokasi">{item.location?.name ?? '—'}</InfoRow>
            <InfoRow label="Kondisi">{item.condition?.name ?? '—'}</InfoRow>
            <InfoRow label="Status Koleksi">{item.collection_status?.name ?? '—'}</InfoRow>
            <InfoRow label="Status Eksemplar">
              <Badge tone={itemStatusTone(item.status)}>
                {ITEM_STATUS_LABELS[item.status] ?? item.status}
              </Badge>
            </InfoRow>
            <InfoRow label="Kendali Sirkulasi">
              {item.is_circulation_controlled ? 'Dikendalikan sirkulasi' : 'Tidak dikendalikan sirkulasi'}
            </InfoRow>
          </Card>

          <div className="space-y-4">
            <Card className="p-4">
              <h2 className="mb-2 text-sm font-semibold text-slate-900">Perolehan</h2>
              <InfoRow label="Tanggal Perolehan">{item.acquisition_date ?? '—'}</InfoRow>
              <InfoRow label="Sumber Perolehan">{item.acquisition_source?.name ?? '—'}</InfoRow>
              <InfoRow label="Harga Perolehan">{formatPrice(item.acquisition_price)}</InfoRow>
              <InfoRow label="Sumber Dana">{item.funding_source ?? '—'}</InfoRow>
              <InfoRow label="Catatan">{item.notes ?? '—'}</InfoRow>
            </Card>

            <Card className="p-4">
              <h2 className="mb-2 text-sm font-semibold text-slate-900">Jejak Waktu</h2>
              <InfoRow label="Dibuat">{formatDateTime(item.created_at)}</InfoRow>
              <InfoRow label="Diperbarui">{formatDateTime(item.updated_at)}</InfoRow>
            </Card>

            <Card className="p-4">
              <div className="flex items-center gap-2">
                <History className="h-4 w-4 text-slate-400" />
                <h2 className="text-sm font-semibold text-slate-900">Riwayat</h2>
              </div>
              <p className="mt-2 text-sm text-slate-500">
                Riwayat transaksi lengkap (peminjaman, pengembalian, denda) akan tersedia pada modul
                Sirkulasi (Fase 4).
              </p>
            </Card>
          </div>
        </div>
      )}

      <ItemFormModal
        open={formOpen}
        editing={item ?? null}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => setFormOpen(false)}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <StatusModal
        open={statusOpen}
        submitting={statusMutation.isPending}
        serverErrors={statusErrors}
        onClose={() => setStatusOpen(false)}
        onSubmit={(status, reason) => statusMutation.mutate({ status, reason })}
      />

      <ConfirmDialog
        open={deleteOpen}
        title="Hapus Eksemplar"
        message={`Hapus eksemplar "${item?.item_code ?? ''}"? Eksemplar yang sedang dalam sirkulasi tidak dapat dihapus.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleteOpen(false)}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  );
}