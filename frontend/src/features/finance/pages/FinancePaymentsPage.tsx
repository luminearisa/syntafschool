import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Plus, RefreshCw, Search, X } from 'lucide-react';
import { toast } from 'sonner';
import { financeApi } from '../api/financeApi';
import { PaymentFormModal } from '../components/PaymentFormModal';
import { RejectPaymentModal } from '../components/RejectPaymentModal';
import { financeStatusTone, formatRupiah } from '../lib/format';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import type { CreatePaymentPayload } from '../api/financeApi';
import type { FinancePayment } from '../types';

const PAYMENT_STATUSES = [
  { value: '', label: 'Semua status' },
  { value: 'pending', label: 'Menunggu verifikasi' },
  { value: 'paid', label: 'Lunas' },
  { value: 'partial', label: 'Sebagian' },
  { value: 'failed', label: 'Gagal / ditolak' },
  { value: 'cancelled', label: 'Dibatalkan' },
];

export function FinancePaymentsPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [verifying, setVerifying] = useState<FinancePayment | null>(null);
  const [rejecting, setRejecting] = useState<FinancePayment | null>(null);

  const paymentsQuery = useQuery({
    queryKey: ['finance', 'payments', page, status, search],
    queryFn: () => financeApi.payments({ page, per_page: 15, status, search }),
  });
  const invoicesQuery = useQuery({
    queryKey: ['finance', 'payment-invoice-options'],
    enabled: formOpen,
    staleTime: 60_000,
    queryFn: () => financeApi.invoices({ all: true }),
  });
  const cashQuery = useQuery({
    queryKey: ['finance', 'cash-account-options'],
    enabled: formOpen && can('finance.cash.view'),
    staleTime: 60_000,
    queryFn: () => financeApi.cashAccounts(),
  });
  const bankQuery = useQuery({
    queryKey: ['finance', 'bank-account-options'],
    enabled: formOpen && can('finance.bank.view'),
    staleTime: 60_000,
    queryFn: () => financeApi.bankAccounts(),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['finance', 'payments'] });
  const createMutation = useMutation({
    mutationFn: (payload: CreatePaymentPayload) => financeApi.createPayment(payload),
    onSuccess: (payment) => {
      toast.success(payment.status === 'pending' ? 'Pembayaran dicatat, menunggu verifikasi' : 'Pembayaran berhasil dicatat');
      setFormOpen(false);
      invalidate();
      queryClient.invalidateQueries({ queryKey: ['finance', 'invoices'] });
      queryClient.invalidateQueries({ queryKey: ['finance', 'billings'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const verifyMutation = useMutation({
    mutationFn: (id: number) => financeApi.verifyPayment(id),
    onSuccess: () => {
      toast.success('Pembayaran diverifikasi dan piutang diperbarui');
      setVerifying(null);
      invalidate();
      queryClient.invalidateQueries({ queryKey: ['finance', 'invoices'] });
      queryClient.invalidateQueries({ queryKey: ['finance', 'billings'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => financeApi.rejectPayment(id, reason),
    onSuccess: () => {
      toast.success('Pembayaran ditolak');
      setRejecting(null);
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const applySearch = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  };
  const payments = paymentsQuery.data?.data ?? [];
  const invoices = (invoicesQuery.data?.data ?? []).filter((invoice) =>
    invoice.remaining_amount > 0 && !['cancelled', 'draft'].includes(invoice.status),
  );
  const cashAccounts = cashQuery.data ?? [];
  const bankAccounts = bankQuery.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pembayaran</h1>
          <p className="text-sm text-slate-500">Catat penerimaan, verifikasi transfer, dan pantau kuitansi pembayaran.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button size="icon" variant="ghost" title="Muat ulang" onClick={() => paymentsQuery.refetch()}>
            <RefreshCw className={paymentsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="finance.payment.create">
            <Button onClick={() => setFormOpen(true)}><Plus className="h-4 w-4" /> Catat Pembayaran</Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <form onSubmit={applySearch} className="flex flex-1 items-center gap-2">
            <div className="relative w-full max-w-sm">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <Input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari nomor pembayaran..." className="pl-9" />
            </div>
            <Button type="submit" variant="outline">Cari</Button>
          </form>
          <div className="w-full sm:w-56">
            <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
              {PAYMENT_STATUSES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </Select>
          </div>
        </div>
      </Card>

      <Card>
        {paymentsQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat pembayaran...</div>
        ) : paymentsQuery.isError ? (
          <EmptyState title="Gagal memuat pembayaran" description={getErrorMessage(paymentsQuery.error)} action={<Button size="sm" variant="outline" className="mt-2" onClick={() => paymentsQuery.refetch()}>Coba lagi</Button>} />
        ) : payments.length === 0 ? (
          <EmptyState title="Belum ada pembayaran" description={search ? 'Tidak ada pembayaran yang cocok dengan pencarian.' : 'Pembayaran yang dicatat akan tampil di sini.'} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="px-4 py-3 font-medium">Nomor Pembayaran</th>
                <th className="px-4 py-3 font-medium">Siswa / Invoice</th>
                <th className="px-4 py-3 font-medium">Tanggal</th>
                <th className="px-4 py-3 font-medium">Metode</th>
                <th className="px-4 py-3 text-right font-medium">Nominal</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 text-right font-medium">Aksi</th>
              </tr></thead>
              <tbody>{payments.map((payment) => (
                <tr key={payment.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                  <td className="px-4 py-3"><div className="font-mono text-xs font-semibold text-slate-800">{payment.payment_number}</div><div className="text-xs text-slate-400">{payment.reference_number ?? '—'}</div></td>
                  <td className="px-4 py-3"><div className="font-medium text-slate-800">{payment.student_name ?? '—'}</div><div className="text-xs text-slate-500">{payment.invoice_number ?? payment.billing_id ?? '—'}</div></td>
                  <td className="px-4 py-3 text-xs text-slate-600">{payment.paid_at ? new Date(payment.paid_at).toLocaleDateString('id-ID') : 'Menunggu'}</td>
                  <td className="px-4 py-3 text-slate-600">{payment.method_label}</td>
                  <td className="px-4 py-3 text-right font-semibold">{formatRupiah(payment.amount)}</td>
                  <td className="px-4 py-3"><Badge tone={financeStatusTone(payment.status)}>{payment.status_label}</Badge>{payment.reject_reason && <div className="mt-1 max-w-48 truncate text-xs text-red-600" title={payment.reject_reason}>{payment.reject_reason}</div>}</td>
                  <td className="px-4 py-3">
                    {payment.status === 'pending' ? (
                      <div className="flex justify-end gap-1">
                        <Can perm="finance.payment.verify">
                          <Button size="icon" variant="ghost" title="Verifikasi pembayaran" onClick={() => setVerifying(payment)}><Check className="h-4 w-4 text-green-600" /></Button>
                        </Can>
                        <Can perm="finance.payment.reject">
                          <Button size="icon" variant="ghost" title="Tolak pembayaran" onClick={() => setRejecting(payment)}><X className="h-4 w-4 text-red-500" /></Button>
                        </Can>
                      </div>
                    ) : <span className="block text-right text-xs text-slate-400">{payment.verified_by_name ?? '—'}</span>}
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
        {paymentsQuery.data?.meta && <Pagination meta={paymentsQuery.data.meta} onPageChange={setPage} />}
      </Card>

      <PaymentFormModal
        open={formOpen}
        submitting={createMutation.isPending}
        invoices={invoices}
        cashAccounts={cashAccounts}
        bankAccounts={bankAccounts}
        onClose={() => setFormOpen(false)}
        onSubmit={(payload) => createMutation.mutate(payload)}
      />
      <ConfirmDialog
        open={!!verifying}
        title="Verifikasi pembayaran?"
        message={`Pembayaran ${verifying?.payment_number ?? ''} akan diposting dan mengurangi sisa invoice.`}
        confirmLabel="Verifikasi"
        confirmVariant="primary"
        loading={verifyMutation.isPending}
        onCancel={() => setVerifying(null)}
        onConfirm={() => { if (verifying) verifyMutation.mutate(verifying.id); }}
      />
      <RejectPaymentModal
        payment={rejecting}
        open={!!rejecting}
        submitting={rejectMutation.isPending}
        onClose={() => setRejecting(null)}
        onSubmit={(reason) => { if (rejecting) rejectMutation.mutate({ id: rejecting.id, reason }); }}
      />
    </div>
  );
}
