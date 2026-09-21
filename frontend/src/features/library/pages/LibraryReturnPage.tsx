import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { CheckCircle2, RotateCcw, ScanLine } from 'lucide-react';
import { toast } from 'sonner';
import { libraryCirculationApi, libraryMastersApi, librarySettingsApi } from '../api/libraryApi';
import { ITEM_STATUS_LABELS, itemStatusTone } from '../lib/catalogLabels';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { SelectOption } from '@/constants/masterData';
import type { LibrarySettingsGrouped } from '@/types/library';
import type { ItemStatus } from '@/types/libraryCatalog';
import type { LibraryLoan } from '@/types/libraryCirculation';

const RESULT_STATUS: ItemStatus[] = ['available', 'damaged', 'lost'];

function firstValidationMessage(err: unknown): string | null {
  const errors = getValidationErrors(err);
  if (!errors) return null;
  const first = Object.values(errors)[0];
  return first && first.length > 0 ? first[0] : null;
}

function currency(value: number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(value);
}

function settingValue(groups: LibrarySettingsGrouped | undefined, key: string): unknown {
  if (!groups) return undefined;
  for (const entries of Object.values(groups)) {
    const found = entries.find((e) => e.key === key);
    if (found) return found.value;
  }
  return undefined;
}

function overdueDaysFrom(dueAt: string | null): number {
  if (!dueAt) return 0;
  const due = new Date(`${dueAt}T00:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diff = Math.floor((today.getTime() - due.getTime()) / 86400000);
  return diff > 0 ? diff : 0;
}

interface ReturnTx {
  key: number;
  loan_code: string;
  item_code: string;
  member: string;
  fine: number | null;
}

/**
 * Layar pengembalian (§7).
 *
 * Pindai eksemplar → tampilkan pinjaman terbuka, keterlambatan, dan perkiraan
 * denda. Nominal akhir tetap ditentukan server saat pengembalian diproses.
 */
export function LibraryReturnPage() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();

  const codeParam = searchParams.get('code') ?? '';
  const loanCodeParam = searchParams.get('loan_code') ?? '';

  const [scanInput, setScanInput] = useState('');
  const [scannedItemId, setScannedItemId] = useState<number | null>(null);
  const [scanError, setScanError] = useState<string | null>(null);
  const [conditionId, setConditionId] = useState<number | ''>('');
  const [status, setStatus] = useState<ItemStatus | ''>('');
  const [notes, setNotes] = useState('');
  const [session, setSession] = useState<ReturnTx[]>([]);
  const prefilled = useRef(false);

  const openLoanQuery = useQuery({
    queryKey: ['library', 'circulation', 'return', 'open-loan', scannedItemId, loanCodeParam],
    enabled: scannedItemId != null || loanCodeParam !== '',
    queryFn: () => {
      if (scannedItemId != null) {
        return libraryCirculationApi.list({ filter: { item_id: scannedItemId }, open: true, per_page: 10 });
      }
      return libraryCirculationApi.list({ search: loanCodeParam, open: true, per_page: 10 });
    },
  });

  const loan: LibraryLoan | null =
    (openLoanQuery.data?.data ?? []).find((l) =>
      scannedItemId != null ? l.item_id === scannedItemId : l.loan_code === loanCodeParam,
    ) ??
    openLoanQuery.data?.data[0] ??
    null;

  const conditionsQuery = useQuery({
    queryKey: ['library', 'circulation', 'return', 'conditions'],
    queryFn: () => libraryMastersApi.list('conditions', { all: true }),
  });
  const conditionOptions: SelectOption[] = useMemo(
    () => (conditionsQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [conditionsQuery.data],
  );

  const settingsQuery = useQuery({
    queryKey: ['library', 'circulation', 'return', 'settings', loan?.library_id ?? null],
    enabled: loan?.library_id != null,
    queryFn: () => librarySettingsApi.show(loan?.library_id as number),
  });

  const lookupMutation = useMutation({
    mutationFn: (code: string) => libraryCirculationApi.lookup(code),
    onSuccess: (result) => {
      if (result.type === 'item') {
        if (!result.item.open_loan) {
          setScannedItemId(null);
          setScanError('Eksemplar ini tidak sedang dipinjam.');
          return;
        }
        setScanError(null);
        setScanInput(result.item.item_code);
        setScannedItemId(result.item.id);
      } else if (result.type === 'member') {
        setScannedItemId(null);
        setScanError('Kode ini adalah anggota. Pindai eksemplar yang dikembalikan.');
      } else {
        setScannedItemId(null);
        setScanError(result.message ?? 'Kode tidak dikenali.');
      }
    },
    onError: (err) => {
      setScannedItemId(null);
      setScanError(firstValidationMessage(err) ?? getErrorMessage(err));
    },
  });

  // Prefill dari tautan "Kembalikan" di halaman peminjaman.
  useEffect(() => {
    if (prefilled.current) return;
    if (codeParam) {
      prefilled.current = true;
      setScanInput(codeParam);
      lookupMutation.mutate(codeParam);
    }
  }, [codeParam, lookupMutation]);

  const returnMutation = useMutation({
    mutationFn: (loanId: number) =>
      libraryCirculationApi.returnLoan(loanId, { condition_id: conditionId, status, notes }),
    onSuccess: (res) => {
      toast.success(res.message);
      const fineAmount =
        res.data.fines && res.data.fines.length > 0
          ? res.data.fines.reduce((sum, f) => sum + f.amount, 0)
          : null;
      setSession((prev) =>
        [
          {
            key: Date.now(),
            loan_code: res.data.loan_code,
            item_code: res.data.item?.item_code ?? '',
            member: res.data.member?.name ?? res.data.member?.member_number ?? '',
            fine: fineAmount,
          },
          ...prev,
        ].slice(0, 5),
      );
      setScannedItemId(null);
      setScanInput('');
      setScanError(null);
      setConditionId('');
      setStatus('');
      setNotes('');
      const next = new URLSearchParams(searchParams);
      next.delete('code');
      next.delete('loan_code');
      setSearchParams(next, { replace: true });
      prefilled.current = false;
      queryClient.invalidateQueries({ queryKey: ['library'] });
    },
    onError: (err) => toast.error(firstValidationMessage(err) ?? getErrorMessage(err)),
  });

  const handleScan = (e: FormEvent) => {
    e.preventDefault();
    const trimmed = scanInput.trim();
    if (!trimmed) return;
    setScanError(null);
    lookupMutation.mutate(trimmed);
  };

  const settings = settingsQuery.data;
  const fineEnabled = Boolean(settingValue(settings, 'fine.enabled'));
  const perDay = Number(settingValue(settings, 'fine.per_day') ?? 0) || 0;
  const overdueDays = loan ? (loan.overdue_days > 0 ? loan.overdue_days : overdueDaysFrom(loan.due_at)) : 0;
  const previewFine = fineEnabled ? overdueDays * perDay : 0;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Pengembalian</h1>
        <p className="text-sm text-slate-500">
          Pindai barcode eksemplar untuk menemukan pinjaman terbukanya.
        </p>
      </div>

      <Card className="p-4">
        <form onSubmit={handleScan} className="space-y-2">
          <Label>Kode / Barcode Eksemplar</Label>
          <div className="flex gap-2">
            <Input
              autoFocus
              value={scanInput}
              error={scanError ?? undefined}
              placeholder="Pindai barcode atau ketik kode eksemplar, lalu Enter"
              onChange={(e) => setScanInput(e.target.value)}
            />
            <Button type="submit" loading={lookupMutation.isPending}>
              <ScanLine className="h-4 w-4" /> Cari
            </Button>
          </div>
        </form>
      </Card>

      {openLoanQuery.isLoading || lookupMutation.isPending ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
            <Spinner /> Mencari pinjaman...
          </div>
        </Card>
      ) : loan ? (
        <Card className="p-4">
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <span className="font-mono text-sm text-slate-700">{loan.loan_code}</span>
            <Badge tone={itemStatusTone(loan.item ? 'on_loan' : 'available')}>Dipinjam</Badge>
            {overdueDays > 0 && (
              <span className="text-xs font-semibold text-red-600">Terlambat {overdueDays} hari</span>
            )}
          </div>

          <div className="grid grid-cols-1 gap-3 text-xs text-slate-600 sm:grid-cols-2">
            <div>
              Peminjam: <span className="text-slate-800">{orDash(loan.member?.name)}</span>{' '}
              <span className="font-mono text-slate-500">{orDash(loan.member?.member_number)}</span>
            </div>
            <div>
              Eksemplar: <span className="font-mono text-slate-800">{orDash(loan.item?.item_code)}</span>
            </div>
            <div className="sm:col-span-2">Judul: {orDash(loan.bibliography?.title)}</div>
            <div>Jatuh tempo: {orDash(loan.due_at)}</div>
            <div className={overdueDays > 0 ? 'font-medium text-red-600' : ''}>
              Keterlambatan: {overdueDays} hari
            </div>
          </div>

          <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
            <div className="text-xs font-medium text-amber-800">Perkiraan Denda</div>
            {fineEnabled ? (
              <div className="text-sm text-amber-900">
                {overdueDays} hari × {currency(perDay)} = <span className="font-semibold">{currency(previewFine)}</span>
              </div>
            ) : (
              <div className="text-sm text-amber-900">Denda tidak diaktifkan untuk perpustakaan ini.</div>
            )}
            <p className="mt-1 text-xs text-amber-700">
              Perkiraan saja — nominal final ditentukan oleh server saat pengembalian diproses.
            </p>
          </div>

          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label>Kondisi Eksemplar</Label>
              <SearchSelect
                options={conditionOptions}
                value={conditionId}
                placeholder="-- Tidak berubah --"
                onChange={(v) => setConditionId(v === '' ? '' : Number(v))}
              />
            </div>
            <div>
              <Label>Status Setelah Kembali</Label>
              <Select value={status} onChange={(e) => setStatus(e.target.value as ItemStatus | '')}>
                <option value="">-- Tersedia (default) --</option>
                {RESULT_STATUS.map((key) => (
                  <option key={key} value={key}>
                    {ITEM_STATUS_LABELS[key]}
                  </option>
                ))}
              </Select>
            </div>
            <div className="sm:col-span-2">
              <Label>Catatan</Label>
              <Textarea value={notes} placeholder="Opsional" onChange={(e) => setNotes(e.target.value)} />
            </div>
          </div>

          <div className="mt-4 flex justify-end">
            <Button
              loading={returnMutation.isPending}
              onClick={() => returnMutation.mutate(loan.id)}
            >
              <RotateCcw className="h-4 w-4" /> Proses Pengembalian
            </Button>
          </div>
        </Card>
      ) : (
        <Card>
          <p className="py-10 text-center text-sm text-slate-400">
            Pindai eksemplar untuk melihat pinjaman yang akan dikembalikan.
          </p>
        </Card>
      )}

      <Card className="p-4">
        <h2 className="mb-2 text-sm font-semibold text-slate-800">Pengembalian Sesi Ini</h2>
        {session.length === 0 ? (
          <p className="text-xs text-slate-400">Belum ada pengembalian pada sesi ini.</p>
        ) : (
          <ul className="divide-y divide-slate-50">
            {session.map((tx) => (
              <li key={tx.key} className="flex flex-wrap items-center gap-2 py-2 text-xs">
                <CheckCircle2 className="h-4 w-4 text-green-600" />
                <span className="font-mono text-slate-700">{tx.loan_code}</span>
                <span className="font-mono text-slate-600">{tx.item_code}</span>
                <span className="text-slate-500">{tx.member}</span>
                {tx.fine != null && (
                  <span className="font-medium text-amber-600">Denda {currency(tx.fine)}</span>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}