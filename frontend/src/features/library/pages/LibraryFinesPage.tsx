import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { BadgeCheck, HandCoins, Plus, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { libraryFinesApi, libraryMembersApi } from '../api/libraryApi';
import { PayFineModal, type PayFinePayload } from '../components/PayFineModal';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { orDash } from '../lib/labels';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
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
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDate, formatRupiah } from '@/lib/utils';
import type { SelectOption } from '@/constants/masterData';
import type { FineStatus, FineType, LibraryFine } from '@/types/libraryCirculation';

const FINE_TYPE_LABELS: Record<FineType, string> = {
  overdue: 'Keterlambatan',
  damage: 'Kerusakan',
  lost: 'Kehilangan',
  other: 'Lainnya',
};

const FINE_STATUS_LABELS: Record<FineStatus, string> = {
  unpaid: 'Belum Dibayar',
  partial: 'Sebagian',
  paid: 'Lunas',
  waived: 'Dibebaskan',
};

const FINE_TYPE_OPTIONS = Object.keys(FINE_TYPE_LABELS) as FineType[];
const FINE_STATUS_OPTIONS = Object.keys(FINE_STATUS_LABELS) as FineStatus[];

/** unpaid/partial = amber, paid = green, waived = gray (sesuai kesepakatan UI). */
function fineStatusTone(status: FineStatus): 'amber' | 'green' | 'gray' {
  if (status === 'paid') return 'green';
  if (status === 'waived') return 'gray';
  return 'amber';
}

function SummaryTile({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className={`mt-0.5 text-base font-semibold ${tone ?? 'text-slate-900'}`}>{value}</div>
    </div>
  );
}

/* ============================ MODAL DETAIL ============================ */

function FineDetailModal({
  fineId,
  onClose,
}: {
  fineId: number | null;
  onClose: () => void;
}) {
  const detailQuery = useQuery({
    queryKey: ['library', 'fines', 'detail', fineId],
    enabled: fineId != null,
    queryFn: () => libraryFinesApi.show(fineId as number),
  });

  const fine = detailQuery.data;

  return (
    <Modal open={fineId != null} onClose={onClose} title="Detail Denda" size="lg">
      {detailQuery.isLoading ? (
        <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
          <Spinner /> Memuat detail...
        </div>
      ) : detailQuery.isError ? (
        <EmptyState
          title="Gagal memuat detail denda"
          description={getErrorMessage(detailQuery.error)}
          action={
            <Button size="sm" variant="outline" className="mt-2" onClick={() => detailQuery.refetch()}>
              Coba lagi
            </Button>
          }
        />
      ) : !fine ? (
        <EmptyState title="Denda tidak ditemukan" />
      ) : (
        <div className="space-y-4">
          <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <Link
                  to={`/library/members/${fine.member_id}`}
                  className="font-medium text-brand-700 hover:underline"
                >
                  {fine.member?.name ?? '—'}
                </Link>
                <div className="mt-0.5 font-mono text-xs text-slate-500">
                  {orDash(fine.member?.member_number)}
                </div>
              </div>
              <Badge tone={fineStatusTone(fine.status)}>
                {FINE_STATUS_LABELS[fine.status] ?? fine.status}
              </Badge>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <SummaryTile label="Total" value={formatRupiah(fine.amount)} />
            <SummaryTile label="Dibayar" value={formatRupiah(fine.paid_amount)} tone="text-green-600" />
            <SummaryTile label="Dibebaskan" value={formatRupiah(fine.waived_amount)} tone="text-slate-500" />
            <SummaryTile
              label="Sisa Denda"
              value={formatRupiah(fine.outstanding_amount)}
              tone="text-amber-700"
            />
          </div>

          <div className="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            <div className="flex justify-between border-b border-slate-50 py-1.5">
              <span className="text-xs text-slate-500">Jenis</span>
              <span className="text-slate-800">{FINE_TYPE_LABELS[fine.type] ?? fine.type}</span>
            </div>
            <div className="flex justify-between border-b border-slate-50 py-1.5">
              <span className="text-xs text-slate-500">Tanggal Denda</span>
              <span className="text-slate-800">{formatDate(fine.assessed_at)}</span>
            </div>
            {fine.overdue_days != null && (
              <div className="flex justify-between border-b border-slate-50 py-1.5">
                <span className="text-xs text-slate-500">Hari Terlambat</span>
                <span className="text-slate-800">{fine.overdue_days} hari</span>
              </div>
            )}
            {fine.waived_reason && (
              <div className="flex justify-between border-b border-slate-50 py-1.5">
                <span className="text-xs text-slate-500">Alasan Bebas</span>
                <span className="text-slate-800">{fine.waived_reason}</span>
              </div>
            )}
          </div>

          {fine.notes && (
            <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{fine.notes}</p>
          )}

          <div className="flex flex-wrap gap-2 text-sm">
            {fine.loan && (
              <Link
                to={`/library/loans/${fine.loan.id}`}
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-slate-50"
              >
                Peminjaman {fine.loan.loan_code}
              </Link>
            )}
            {fine.item && (
              <Link
                to={`/library/items/${fine.item.id}`}
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-slate-50"
              >
                Eksemplar {fine.item.item_code}
              </Link>
            )}
          </div>

          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900">Riwayat Pembayaran</h3>
            {(fine.payments ?? []).length === 0 ? (
              <p className="text-xs text-slate-400">Belum ada pembayaran.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="px-3 py-2 font-medium">Tanggal</th>
                      <th className="px-3 py-2 font-medium">Nominal</th>
                      <th className="px-3 py-2 font-medium">Metode</th>
                      <th className="px-3 py-2 font-medium">Referensi</th>
                      <th className="px-3 py-2 font-medium">Catatan</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(fine.payments ?? []).map((payment) => (
                      <tr key={payment.id} className="border-b border-slate-50 last:border-0">
                        <td className="px-3 py-2 text-xs text-slate-600">{formatDate(payment.paid_at)}</td>
                        <td className="px-3 py-2 font-medium text-slate-800">
                          {formatRupiah(payment.amount)}
                        </td>
                        <td className="px-3 py-2 text-xs text-slate-600">
                          {orDash(payment.payment_method)}
                        </td>
                        <td className="px-3 py-2 font-mono text-xs text-slate-600">
                          {orDash(payment.reference)}
                        </td>
                        <td className="px-3 py-2 text-xs text-slate-500">{orDash(payment.notes)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}

/* ============================ MODAL BEBASKAN ============================ */

function WaiveFineModal({
  fine,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  fine: LibraryFine | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (reason: string, amount?: number | '') => void;
}) {
  const [reason, setReason] = useState('');
  const [amount, setAmount] = useState('');
  const [error, setError] = useState('');

  const outstanding = fine?.outstanding_amount ?? 0;

  useEffect(() => {
    if (!fine) return;
    setReason('');
    setAmount('');
    setError('');
  }, [fine?.id, fine]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!reason.trim()) {
      setError('Alasan pembebasan wajib diisi');
      return;
    }
    if (amount !== '') {
      const value = Number(amount);
      if (Number.isNaN(value) || value <= 0 || value > outstanding) {
        setError(`Nominal bebas harus antara 0 dan ${formatRupiah(outstanding)}`);
        return;
      }
    }
    setError('');
    onSubmit(reason.trim(), amount === '' ? '' : Number(amount));
  };

  return (
    <Modal
      open={!!fine}
      onClose={onClose}
      title="Bebaskan Denda"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-fine-waive-form" loading={submitting}>
            Bebaskan
          </Button>
        </>
      }
    >
      <form id="library-fine-waive-form" onSubmit={handleSubmit} className="space-y-4">
        <p className="text-sm text-slate-600">
          Denda dapat dibebaskan sebagian atau seluruhnya. Sisa denda saat ini{' '}
          <span className="font-semibold text-amber-700">{formatRupiah(outstanding)}</span>.
          Kosongkan nominal untuk membebaskan seluruh sisa.
        </p>

        <div>
          <Label required>Alasan</Label>
          <Textarea
            rows={3}
            value={reason}
            maxLength={500}
            error={error || serverErrors?.reason?.[0]}
            placeholder="mis. kebijakan perpustakaan / kerusakan ringan"
            onChange={(e) => setReason(e.target.value)}
          />
        </div>

        <div>
          <Label>Nominal Dibebaskan</Label>
          <Input
            type="number"
            min={0.01}
            step="0.01"
            value={amount}
            error={serverErrors?.amount?.[0]}
            placeholder={`Kosongkan = ${formatRupiah(outstanding)}`}
            onChange={(e) => setAmount(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}

/* ============================ MODAL DENDA MANUAL ============================ */

function NewFineModal({
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
  onSubmit: (payload: {
    member_id: number | '';
    type: FineType;
    amount: number | '';
    notes?: string;
  }) => void;
}) {
  const [memberId, setMemberId] = useState<number | ''>('');
  const [type, setType] = useState<FineType>('other');
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setMemberId('');
    setType('other');
    setAmount('');
    setNotes('');
    setError('');
  }, [open]);

  const membersQuery = useQuery({
    queryKey: ['library', 'members', 'options'],
    enabled: open,
    staleTime: 1000 * 60 * 5,
    queryFn: () => libraryMembersApi.list({ all: true }),
  });

  const memberOptions: SelectOption[] = useMemo(
    () =>
      (membersQuery.data?.data ?? []).map((m) => ({
        value: m.id,
        label: `${m.member_number} — ${m.display_name ?? m.name ?? '—'}`,
      })),
    [membersQuery.data],
  );

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (memberId === '') {
      setError('Anggota wajib dipilih');
      return;
    }
    const value = Number(amount);
    if (amount === '' || Number.isNaN(value) || value <= 0) {
      setError('Nominal denda harus lebih dari 0');
      return;
    }
    setError('');
    onSubmit({ member_id: memberId, type, amount: value, notes: notes.trim() || undefined });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Tambah Denda Manual"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-fine-create-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="library-fine-create-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Anggota</Label>
          <SearchSelect
            options={memberOptions}
            value={memberId}
            error={error || serverErrors?.member_id?.[0]}
            onChange={(v) => {
              setMemberId(v === '' ? '' : Number(v));
              setError('');
            }}
            placeholder="-- Pilih anggota --"
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Jenis Denda</Label>
            <Select
              value={type}
              error={serverErrors?.type?.[0]}
              onChange={(e) => setType(e.target.value as FineType)}
            >
              {FINE_TYPE_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {FINE_TYPE_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label required>Nominal</Label>
            <Input
              type="number"
              min={0.01}
              step="0.01"
              value={amount}
              error={serverErrors?.amount?.[0]}
              onChange={(e) => setAmount(e.target.value)}
            />
          </div>
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            rows={2}
            value={notes}
            maxLength={1000}
            error={serverErrors?.notes?.[0]}
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Denda perpustakaan.
 *
 * Pembayaran boleh sebagian (unpaid → partial → paid) dan pembebasan
 * (waive) wajib disertai alasan. Nilai yang melebihi sisa denda ditolak.
 */
export function LibraryFinesPage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions } = useLibraryOptions();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<FineStatus | ''>('');
  const [type, setType] = useState<FineType | ''>('');
  const [outstandingOnly, setOutstandingOnly] = useState(false);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [libraryId, setLibraryId] = useState<number | ''>('');

  const [detailId, setDetailId] = useState<number | null>(null);
  const [paying, setPaying] = useState<LibraryFine | null>(null);
  const [payErrors, setPayErrors] = useState<Record<string, string[]>>({});
  const [waiving, setWaiving] = useState<LibraryFine | null>(null);
  const [waiveErrors, setWaiveErrors] = useState<Record<string, string[]>>({});
  const [createOpen, setCreateOpen] = useState(false);
  const [createErrors, setCreateErrors] = useState<Record<string, string[]>>({});

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (libraryId !== '') out.library_id = libraryId;
    if (status) out.status = status;
    if (type) out.type = type;
    return out;
  }, [libraryId, status, type]);

  const listQuery = useQuery({
    queryKey: ['library', 'fines', page, search, filters, outstandingOnly, from, to],
    queryFn: () =>
      libraryFinesApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
        outstanding: outstandingOnly || undefined,
        from: from || undefined,
        to: to || undefined,
      }),
  });

  const summaryQuery = useQuery({
    queryKey: ['library', 'fines', 'summary', libraryId],
    queryFn: () => libraryFinesApi.summary(libraryId),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });

  const payMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: PayFinePayload }) =>
      libraryFinesApi.pay(id, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setPaying(null);
      setPayErrors({});
      invalidate();
    },
    onError: (err) => {
      setPayErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const waiveMutation = useMutation({
    mutationFn: ({ id, reason, amount }: { id: number; reason: string; amount?: number | '' }) =>
      libraryFinesApi.waive(id, reason, amount),
    onSuccess: (res) => {
      toast.success(res.message);
      setWaiving(null);
      setWaiveErrors({});
      invalidate();
    },
    onError: (err) => {
      setWaiveErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const createMutation = useMutation({
    mutationFn: (payload: { member_id: number | ''; type: string; amount: number | ''; notes?: string }) =>
      libraryFinesApi.create(payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setCreateOpen(false);
      setCreateErrors({});
      invalidate();
    },
    onError: (err) => {
      setCreateErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const summary = summaryQuery.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Denda Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Pembayaran sebagian diperbolehkan; pembebasan denda wajib disertai alasan.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.fine.manage">
            <Button
              onClick={() => {
                setCreateErrors({});
                setCreateOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Denda
            </Button>
          </Can>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
        <SummaryTile label="Total" value={String(summary?.total ?? 0)} />
        <SummaryTile label="Belum Dibayar" value={String(summary?.unpaid ?? 0)} tone="text-amber-600" />
        <SummaryTile label="Sebagian" value={String(summary?.partial ?? 0)} tone="text-amber-600" />
        <SummaryTile label="Lunas" value={String(summary?.paid ?? 0)} tone="text-green-600" />
        <SummaryTile label="Dibebaskan" value={String(summary?.waived ?? 0)} tone="text-slate-500" />
        <SummaryTile
          label="Sisa Denda"
          value={formatRupiah(summary?.outstanding_amount ?? 0)}
          tone="text-amber-700"
        />
        <SummaryTile
          label="Terkumpul"
          value={formatRupiah(summary?.collected_amount ?? 0)}
          tone="text-green-700"
        />
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Nama / nomor anggota..."
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
                setStatus(e.target.value as FineStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {FINE_STATUS_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {FINE_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis</label>
            <Select
              value={type}
              onChange={(e) => {
                setType(e.target.value as FineType | '');
                setPage(1);
              }}
            >
              <option value="">Semua jenis</option>
              {FINE_TYPE_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {FINE_TYPE_LABELS[key]}
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Denda Dari</label>
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
              checked={outstandingOnly}
              onChange={(e) => {
                setOutstandingOnly(e.target.checked);
                setPage(1);
              }}
            />
            Hanya yang belum lunas
          </label>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat denda...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat denda"
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
            title="Belum ada denda"
            description="Denda keterlambatan dibuat otomatis saat pengembalian, atau tambahkan denda manual."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Anggota</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 text-right font-medium">Total</th>
                  <th className="px-4 py-2 text-right font-medium">Dibayar</th>
                  <th className="px-4 py-2 text-right font-medium">Dibebaskan</th>
                  <th className="px-4 py-2 text-right font-medium">Sisa</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="w-56 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const canManage = row.status !== 'paid' && row.status !== 'waived';
                  return (
                    <tr key={row.id} className="border-b border-slate-50 align-top last:border-0">
                      <td className="px-4 py-2">
                        <Link
                          to={`/library/members/${row.member_id}`}
                          className="font-medium text-brand-700 hover:underline"
                        >
                          {row.member?.name ?? '—'}
                        </Link>
                        <div className="mt-0.5 font-mono text-xs text-slate-400">
                          {orDash(row.member?.member_number)}
                        </div>
                      </td>
                      <td className="px-4 py-2 text-xs text-slate-600">
                        {FINE_TYPE_LABELS[row.type] ?? row.type}
                      </td>
                      <td className="px-4 py-2 text-right text-slate-700">
                        {formatRupiah(row.amount)}
                      </td>
                      <td className="px-4 py-2 text-right text-slate-600">
                        {formatRupiah(row.paid_amount)}
                      </td>
                      <td className="px-4 py-2 text-right text-slate-600">
                        {formatRupiah(row.waived_amount)}
                      </td>
                      <td className="px-4 py-2 text-right">
                        <span
                          className={
                            row.outstanding_amount > 0
                              ? 'font-semibold text-amber-700'
                              : 'text-slate-400'
                          }
                        >
                          {formatRupiah(row.outstanding_amount)}
                        </span>
                      </td>
                      <td className="px-4 py-2">
                        <Badge tone={fineStatusTone(row.status)}>
                          {FINE_STATUS_LABELS[row.status] ?? row.status}
                        </Badge>
                      </td>
                      <td className="px-4 py-2 text-xs text-slate-600">
                        {formatDate(row.assessed_at)}
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex flex-wrap items-center gap-1">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setDetailId(row.id)}
                          >
                            Detail
                          </Button>
                          {canManage && (
                            <Can perm="library.fine.manage">
                              <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                  setPayErrors({});
                                  setPaying(row);
                                }}
                              >
                                <HandCoins className="h-3.5 w-3.5" /> Bayar
                              </Button>
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                  setWaiveErrors({});
                                  setWaiving(row);
                                }}
                              >
                                <BadgeCheck className="h-3.5 w-3.5" /> Bebaskan
                              </Button>
                            </Can>
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

      <FineDetailModal fineId={detailId} onClose={() => setDetailId(null)} />

      <PayFineModal
        open={!!paying}
        fine={paying}
        submitting={payMutation.isPending}
        serverErrors={payErrors}
        onClose={() => {
          setPaying(null);
          setPayErrors({});
        }}
        onSubmit={(payload) => {
          if (paying) payMutation.mutate({ id: paying.id, payload });
        }}
      />

      <WaiveFineModal
        fine={waiving}
        submitting={waiveMutation.isPending}
        serverErrors={waiveErrors}
        onClose={() => {
          setWaiving(null);
          setWaiveErrors({});
        }}
        onSubmit={(reason, amount) => {
          if (waiving) waiveMutation.mutate({ id: waiving.id, reason, amount });
        }}
      />

      <NewFineModal
        open={createOpen}
        submitting={createMutation.isPending}
        serverErrors={createErrors}
        onClose={() => {
          setCreateOpen(false);
          setCreateErrors({});
        }}
        onSubmit={(payload) => createMutation.mutate(payload)}
      />
    </div>
  );
}