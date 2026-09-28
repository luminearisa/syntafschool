import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FileText, Plus, RefreshCw, Search, Send } from 'lucide-react';
import { toast } from 'sonner';
import { financeApi } from '../api/financeApi';
import { BillingFormModal } from '../components/BillingFormModal';
import { FinanceDocumentDetailModal } from '../components/FinanceDocumentDetailModal';
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
import type { CreateBillingPayload } from '../api/financeApi';
import type { FinanceBilling } from '../types';

type Tab = 'billings' | 'invoices';

const STATUSES = [
  { value: '', label: 'Semua status' },
  { value: 'draft', label: 'Draft' },
  { value: 'issued', label: 'Diterbitkan' },
  { value: 'partial', label: 'Dibayar sebagian' },
  { value: 'paid', label: 'Lunas' },
  { value: 'overdue', label: 'Jatuh tempo' },
  { value: 'cancelled', label: 'Dibatalkan' },
];

export function FinanceInvoicesPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canBilling = can('finance.billing.view');
  const canInvoice = can('finance.invoice.view');
  const [tab, setTab] = useState<Tab>(canBilling ? 'billings' : 'invoices');
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [issuing, setIssuing] = useState<FinanceBilling | null>(null);
  const [detailDocument, setDetailDocument] = useState<{ type: 'billing' | 'invoice'; id: number } | null>(null);

  const billingsQuery = useQuery({
    queryKey: ['finance', 'billings', page, search, status],
    enabled: tab === 'billings' && canBilling,
    queryFn: () => financeApi.billings({ page, per_page: 15, search, status }),
  });

  const invoicesQuery = useQuery({
    queryKey: ['finance', 'invoices', page, search, status],
    enabled: tab === 'invoices' && canInvoice,
    queryFn: () => financeApi.invoices({ page, per_page: 15, search, status }),
  });

  const createBilling = useMutation({
    mutationFn: (payload: CreateBillingPayload) => financeApi.createBilling(payload),
    onSuccess: () => {
      toast.success('Tagihan berhasil dibuat');
      setFormOpen(false);
      queryClient.invalidateQueries({ queryKey: ['finance', 'billings'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const issueInvoice = useMutation({
    mutationFn: (billingId: number) => financeApi.issueInvoice(billingId),
    onSuccess: (invoice) => {
      toast.success(`Invoice ${invoice.invoice_number} berhasil diterbitkan`);
      setIssuing(null);
      queryClient.invalidateQueries({ queryKey: ['finance', 'billings'] });
      queryClient.invalidateQueries({ queryKey: ['finance', 'invoices'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const activeQuery = tab === 'billings' ? billingsQuery : invoicesQuery;
  const rows = activeQuery.data?.data ?? [];
  const meta = activeQuery.data?.meta;

  const applySearch = (event: React.FormEvent) => {
    event.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  };

  const changeTab = (next: Tab) => {
    setTab(next);
    setPage(1);
    setStatus('');
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Tagihan & Invoice</h1>
          <p className="text-sm text-slate-500">Kelola tagihan siswa, terbitkan invoice, dan pantau sisa piutang.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            type="button"
            size="icon"
            variant="ghost"
            title="Muat ulang"
            onClick={() => activeQuery.refetch()}
          >
            <RefreshCw className={activeQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          {tab === 'billings' && (
            <Can perm="finance.billing.create">
              <Button type="button" onClick={() => setFormOpen(true)}>
                <Plus className="h-4 w-4" /> Buat Tagihan
              </Button>
            </Can>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-2 border-b border-slate-200">
        {canBilling && (
          <button
            type="button"
            onClick={() => changeTab('billings')}
            className={`border-b-2 px-3 py-2 text-sm font-medium ${tab === 'billings' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
          >
            Tagihan
          </button>
        )}
        {canInvoice && (
          <button
            type="button"
            onClick={() => changeTab('invoices')}
            className={`border-b-2 px-3 py-2 text-sm font-medium ${tab === 'invoices' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
          >
            Invoice
          </button>
        )}
      </div>

      <Card className="p-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <form onSubmit={applySearch} className="flex flex-1 items-center gap-2">
            <div className="relative w-full max-w-sm">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <Input
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder={tab === 'billings' ? 'Cari nomor tagihan...' : 'Cari nomor invoice...'}
                className="pl-9"
              />
            </div>
            <Button type="submit" variant="outline">Cari</Button>
          </form>
          <div className="w-full sm:w-52">
            <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
              {STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
            </Select>
          </div>
        </div>
      </Card>

      <Card>
        {activeQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat data keuangan...</div>
        ) : activeQuery.isError ? (
          <EmptyState
            title="Gagal memuat data"
            description={getErrorMessage(activeQuery.error)}
            action={<Button size="sm" variant="outline" className="mt-2" onClick={() => activeQuery.refetch()}>Coba lagi</Button>}
          />
        ) : rows.length === 0 ? (
          <EmptyState
            title={tab === 'billings' ? 'Belum ada tagihan' : 'Belum ada invoice'}
            description={search ? 'Coba kata kunci atau status yang berbeda.' : 'Data akan tampil setelah tagihan dibuat.'}
            action={tab === 'billings' && can('finance.billing.create') ? <Button size="sm" className="mt-2" onClick={() => setFormOpen(true)}><Plus className="h-4 w-4" /> Buat Tagihan</Button> : undefined}
          />
        ) : tab === 'billings' ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="px-4 py-3 font-medium">Nomor Tagihan</th>
                <th className="px-4 py-3 font-medium">Siswa</th>
                <th className="px-4 py-3 font-medium">Jatuh Tempo</th>
                <th className="px-4 py-3 text-right font-medium">Total</th>
                <th className="px-4 py-3 text-right font-medium">Sisa</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 text-right font-medium">Aksi</th>
              </tr></thead>
              <tbody>{(rows as FinanceBilling[]).map((row) => (
                <tr key={row.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                  <td className="px-4 py-3"><button type="button" onClick={() => setDetailDocument({ type: 'billing', id: row.id })} className="text-left"><div className="font-mono text-xs font-semibold text-brand-700 hover:underline">{row.billing_number}</div><div className="text-xs text-slate-500">{row.invoice_number ?? 'Belum diterbitkan'} · Detail</div></button></td>
                  <td className="px-4 py-3"><div className="font-medium text-slate-800">{row.student_name ?? '—'}</div><div className="text-xs text-slate-400">{row.source}</div></td>
                  <td className="px-4 py-3 text-slate-600">{row.due_date ?? '—'}</td>
                  <td className="px-4 py-3 text-right font-medium">{formatRupiah(row.total)}</td>
                  <td className="px-4 py-3 text-right text-slate-700">{formatRupiah(row.remaining_amount)}</td>
                  <td className="px-4 py-3"><Badge tone={financeStatusTone(row.status)}>{row.status_label}</Badge></td>
                  <td className="px-4 py-3 text-right">
                    {!row.invoice_id && row.status !== 'cancelled' ? (
                      <Can perm="finance.invoice.create">
                        <Button size="sm" variant="outline" onClick={() => setIssuing(row)}><Send className="h-3.5 w-3.5" /> Terbitkan</Button>
                      </Can>
                    ) : <span className="text-xs text-slate-400">{row.invoice_number ?? '—'}</span>}
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="px-4 py-3 font-medium">Nomor Invoice</th>
                <th className="px-4 py-3 font-medium">Siswa</th>
                <th className="px-4 py-3 font-medium">Terbit / Jatuh Tempo</th>
                <th className="px-4 py-3 text-right font-medium">Total</th>
                <th className="px-4 py-3 text-right font-medium">Terbayar</th>
                <th className="px-4 py-3 text-right font-medium">Sisa</th>
                <th className="px-4 py-3 font-medium">Status</th>
              </tr></thead>
              <tbody>{invoicesQuery.data?.data.map((row) => (
                <tr key={row.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                  <td className="px-4 py-3"><button type="button" onClick={() => setDetailDocument({ type: 'invoice', id: row.id })} className="text-left"><div className="font-mono text-xs font-semibold text-brand-700 hover:underline">{row.invoice_number}</div><div className="text-xs text-slate-500">{row.billing_number ?? 'Tagihan manual'} · Detail</div></button></td>
                  <td className="px-4 py-3 font-medium text-slate-800">{row.student_name ?? '—'}</td>
                  <td className="px-4 py-3 text-xs text-slate-600">{row.issue_date ?? '—'}<div className="text-slate-400">Jatuh tempo: {row.due_date ?? '—'}</div></td>
                  <td className="px-4 py-3 text-right font-medium">{formatRupiah(row.total)}</td>
                  <td className="px-4 py-3 text-right text-slate-600">{formatRupiah(row.paid_amount)}</td>
                  <td className="px-4 py-3 text-right font-semibold text-slate-800">{formatRupiah(row.remaining_amount)}</td>
                  <td className="px-4 py-3"><Badge tone={financeStatusTone(row.status)}>{row.status_label}</Badge>{row.is_overdue && <div className="mt-1 text-xs text-red-600">Lewat jatuh tempo</div>}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
        {meta && <Pagination meta={meta} onPageChange={setPage} />}
      </Card>

      <BillingFormModal open={formOpen} submitting={createBilling.isPending} onClose={() => setFormOpen(false)} onSubmit={(payload) => createBilling.mutate(payload)} />
      <FinanceDocumentDetailModal open={detailDocument !== null} document={detailDocument} onClose={() => setDetailDocument(null)} />
      <ConfirmDialog
        open={!!issuing}
        title="Terbitkan invoice?"
        message={`Invoice ${issuing?.billing_number ?? ''} akan diterbitkan dan dicatat dalam pembukuan. Pastikan detail tagihan sudah benar.`}
        loading={issueInvoice.isPending}
        confirmLabel="Terbitkan Invoice"
        confirmVariant="primary"
        onCancel={() => setIssuing(null)}
        onConfirm={() => { if (issuing) issueInvoice.mutate(issuing.id); }}
      />

      <div className="flex items-start gap-3 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
        <FileText className="mt-0.5 h-4 w-4 shrink-0" />
        <p>Invoice yang diterbitkan menjadi dokumen piutang. Pelunasan dan perubahan saldo dilakukan melalui pencatatan serta verifikasi pembayaran, bukan dengan mengubah invoice secara langsung.</p>
      </div>
    </div>
  );
}
