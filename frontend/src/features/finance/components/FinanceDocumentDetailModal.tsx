import { useQuery } from '@tanstack/react-query';
import { financeApi } from '../api/financeApi';
import { financeStatusTone, formatRupiah } from '../lib/format';
import type { FinanceBilling, FinanceInvoice } from '../types';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';

export function FinanceDocumentDetailModal({
  open,
  document,
  onClose,
}: {
  open: boolean;
  document: { type: 'billing' | 'invoice'; id: number } | null;
  onClose: () => void;
}) {
  const billingQuery = useQuery({
    queryKey: ['finance', 'billing-detail', document?.id],
    enabled: open && document?.type === 'billing',
    queryFn: () => financeApi.billing(document!.id),
  });
  const invoiceQuery = useQuery({
    queryKey: ['finance', 'invoice-detail', document?.id],
    enabled: open && document?.type === 'invoice',
    queryFn: () => financeApi.invoice(document!.id),
  });
  const query = document?.type === 'invoice' ? invoiceQuery : billingQuery;
  const data = query.data as FinanceBilling | FinanceInvoice | undefined;
  const items = data?.items ?? [];

  return (
    <Modal open={open} onClose={onClose} title={document?.type === 'invoice' ? 'Detail Invoice' : 'Detail Tagihan'} size="xl">
      {query.isLoading ? <div className="flex justify-center py-14"><Spinner /></div>
        : query.isError ? <EmptyState title="Gagal memuat detail dokumen" description={query.error instanceof Error ? query.error.message : 'Terjadi kesalahan saat memuat data.'} />
          : data ? <div className="space-y-4">
            <div className="grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-4">
              <div><p className="text-xs text-slate-500">Nomor</p><p className="mt-1 font-mono text-sm font-semibold">{document?.type === 'invoice' ? (data as FinanceInvoice).invoice_number : (data as FinanceBilling).billing_number}</p></div>
              <div><p className="text-xs text-slate-500">Siswa</p><p className="mt-1 font-medium">{data.student_name ?? '—'}</p></div>
              <div><p className="text-xs text-slate-500">Jatuh Tempo</p><p className="mt-1">{data.due_date ?? '—'}</p></div>
              <div><p className="text-xs text-slate-500">Status</p><Badge className="mt-1" tone={financeStatusTone(data.status)}>{data.status_label}</Badge></div>
            </div>
            <div className="overflow-x-auto rounded-lg border border-slate-200">
              {items.length === 0 ? <EmptyState title="Rincian dokumen tidak tersedia" /> : <table className="w-full text-sm">
                <thead><tr className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-3 py-2">Deskripsi</th><th className="px-3 py-2 text-right">Jumlah</th><th className="px-3 py-2 text-right">Harga Satuan</th><th className="px-3 py-2 text-right">Total</th></tr></thead>
                <tbody>{items.map((item) => {
                  const quantity = item.quantity;
                  const unitPrice = 'unit_price' in item ? item.unit_price : item.amount;
                  return <tr key={item.id} className="border-t border-slate-100"><td className="px-3 py-2">{item.description}</td><td className="px-3 py-2 text-right">{quantity}</td><td className="px-3 py-2 text-right">{formatRupiah(unitPrice)}</td><td className="px-3 py-2 text-right font-medium">{formatRupiah(item.total ?? unitPrice * quantity)}</td></tr>;
                })}</tbody>
              </table>}
            </div>
            <div className="flex flex-col gap-1 text-right text-sm">
              <div>Total dokumen: <strong>{formatRupiah(data.total)}</strong></div>
              <div>Sudah dibayar: {formatRupiah(data.paid_amount)}</div>
              <div>Sisa: <strong>{formatRupiah(data.remaining_amount)}</strong></div>
            </div>
            {data.notes && <div><p className="mb-1 text-xs font-medium text-slate-500">Catatan</p><p className="rounded-lg border border-slate-200 p-3 text-sm text-slate-700">{data.notes}</p></div>}
          </div> : null}
    </Modal>
  );
}
