import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { libraryMastersApi } from '../api/libraryApi';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { formatRupiah } from '@/lib/utils';
import type { SelectOption } from '@/constants/masterData';
import type { LibraryFine } from '@/types/libraryCirculation';

export interface PayFinePayload {
  amount: number | '';
  paid_at?: string;
  payment_method_id?: number | '';
  reference?: string;
  notes?: string;
}

interface PayFineModalProps {
  open: boolean;
  fine: LibraryFine | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: PayFinePayload) => void;
}

/** Tanggal hari ini (zona waktu lokal) dalam format `YYYY-MM-DD`. */
function todayISO(): string {
  const now = new Date();
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 10);
}

/**
 * Catat pembayaran denda.
 *
 * Pembayaran boleh sebagian; nilai yang melebihi sisa denda akan ditolak
 * server (422), jadi modal juga memvalidasinya lebih dulu di sisi klien.
 */
export function PayFineModal({
  open,
  fine,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: PayFineModalProps) {
  const [amount, setAmount] = useState('');
  const [paidAt, setPaidAt] = useState(todayISO());
  const [methodId, setMethodId] = useState<number | ''>('');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');

  const outstanding = fine?.outstanding_amount ?? 0;

  const methodsQuery = useQuery({
    queryKey: ['library', 'masters', 'payment-methods', 'options'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => libraryMastersApi.list('payment-methods', { all: true }),
  });

  const methodOptions: SelectOption[] = useMemo(
    () => (methodsQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [methodsQuery.data],
  );

  // Isi ulang setiap kali modal dibuka untuk denda yang berbeda.
  useEffect(() => {
    if (!open) return;
    setAmount(fine ? String(fine.outstanding_amount) : '');
    setPaidAt(todayISO());
    setMethodId('');
    setReference('');
    setNotes('');
    setError('');
  }, [open, fine?.id, fine?.outstanding_amount]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const value = Number(amount);
    if (amount === '' || Number.isNaN(value) || value <= 0) {
      setError('Nominal pembayaran harus lebih dari 0');
      return;
    }
    if (value > outstanding) {
      setError(`Nominal tidak boleh melebihi sisa denda (${formatRupiah(outstanding)})`);
      return;
    }

    setError('');
    onSubmit({
      amount: value,
      paid_at: paidAt || undefined,
      payment_method_id: methodId,
      reference: reference.trim() || undefined,
      notes: notes.trim() || undefined,
    });
  };

  const err = error || serverErrors?.amount?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Bayar Denda"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-fine-pay-form" loading={submitting}>
            Catat Pembayaran
          </Button>
        </>
      }
    >
      <form id="library-fine-pay-form" onSubmit={handleSubmit} className="space-y-4">
        {fine && (
          <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3 text-sm">
            <div className="font-medium text-slate-800">
              {fine.member?.name ?? '—'}
              <span className="ml-2 font-mono text-xs text-slate-500">
                {fine.member?.member_number ?? ''}
              </span>
            </div>
            <div className="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-600">
              <div>
                Total denda
                <div className="font-medium text-slate-800">{formatRupiah(fine.amount)}</div>
              </div>
              <div>
                Sisa denda
                <div className="font-semibold text-amber-700">{formatRupiah(outstanding)}</div>
              </div>
            </div>
          </div>
        )}

        <div>
          <Label required>Nominal Pembayaran</Label>
          <Input
            type="number"
            min={0.01}
            step="0.01"
            value={amount}
            error={err}
            onChange={(e) => setAmount(e.target.value)}
          />
          <p className="mt-1 text-xs text-slate-400">
            Harus lebih dari 0 dan tidak melebihi sisa denda ({formatRupiah(outstanding)}).
            Pembayaran sebagian diperbolehkan.
          </p>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label>Tanggal Bayar</Label>
            <Input type="date" value={paidAt} onChange={(e) => setPaidAt(e.target.value)} />
          </div>
          {/* Metode pembayaran opsional: master mungkin belum diisi. */}
          {methodOptions.length > 0 && (
            <div>
              <Label>Metode Pembayaran</Label>
              <Select
                value={methodId === '' ? '' : String(methodId)}
                error={serverErrors?.payment_method_id?.[0]}
                onChange={(e) => setMethodId(e.target.value === '' ? '' : Number(e.target.value))}
              >
                <option value="">-- Tidak dicatat --</option>
                {methodOptions.map((o) => (
                  <option key={String(o.value)} value={String(o.value)}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </div>
          )}
        </div>

        <div>
          <Label>Referensi</Label>
          <Input
            value={reference}
            maxLength={100}
            placeholder="mis. nomor kuitansi / bukti transfer"
            error={serverErrors?.reference?.[0]}
            onChange={(e) => setReference(e.target.value)}
          />
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            rows={2}
            value={notes}
            error={serverErrors?.notes?.[0]}
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}