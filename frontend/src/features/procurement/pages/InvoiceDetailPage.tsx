import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, RefreshCw, Send, ShieldAlert } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { formatDate, formatRupiah } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { invoicesApi } from '../api/procurementApi';
import { DetailRow, FormField, SectionCard, StatusBadge } from '../components/ProcurementUi';
import { handoffStatus, invoiceStatus, matchStatus } from '../lib/labels';

/** Detail invoice: item, hasil pencocokan, integrasi akuntansi, dan status bayar. */
export default function InvoiceDetailPage() {
  const { invoiceId } = useParams();
  const id = Number(invoiceId);
  const qc = useQueryClient();
  const [bukaOverride, setBukaOverride] = useState(false);

  const { data: inv, isLoading } = useQuery({
    queryKey: ['procurement', 'invoice', id],
    queryFn: () => invoicesApi.show(id),
    enabled: Number.isFinite(id),
  });

  const { data: payment } = useQuery({
    queryKey: ['procurement', 'invoice', id, 'payment'],
    queryFn: () => invoicesApi.paymentStatus(id),
    enabled: Number.isFinite(id),
    retry: false,
  });

  const aksi = useMutation({
    mutationFn: (langkah: 'match' | 'approve' | 'handoff' | 'retry' | 'sync') => {
      if (langkah === 'match') return invoicesApi.match(id);
      if (langkah === 'approve') return invoicesApi.approve(id);
      if (langkah === 'handoff') return invoicesApi.handoff(id);
      if (langkah === 'retry') return invoicesApi.retryHandoff(id);
      return invoicesApi.syncStatus(id);
    },
    onSuccess: () => {
      toast.success('Status invoice diperbarui');
      qc.invalidateQueries({ queryKey: ['procurement'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (isLoading) {
    return (
      <div className="flex justify-center py-24">
        <Spinner />
      </div>
    );
  }

  if (!inv) return <EmptyState title="Invoice tidak ditemukan" />;

  const match = inv.matchResults?.[0];
  const integrasi = inv.integrations ?? [];

  return (
    <div className="space-y-4">
      <Link to="/procurement/invoices" className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-brand-600">
        <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke daftar invoice
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="font-mono text-lg font-semibold text-slate-800">{inv.number}</h1>
            <StatusBadge value={inv.status} map={invoiceStatus} />
            {match && <StatusBadge value={match.match_status} map={matchStatus} />}
          </div>
          <p className="mt-0.5 text-xs text-slate-500">
            {inv.vendor?.legal_name ?? '-'} · invoice vendor <span className="font-mono">{inv.vendor_invoice_number}</span>
            {inv.purchase_order?.number && <> · PO {inv.purchase_order.number}</>}
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Can perm="procurement.match_invoice">
            {['RECEIVED', 'UNDER_MATCHING', 'MISMATCH', 'EXCEPTION'].includes(inv.status) && (
              <>
                <Button size="sm" onClick={() => aksi.mutate('match')} loading={aksi.isPending}>
                  <RefreshCw className="mr-1 h-4 w-4" /> Cocokkan
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setBukaOverride(true)}>
                  <ShieldAlert className="mr-1 h-4 w-4" /> Override
                </Button>
              </>
            )}
            {inv.status === 'MATCHED' && (
              <Button size="sm" onClick={() => aksi.mutate('approve')} loading={aksi.isPending}>
                Setujui invoice
              </Button>
            )}
          </Can>
          <Can perm="procurement.send_to_accounting">
            {inv.status === 'APPROVED' && (
              <Button size="sm" onClick={() => aksi.mutate('handoff')} loading={aksi.isPending}>
                <Send className="mr-1 h-4 w-4" /> Kirim ke akuntansi
              </Button>
            )}
            {['SENT_TO_ACCOUNTING', 'POSTED'].includes(inv.status) && (
              <>
                <Button size="sm" variant="outline" onClick={() => aksi.mutate('sync')} loading={aksi.isPending}>
                  Sinkronkan status
                </Button>
                <Button size="sm" variant="ghost" onClick={() => aksi.mutate('retry')} loading={aksi.isPending}>
                  Kirim ulang
                </Button>
              </>
            )}
          </Can>
        </div>
      </header>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard title="Ringkasan">
          <DetailRow label="Tanggal invoice">{formatDate(inv.invoice_date)}</DetailRow>
          <DetailRow label="Jatuh tempo">{formatDate(inv.due_date)}</DetailRow>
          <DetailRow label="Mata uang">{inv.currency ?? 'IDR'}</DetailRow>
          <div className="mt-2 border-t border-slate-100 pt-2">
            <DetailRow label="Subtotal">{formatRupiah(inv.subtotal ?? 0)}</DetailRow>
            <DetailRow label="Pajak">{formatRupiah(inv.tax_amount ?? 0)}</DetailRow>
            <DetailRow label="Total">
              <span className="text-base font-semibold tabular-nums">{formatRupiah(inv.total ?? 0)}</span>
            </DetailRow>
          </div>
        </SectionCard>

        <SectionCard title="Hasil pencocokan 3 arah" className="lg:col-span-2">
          {!match ? (
            <EmptyState title="Belum dicocokkan" description="Jalankan pencocokan untuk membandingkan PO, penerimaan, dan invoice." />
          ) : (
            <>
              <div className="flex flex-wrap gap-2">
                <CekBadge label="Kuantitas" ok={match.quantity_ok} />
                <CekBadge label="Harga" ok={match.price_ok} />
                <CekBadge label="Nilai" ok={match.amount_ok} />
              </div>
              {match.differences && Object.keys(match.differences).length > 0 && (
                <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                  <p className="font-medium">Selisih terdeteksi</p>
                  <ul className="mt-1 space-y-0.5">
                    {Object.entries(match.differences).map(([k, v]) => (
                      <li key={k}>
                        {k.replace(/_/g, ' ')}: <span className="font-mono">{String(v)}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </>
          )}

          <div className="mt-4">
            <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Item invoice</h4>
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="py-2 font-medium">Deskripsi</th>
                  <th className="py-2 text-right font-medium">Jumlah</th>
                  <th className="py-2 text-right font-medium">Harga</th>
                  <th className="py-2 text-right font-medium">Subtotal</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(inv.items ?? []).map((it) => (
                  <tr key={it.id}>
                    <td className="py-2 text-slate-800">{it.description}</td>
                    <td className="py-2 text-right tabular-nums text-slate-600">
                      {it.quantity} {it.unit ?? ''}
                    </td>
                    <td className="py-2 text-right tabular-nums text-slate-600">{formatRupiah(it.unit_price ?? 0)}</td>
                    <td className="py-2 text-right tabular-nums text-slate-700">
                      {formatRupiah(Number(it.quantity) * Number(it.unit_price ?? 0))}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SectionCard title="Integrasi akuntansi">
          {integrasi.length === 0 ? (
            <EmptyState title="Belum ada serah terima" description="Kirim invoice ke akuntansi setelah disetujui." />
          ) : (
            <ul className="space-y-2">
              {integrasi.map((i) => (
                <li key={i.id} className="rounded-lg border border-slate-100 px-3 py-2">
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-slate-800">{i.provider}</span>
                    <StatusBadge value={i.status} map={handoffStatus} />
                  </div>
                  <p className="mt-0.5 font-mono text-[10px] text-slate-400">
                    kunci: {i.idempotency_key ?? '-'}
                    {i.reference && <> · ref: {i.reference}</>}
                  </p>
                  {i.error_message && <p className="mt-1 text-[11px] text-red-600">{i.error_message}</p>}
                </li>
              ))}
            </ul>
          )}
        </SectionCard>

        <SectionCard title="Status pembayaran">
          {!payment ? (
            <p className="text-xs text-slate-500">Status pembayaran belum tersedia.</p>
          ) : (
            <>
              <DetailRow label="Status">{payment.status}</DetailRow>
              <DetailRow label="Dibayar">{formatRupiah(payment.paid_amount ?? 0)}</DetailRow>
              <DetailRow label="Sisa">{formatRupiah(payment.outstanding ?? 0)}</DetailRow>
              <DetailRow label="Jatuh tempo">{formatDate(payment.due_date)}</DetailRow>
            </>
          )}
        </SectionCard>
      </div>

      {bukaOverride && <OverrideModal id={id} onClose={() => setBukaOverride(false)} />}
    </div>
  );
}

function CekBadge({ label, ok }: { label: string; ok?: boolean }) {
  return (
    <Badge tone={ok === false ? 'red' : 'green'}>
      {label}: {ok === false ? 'selisih' : 'sesuai'}
    </Badge>
  );
}

function OverrideModal({ id, onClose }: { id: number; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () => invoicesApi.override(id, { reason }),
    onSuccess: () => {
      toast.success('Pencocokan di-override');
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
      title="Override pencocokan"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!reason}>
            Override
          </Button>
        </div>
      }
    >
      <div className="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
        Override menandai selisih sebagai diterima secara sadar. Alasan wajib dan akan tercatat di audit.
      </div>
      <FormField label="Alasan override" required error={errors.reason?.[0]}>
        <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
