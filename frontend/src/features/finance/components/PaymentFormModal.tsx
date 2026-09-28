import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { formatRupiah } from '../lib/format';
import type { CreatePaymentPayload } from '../api/financeApi';
import type { FinanceBankAccountOption, FinanceCashAccountOption, FinanceInvoice } from '../types';

interface Props {
  open: boolean;
  submitting: boolean;
  invoices: FinanceInvoice[];
  cashAccounts: FinanceCashAccountOption[];
  bankAccounts: FinanceBankAccountOption[];
  onClose: () => void;
  onSubmit: (payload: CreatePaymentPayload) => void;
}

export function PaymentFormModal({ open, submitting, invoices, cashAccounts, bankAccounts, onClose, onSubmit }: Props) {
  const [invoiceId, setInvoiceId] = useState<number | ''>('');
  const [method, setMethod] = useState<CreatePaymentPayload['method']>('cash');
  const [settlementAccount, setSettlementAccount] = useState('');
  const [amount, setAmount] = useState('');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');

  const selectedInvoice = invoices.find((invoice) => invoice.id === Number(invoiceId));
  const accountOptions = useMemo(() => [
    ...cashAccounts.map((account) => ({ value: `cash:${account.id}`, label: `Kas · ${account.name} (${account.code})` })),
    ...bankAccounts.map((account) => ({ value: `bank:${account.id}`, label: `Bank · ${account.bank_name ?? account.name} · ${account.name}` })),
  ], [bankAccounts, cashAccounts]);

  useEffect(() => {
    if (!open) return;
    setInvoiceId('');
    setMethod('cash');
    setSettlementAccount('');
    setAmount('');
    setReference('');
    setNotes('');
    setError('');
  }, [open]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!selectedInvoice) {
      setError('Pilih invoice yang akan dibayar.');
      return;
    }
    if (!Number(amount) || Number(amount) <= 0 || Number(amount) > selectedInvoice.remaining_amount) {
      setError(`Nominal harus lebih dari nol dan tidak boleh melebihi sisa invoice (${formatRupiah(selectedInvoice.remaining_amount)}).`);
      return;
    }
    if (!settlementAccount) {
      setError('Pilih akun kas atau rekening bank penerimaan.');
      return;
    }
    const [accountType, accountId] = settlementAccount.split(':');
    if (method === 'cash' && accountType !== 'cash') {
      setError('Pembayaran tunai harus menggunakan akun kas.');
      return;
    }
    setError('');
    onSubmit({
      invoice_id: selectedInvoice.id,
      amount: Number(amount),
      method,
      cash_account_id: accountType === 'cash' ? Number(accountId) : undefined,
      bank_account_id: accountType === 'bank' ? Number(accountId) : undefined,
      reference_number: reference.trim() || undefined,
      notes: notes.trim() || undefined,
      paid_at: method === 'cash' ? new Date().toISOString() : undefined,
    });
  };

  const invoiceOptions = invoices.map((invoice) => ({
    value: invoice.id,
    label: `${invoice.invoice_number} · ${invoice.student_name ?? 'Siswa'} · sisa ${formatRupiah(invoice.remaining_amount)}`,
  }));

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Catat Pembayaran"
      size="lg"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Batal</Button>
          <Button type="submit" form="finance-payment-form" loading={submitting}>Simpan Pembayaran</Button>
        </>
      }
    >
      <form id="finance-payment-form" onSubmit={submit} className="space-y-4">
        <div>
          <Label required>Invoice</Label>
          <SearchSelect
            options={invoiceOptions}
            value={invoiceId}
            placeholder="Pilih invoice yang belum lunas"
            onChange={(value) => {
              const next = value === '' ? '' : Number(value);
              setInvoiceId(next);
              const invoice = invoices.find((row) => row.id === Number(next));
              setAmount(invoice ? String(invoice.remaining_amount) : '');
            }}
          />
        </div>
        {selectedInvoice && (
          <div className="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
            <div className="flex justify-between gap-4"><span>Total invoice</span><strong>{formatRupiah(selectedInvoice.total)}</strong></div>
            <div className="mt-1 flex justify-between gap-4"><span>Sudah dibayar</span><span>{formatRupiah(selectedInvoice.paid_amount)}</span></div>
            <div className="mt-1 flex justify-between gap-4"><span>Sisa pembayaran</span><strong>{formatRupiah(selectedInvoice.remaining_amount)}</strong></div>
          </div>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Metode</Label>
            <Select value={method} onChange={(event) => { setMethod(event.target.value as CreatePaymentPayload['method']); setSettlementAccount(''); }}>
              <option value="cash">Tunai — langsung tercatat lunas</option>
              <option value="bank_transfer">Transfer bank — menunggu verifikasi</option>
              <option value="manual_transfer">Transfer manual — menunggu verifikasi</option>
            </Select>
          </div>
          <div>
            <Label required>{method === 'cash' ? 'Akun Kas' : 'Akun Penerimaan'}</Label>
            <Select value={settlementAccount} onChange={(event) => setSettlementAccount(event.target.value)}>
              <option value="">-- Pilih akun --</option>
              {method === 'cash'
                ? accountOptions.filter((option) => String(option.value).startsWith('cash:')).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)
                : accountOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </Select>
            {accountOptions.length === 0 && <p className="mt-1 text-xs text-amber-700">Belum ada akun penerimaan aktif. Minta pengelola keuangan menyiapkan akun kas/bank.</p>}
          </div>
          <div>
            <Label required>Nominal</Label>
            <Input type="number" min="1" step="1000" value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="0" />
          </div>
          <div>
            <Label>Nomor Referensi</Label>
            <Input value={reference} onChange={(event) => setReference(event.target.value)} placeholder="No. bukti transfer" />
          </div>
        </div>
        <div>
          <Label>Catatan</Label>
          <Textarea value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Catatan pembayaran (opsional)" />
        </div>
        {method !== 'cash' && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">Pembayaran transfer tersimpan sebagai menunggu verifikasi. Saldo piutang baru berubah setelah petugas memverifikasi pembayaran.</p>}
        {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
      </form>
    </Modal>
  );
}
