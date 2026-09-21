import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, CheckCircle2, RefreshCw, ScanLine, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { libraryInventoryApi, libraryMastersApi } from '../api/libraryApi';
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
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDate } from '@/lib/utils';
import type { SelectOption } from '@/constants/masterData';
import type { InventoryResult, InventoryStatus } from '@/types/libraryCirculation';

const INVENTORY_STATUS_LABELS: Record<InventoryStatus, string> = {
  draft: 'Draf',
  ongoing: 'Berjalan',
  finalized: 'Final',
  cancelled: 'Dibatalkan',
};

function inventoryStatusTone(status: InventoryStatus): 'gray' | 'blue' | 'green' | 'red' {
  switch (status) {
    case 'ongoing':
      return 'blue';
    case 'finalized':
      return 'green';
    case 'cancelled':
      return 'red';
    default:
      return 'gray';
  }
}

function TallyTile({ label, value, tone }: { label: string; value: number | string; tone?: string }) {
  return (
    <div className="rounded-lg border border-slate-200 px-3 py-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className={`mt-0.5 text-lg font-semibold ${tone ?? 'text-slate-900'}`}>{value}</div>
    </div>
  );
}

/* ============================ MODAL FINALISASI ============================ */

function FinalizeModal({
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
  onSubmit: (markMissingAsLost: boolean) => void;
}) {
  const [markLost, setMarkLost] = useState(false);

  useEffect(() => {
    if (!open) return;
    setMarkLost(false);
  }, [open]);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Finalisasi Stock Opname"
      size="sm"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button variant="primary" loading={submitting} onClick={() => onSubmit(markLost)}>
            Finalkan
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <p className="text-sm text-slate-600">
          Finalisasi akan menghitung jumlah diharapkan, ditemukan, hilang, tak terduga, dan rusak.
          Sesi yang sudah final tidak dapat dipindai lagi.
        </p>
        <label className="flex items-start gap-2 rounded-lg border border-slate-200 p-3 text-sm text-slate-700">
          <input
            type="checkbox"
            className="mt-0.5 h-4 w-4 rounded border-slate-300"
            checked={markLost}
            onChange={(e) => setMarkLost(e.target.checked)}
          />
          <span>
            Tandai eksemplar hilang sebagai <span className="font-medium">hilang (lost)</span>
          </span>
        </label>
        {serverErrors?.session?.[0] && (
          <p className="text-xs text-red-500">{serverErrors.session[0]}</p>
        )}
      </div>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Pemindaian & hasil sesi stock opname.
 *
 * Sesi berjalan: pindai kode (Enter) → hasil tampil mencolok; penghitung
 * sesi dan rekap berjalan diperbarui dari respons server. Sesi final
 * menyembunyikan input pindai dan menampilkan laporan hasil.
 */
export function LibraryInventoryDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const sessionId = Number(id);

  const inputRef = useRef<HTMLInputElement>(null);

  const [code, setCode] = useState('');
  const [conditionId, setConditionId] = useState<number | ''>('');
  const [lastResult, setLastResult] = useState<{
    result: InventoryResult;
    scanned_code: string | null;
    is_unexpected: boolean;
  } | null>(null);
  const [scanCount, setScanCount] = useState(0);
  const [scannedItems, setScannedItems] = useState<Map<number, InventoryResult>>(new Map());
  const [unexpectedCodes, setUnexpectedCodes] = useState<Set<string>>(new Set());
  const [finalizeOpen, setFinalizeOpen] = useState(false);
  const [finalizeErrors, setFinalizeErrors] = useState<Record<string, string[]>>({});
  const [finalCounts, setFinalCounts] = useState<{
    expected: number;
    found: number;
    missing: number;
    unexpected: number;
    damaged: number;
  } | null>(null);

  const summaryQuery = useQuery({
    queryKey: ['library', 'inventory', 'detail', sessionId],
    enabled: Number.isFinite(sessionId),
    queryFn: () => libraryInventoryApi.show(sessionId),
  });

  const conditionsQuery = useQuery({
    queryKey: ['library', 'masters', 'conditions', 'options'],
    staleTime: 1000 * 60 * 10,
    queryFn: () => libraryMastersApi.list('conditions', { all: true }),
  });

  const conditionOptions: SelectOption[] = useMemo(
    () => (conditionsQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [conditionsQuery.data],
  );

  const summary = summaryQuery.data;
  const status = summary?.session.status;
  const readOnly = status === 'finalized' || status === 'cancelled';

  // Fokuskan input setiap kali sesi berjalan ditampilkan.
  useEffect(() => {
    if (summaryQuery.isSuccess && !readOnly) inputRef.current?.focus();
  }, [summaryQuery.isSuccess, readOnly]);

  const scanMutation = useMutation({
    mutationFn: (payload: { code: string }) => libraryInventoryApi.scan(sessionId, payload),
    onSuccess: (res) => {
      setLastResult(res.data);
      setScanCount((c) => c + 1);
      setCode('');
      // Rekap berjalan: dedupe per eksemplar (server idempoten).
      if (res.data.item_id != null && res.data.result !== 'unexpected') {
        const itemId = res.data.item_id;
        const result = res.data.result;
        setScannedItems((prev) => new Map(prev).set(itemId, result));
      } else if (res.data.scanned_code) {
        const scanned = res.data.scanned_code;
        setUnexpectedCodes((prev) => new Set(prev).add(scanned));
      }
      summaryQuery.refetch();
      requestAnimationFrame(() => inputRef.current?.focus());
      toast.success(res.message);
    },
    onError: (err) => {
      const errors = getValidationErrors(err);
      const first = errors ? Object.values(errors)[0]?.[0] : undefined;
      toast.error(first ?? getErrorMessage(err));
      setCode('');
      requestAnimationFrame(() => inputRef.current?.focus());
    },
  });

  const finalizeMutation = useMutation({
    mutationFn: (markMissingAsLost: boolean) =>
      libraryInventoryApi.finalize(sessionId, { mark_missing_as_lost: markMissingAsLost }),
    onSuccess: (res) => {
      toast.success(res.message);
      setFinalCounts(res.data);
      setFinalizeOpen(false);
      setFinalizeErrors({});
      queryClient.invalidateQueries({ queryKey: ['library'] });
    },
    onError: (err) => {
      setFinalizeErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const handleScan = (e: FormEvent) => {
    e.preventDefault();
    const value = code.trim();
    if (!value) return;
    scanMutation.mutate({
      code: value,
      ...(conditionId !== '' ? { condition_id: conditionId } : {}),
    });
  };

  const liveFound = useMemo(
    () => Array.from(scannedItems.values()).filter((r) => r === 'found').length,
    [scannedItems],
  );
  const liveDamaged = useMemo(
    () => Array.from(scannedItems.values()).filter((r) => r === 'damaged').length,
    [scannedItems],
  );

  if (!Number.isFinite(sessionId)) {
    return (
      <Card>
        <EmptyState title="Sesi tidak ditemukan" description="ID sesi tidak valid." />
      </Card>
    );
  }

  if (summaryQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat sesi...
        </div>
      </Card>
    );
  }

  if (summaryQuery.isError || !summary) {
    return (
      <Card>
        <EmptyState
          title="Gagal memuat sesi"
          description={summaryQuery.error ? getErrorMessage(summaryQuery.error) : undefined}
          action={
            <Button size="sm" variant="outline" className="mt-2" onClick={() => summaryQuery.refetch()}>
              Coba lagi
            </Button>
          }
        />
      </Card>
    );
  }

  const counts = finalCounts ?? {
    expected: summary.expected,
    found: liveFound,
    missing: summary.missing,
    unexpected: unexpectedCodes.size,
    damaged: liveDamaged,
  };

  const resultMeta: Record<
    InventoryResult,
    { label: string; className: string; icon: React.ReactNode }
  > = {
    found: {
      label: 'Ditemukan',
      className: 'border-green-200 bg-green-50 text-green-800',
      icon: <CheckCircle2 className="h-5 w-5 text-green-600" />,
    },
    damaged: {
      label: 'Rusak',
      className: 'border-amber-200 bg-amber-50 text-amber-800',
      icon: <AlertTriangle className="h-5 w-5 text-amber-600" />,
    },
    unexpected: {
      label: 'Di luar cakupan / tak terduga',
      className: 'border-red-200 bg-red-50 text-red-800',
      icon: <XCircle className="h-5 w-5 text-red-600" />,
    },
    missing: {
      label: 'Hilang',
      className: 'border-red-200 bg-red-50 text-red-800',
      icon: <XCircle className="h-5 w-5 text-red-600" />,
    },
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <Button variant="ghost" size="icon" title="Kembali" onClick={() => navigate(-1)}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <h1 className="font-mono text-lg font-semibold text-slate-900">
              {summary.session.code}
            </h1>
            <div className="mt-0.5 flex flex-wrap items-center gap-2">
              <span className="text-sm text-slate-500">{summary.session.name}</span>
              <Badge tone={inventoryStatusTone(summary.session.status)}>
                {INVENTORY_STATUS_LABELS[summary.session.status] ?? summary.session.status}
              </Badge>
            </div>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => summaryQuery.refetch()}>
            <RefreshCw className={summaryQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.inventory.manage">
            {!readOnly && (
              <Button
                variant="primary"
                onClick={() => {
                  setFinalizeErrors({});
                  setFinalizeOpen(true);
                }}
              >
                Finalisasi
              </Button>
            )}
          </Can>
        </div>
      </div>

      {!readOnly && (
        <Card className="p-4">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <ScanLine className="h-4 w-4 text-slate-400" />
              <h2 className="text-sm font-semibold text-slate-900">Pindai Kode</h2>
            </div>
            <span className="text-xs text-slate-500">
              Penghitung sesi: <span className="font-semibold text-slate-700">{scanCount}</span>{' '}
              pemindaian
            </span>
          </div>

          <form onSubmit={handleScan} className="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div className="sm:col-span-3">
              <Label>Barcode / Kode Eksemplar / No. Inventaris</Label>
              <Input
                ref={inputRef}
                autoFocus
                value={code}
                placeholder="Pindai atau ketik kode lalu tekan Enter..."
                onChange={(e) => setCode(e.target.value)}
              />
            </div>
            <div>
              <Label>Kondisi (opsional)</Label>
              <Select
                value={conditionId === '' ? '' : String(conditionId)}
                onChange={(e) => setConditionId(e.target.value === '' ? '' : Number(e.target.value))}
              >
                <option value="">-- Kondisi normal --</option>
                {conditionOptions.map((o) => (
                  <option key={String(o.value)} value={String(o.value)}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </div>
          </form>

          {lastResult && (
            <div
              className={`mt-3 flex items-start gap-3 rounded-lg border p-3 ${resultMeta[lastResult.result].className}`}
            >
              {resultMeta[lastResult.result].icon}
              <div>
                <div className="text-sm font-semibold">{resultMeta[lastResult.result].label}</div>
                <div className="font-mono text-xs">
                  {orDash(lastResult.scanned_code)}
                  {lastResult.is_unexpected && ' — di luar cakupan sesi'}
                </div>
              </div>
            </div>
          )}
        </Card>
      )}

      <Card className="p-4">
        <div className="mb-3 flex items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-900">Rekap Sementara</h2>
          <Button size="sm" variant="ghost" onClick={() => summaryQuery.refetch()}>
            <RefreshCw className="h-3.5 w-3.5" /> Perbarui
          </Button>
        </div>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
          <TallyTile label="Diharapkan" value={counts.expected} />
          <TallyTile label="Ditemukan" value={counts.found} tone="text-green-600" />
          <TallyTile label="Hilang" value={counts.missing} tone="text-red-600" />
          <TallyTile label="Tak Terduga" value={counts.unexpected} tone="text-amber-600" />
          <TallyTile label="Rusak" value={counts.damaged} tone="text-amber-600" />
        </div>
        {!readOnly && (
          <p className="mt-2 text-xs text-slate-400">
            Angka diharapkan &amp; hilang dihitung final saat finalisasi. Rekap di atas diperbarui
            setiap pemindaian.
          </p>
        )}
      </Card>

      <Card>
        <div className="border-b border-slate-100 px-4 py-3">
          <h2 className="text-sm font-semibold text-slate-900">
            Hasil Stock Opname
            {summary.session.finalized_at && (
              <span className="ml-2 text-xs font-normal text-slate-500">
                final {formatDate(summary.session.finalized_at)}
              </span>
            )}
          </h2>
        </div>
        <div className="grid grid-cols-1 gap-4 p-4 lg:grid-cols-3">
          <div>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
              Eksemplar Hilang
            </h3>
            {summary.missing_items.length === 0 ? (
              <p className="text-xs text-slate-400">Tidak ada.</p>
            ) : (
              <ul className="space-y-1 text-sm">
                {summary.missing_items.map((item, index) => (
                  <li
                    key={`${item.item_code ?? 'missing'}-${index}`}
                    className="flex items-center justify-between gap-2 border-b border-slate-50 pb-1"
                  >
                    <span className="font-mono text-xs text-slate-700">
                      {orDash(item.item_code ?? item.barcode)}
                    </span>
                    <span className="text-xs text-slate-400">{orDash(item.location)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
              Eksemplar Rusak
            </h3>
            {summary.damaged_items.length === 0 ? (
              <p className="text-xs text-slate-400">Tidak ada.</p>
            ) : (
              <ul className="space-y-1 text-sm">
                {summary.damaged_items.map((item, index) => (
                  <li
                    key={`${item.item_code ?? 'damaged'}-${index}`}
                    className="flex items-center justify-between gap-2 border-b border-slate-50 pb-1"
                  >
                    <span className="font-mono text-xs text-slate-700">
                      {orDash(item.item_code ?? item.barcode)}
                    </span>
                    <span className="text-xs text-slate-400">{orDash(item.location)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
              Kode Tak Terduga
            </h3>
            {summary.unexpected_codes.length === 0 ? (
              <p className="text-xs text-slate-400">Tidak ada.</p>
            ) : (
              <ul className="space-y-1 text-sm">
                {summary.unexpected_codes.map((entry, index) => (
                  <li
                    key={`${entry.scanned_code ?? 'code'}-${index}`}
                    className="flex items-center justify-between gap-2 border-b border-slate-50 pb-1"
                  >
                    <span className="font-mono text-xs text-slate-700">
                      {orDash(entry.scanned_code)}
                    </span>
                    <span className="text-xs text-slate-400">{orDash(entry.notes)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
        {readOnly && (
          <div className="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
            Sesi ini sudah{' '}
            {status === 'finalized' ? 'difinalkan' : 'dibatalkan'} — pemindaian tidak lagi tersedia.
            Lihat koleksi pada{' '}
            <Link to="/library/items" className="text-brand-700 hover:underline">
              daftar eksemplar
            </Link>{' '}
            bila perlu menindaklanjuti.
          </div>
        )}
      </Card>

      <FinalizeModal
        open={finalizeOpen}
        submitting={finalizeMutation.isPending}
        serverErrors={finalizeErrors}
        onClose={() => {
          setFinalizeOpen(false);
          setFinalizeErrors({});
        }}
        onSubmit={(markLost) => finalizeMutation.mutate(markLost)}
      />
    </div>
  );
}