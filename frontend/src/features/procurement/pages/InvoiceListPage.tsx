import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, RefreshCw, Send } from 'lucide-react';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { formatDate, formatRupiah } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { invoicesApi, purchaseOrdersApi, vendorsApi } from '../api/procurementApi';
import { FormField, StatusBadge } from '../components/ProcurementUi';
import { invoiceStatus, matchStatus } from '../lib/labels';

/** Invoice vendor, pencocokan 3 arah, dan serah terima ke akuntansi (§37–§44). */
export default function InvoiceListPage() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [buka, setBuka] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'invoices', page, search, status],
    queryFn: () => invoicesApi.list({ page, per_page: 20, search, status }),
  });

  const aksi = useMutation({
    mutationFn: ({ id, langkah }: { id: number; langkah: 'match' | 'approve' | 'handoff' | 'retry' | 'sync' }) => {
      if (langkah === 'match') return invoicesApi.match(id);
      if (langkah === 'approve') return invoicesApi.approve(id);
      if (langkah === 'handoff') return invoicesApi.handoff(id);
      if (langkah === 'retry') return invoicesApi.retryHandoff(id);
      return invoicesApi.syncStatus(id);
    },
    onSuccess: (_res, vars) => {
      const label: Record<string, string> = {
        match: 'Pencocokan dijalankan',
        approve: 'Invoice disetujui',
        handoff: 'Dikirim ke akuntansi',
        retry: 'Percobaan ulang dijalankan',
        sync: 'Status disinkronkan',
      };
      toast.success(label[vars.langkah]);
      qc.invalidateQueries({ queryKey: ['procurement'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Invoice Vendor</h1>
          <p className="text-xs text-slate-500">
            Invoice dicocokkan 3 arah (PO ↔ penerimaan ↔ invoice). Jurnal dan payable tetap milik modul Akuntansi.
          </p>
        </div>
        <Can perm="procurement.manage_invoice">
          <Button size="sm" onClick={() => setBuka(true)}>
            <Plus className="mr-1 h-4 w-4" /> Catat invoice
          </Button>
        </Can>
      </header>

      <Card>
        <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
          <Input
            placeholder="Cari nomor invoice / vendor…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="max-w-xs"
          />
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className="max-w-[200px]"
          >
            <option value="">Semua status</option>
            {invoiceStatus.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada invoice" description="Catat invoice vendor untuk memulai pencocokan." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-2 font-medium">Nomor</th>
                  <th className="px-3 py-2 font-medium">Vendor</th>
                  <th className="px-3 py-2 font-medium">Tgl invoice</th>
                  <th className="px-3 py-2 text-right font-medium">Total</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                  <th className="px-3 py-2 font-medium">Matching</th>
                  <th className="px-5 py-2 text-right font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((inv) => (
                  <tr key={inv.id} className="hover:bg-slate-50">
                    <td className="px-5 py-2">
                      <Link to={`/procurement/invoices/${inv.id}`} className="font-mono text-xs text-brand-600 hover:underline">
                        {inv.number}
                      </Link>
                      <p className="font-mono text-[10px] text-slate-400">{inv.vendor_invoice_number}</p>
                    </td>
                    <td className="px-3 py-2 text-slate-700">{inv.vendor?.legal_name ?? '-'}</td>
                    <td className="px-3 py-2 text-slate-600">{formatDate(inv.invoice_date)}</td>
                    <td className="px-3 py-2 text-right tabular-nums text-slate-700">{formatRupiah(inv.total ?? 0)}</td>
                    <td className="px-3 py-2">
                      <StatusBadge value={inv.status} map={invoiceStatus} />
                    </td>
                    <td className="px-3 py-2">
                      {inv.matchResults?.[0] ? <StatusBadge value={inv.matchResults[0].match_status} map={matchStatus} /> : '-'}
                    </td>
                    <td className="px-5 py-2 text-right">
                      <div className="flex justify-end gap-1">
                        <Can perm="procurement.match_invoice">
                          {['RECEIVED', 'UNDER_MATCHING', 'MISMATCH', 'EXCEPTION'].includes(inv.status) && (
                            <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: inv.id, langkah: 'match' })}>
                              <RefreshCw className="mr-1 h-3.5 w-3.5" /> Cocokkan
                            </Button>
                          )}
                          {inv.status === 'MATCHED' && (
                            <Button size="sm" onClick={() => aksi.mutate({ id: inv.id, langkah: 'approve' })}>
                              Setujui
                            </Button>
                          )}
                        </Can>
                        <Can perm="procurement.send_to_accounting">
                          {inv.status === 'APPROVED' && (
                            <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: inv.id, langkah: 'handoff' })}>
                              <Send className="mr-1 h-3.5 w-3.5" /> Ke akuntansi
                            </Button>
                          )}
                          {['SENT_TO_ACCOUNTING', 'POSTED'].includes(inv.status) && (
                            <Button size="sm" variant="ghost" onClick={() => aksi.mutate({ id: inv.id, langkah: 'sync' })}>
                              Sinkronkan
                            </Button>
                          )}
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      {buka && <InvoiceFormModal onClose={() => setBuka(false)} />}
    </div>
  );
}

function InvoiceFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    vendor_id: '' as number | '',
    purchase_order_id: '' as number | '',
    vendor_invoice_number: '',
    invoice_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    currency: 'IDR',
    subtotal: '',
    tax_amount: '',
  });

  const { data: vendors } = useQuery({
    queryKey: ['procurement', 'options', 'vendor'],
    queryFn: () => vendorsApi.list({ per_page: 100, usable_only: true }),
  });

  const { data: orders } = useQuery({
    queryKey: ['procurement', 'purchase-orders', 'for-invoice'],
    queryFn: () => purchaseOrdersApi.list({ per_page: 100, status: 'VENDOR_CONFIRMED' }),
  });

  const simpan = useMutation({
    mutationFn: () =>
      invoicesApi.create({
        vendor_id: Number(form.vendor_id),
        purchase_order_id: form.purchase_order_id === '' ? null : Number(form.purchase_order_id),
        vendor_invoice_number: form.vendor_invoice_number,
        invoice_date: form.invoice_date,
        due_date: form.due_date || null,
        currency: form.currency,
        subtotal: Number(form.subtotal || 0),
        tax_amount: Number(form.tax_amount || 0),
      }),
    onSuccess: () => {
      toast.success('Invoice dicatat');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Catat invoice vendor"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button
            onClick={() => simpan.mutate()}
            loading={simpan.isPending}
            disabled={form.vendor_id === '' || !form.vendor_invoice_number}
          >
            Simpan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Vendor" required error={errors.vendor_id?.[0]}>
          <Select
            value={form.vendor_id}
            onChange={(e) => setForm({ ...form, vendor_id: e.target.value === '' ? '' : Number(e.target.value) })}
          >
            <option value="">— Pilih vendor —</option>
            {(vendors?.data ?? []).map((v) => (
              <option key={v.id} value={v.id}>
                {v.code} — {v.legal_name}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Nomor invoice vendor" required error={errors.vendor_invoice_number?.[0]}>
          <Input
            value={form.vendor_invoice_number}
            onChange={(e) => setForm({ ...form, vendor_invoice_number: e.target.value })}
          />
        </FormField>
        <FormField label="Purchase Order" error={errors.purchase_order_id?.[0]}>
          <Select
            value={form.purchase_order_id}
            onChange={(e) => setForm({ ...form, purchase_order_id: e.target.value === '' ? '' : Number(e.target.value) })}
          >
            <option value="">— Tanpa PO —</option>
            {(orders?.data ?? []).map((po) => (
              <option key={po.id} value={po.id}>
                {po.number} — {po.vendor?.legal_name ?? ''}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tanggal invoice" error={errors.invoice_date?.[0]}>
          <Input type="date" value={form.invoice_date} onChange={(e) => setForm({ ...form, invoice_date: e.target.value })} />
        </FormField>
        <FormField label="Jatuh tempo" error={errors.due_date?.[0]}>
          <Input type="date" value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} />
        </FormField>
        <FormField label="Mata uang">
          <Input value={form.currency} maxLength={3} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })} />
        </FormField>
        <FormField label="Subtotal" error={errors.subtotal?.[0]}>
          <Input type="number" value={form.subtotal} onChange={(e) => setForm({ ...form, subtotal: e.target.value })} />
        </FormField>
        <FormField label="Pajak" error={errors.tax_amount?.[0]}>
          <Input type="number" value={form.tax_amount} onChange={(e) => setForm({ ...form, tax_amount: e.target.value })} />
        </FormField>
      </div>
      <p className="mt-3 text-[11px] text-slate-500">
        Setelah dicatat, jalankan pencocokan 3 arah sebelum menyetujui.
      </p>
    </Modal>
  );
}
