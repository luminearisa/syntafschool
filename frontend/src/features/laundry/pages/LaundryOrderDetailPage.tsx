import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import {
  ArrowLeft, ArrowRightLeft, BadgeCheck, CircleDollarSign, Layers, Package, Scale, ShieldAlert, Wallet,
} from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateTime, Field, Money, PageHeader, Panel, StatusPill, Weight, newIdempotencyKey, readError } from '../components/LaundryUi';
import { cn } from '@/lib/utils';

/**
 * Detail order + timeline traceability (§9, §12, §19–§23, §56/§57).
 *
 * Satu layar untuk seluruh aksi siklus order: terima, timbang, kalkulasi,
 * bayar/refund, antrekan, override beralasan, batal, label, dan jejak
 * status–pembayaran–QC–insiden–serah terima.
 */
export default function LaundryOrderDetailPage() {
  const { id } = useParams();
  const orderId = Number(id);
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');
  const [overrideType, setOverrideType] = useState<'WAIVE_FEE' | 'FORCE_PROCESS'>('WAIVE_FEE');
  const [rfidUid, setRfidUid] = useState('');

  const order = useQuery({
    queryKey: ['laundry', 'order', orderId],
    queryFn: () => laundryApi.order(orderId),
    enabled: Number.isFinite(orderId),
  });
  const timeline = useQuery({
    queryKey: ['laundry', 'order', orderId, 'timeline'],
    queryFn: () => laundryApi.orderTimeline(orderId),
    enabled: Number.isFinite(orderId),
  });
  const paymentPreview = useQuery({
    queryKey: ['laundry', 'order', orderId, 'payment-preview'],
    queryFn: () => laundryApi.paymentPreview(orderId),
    enabled: Number.isFinite(orderId),
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['laundry', 'order', orderId] });
    queryClient.invalidateQueries({ queryKey: ['laundry', 'orders'] });
    queryClient.invalidateQueries({ queryKey: ['laundry', 'dashboard'] });
  };

  const pay = useMutation({
    mutationFn: () => laundryApi.payOrder(orderId, {
      idempotency_key: newIdempotencyKey('pay'),
      rfid_uid: rfidUid.trim() || undefined,
    }),
    onSuccess: (result) => {
      toast.success(`Dibayar${result.replayed ? ' (replay idempoten — tidak double charge)' : ''}`);
      setRfidUid('');
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Pembayaran gagal')),
  });

  const refund = useMutation({
    mutationFn: () => laundryApi.refundOrder(orderId, { reason: reason.trim() }),
    onSuccess: () => { toast.success('Refund wallet berhasil'); setReason(''); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Refund gagal')),
  });

  const queue = useMutation({
    mutationFn: () => laundryApi.queueOrder(orderId),
    onSuccess: () => { toast.success('Masuk antrean proses'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal — order belum lunas? Gunakan override beralasan.')),
  });

  const cancel = useMutation({
    mutationFn: () => laundryApi.cancelOrder(orderId, { reason: reason.trim() }),
    onSuccess: () => { toast.success('Order dibatalkan — jatah kembali, dana direfund'); setReason(''); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal membatalkan')),
  });

  const override = useMutation({
    mutationFn: () => laundryApi.overrideOrder(orderId, { type: overrideType, reason: reason.trim() }),
    onSuccess: () => { toast.success('Override tersimpan & teraudit'); setReason(''); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Override gagal')),
  });

  if (order.isLoading || !order.data) {
    return <p className="text-sm text-slate-500">Memuat order…</p>;
  }

  const data = order.data;
  const tl = timeline.data;

  return (
    <div className="space-y-5">
      <Link to="/laundry/orders" className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition-colors hover:text-brand-700">
        <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke daftar order
      </Link>

      <PageHeader
        icon={<Package className="h-6 w-6" />}
        title={`Order ${data.order_number}`}
        description={<>{data.student_name ?? '—'} {data.student_nis ? `· NIS ${data.student_nis}` : ''} · {data.service ?? '—'}</>}
        actions={
          <>
            <StatusPill status={data.status} />
            <StatusPill status={data.payment_status} />
          </>
        }
      />

      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title="Timbangan & split" description="Perhitungan jatah gratis vs porsi berbayar (§12)">
          <dl className="space-y-0 text-sm">
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Bruto / tas / netto</dt><dd className="font-medium"><Weight value={data.gross_weight} /> / <Weight value={data.bag_weight} /> / <Weight value={data.net_weight} /></dd></div>
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Item</dt><dd className="font-medium">{data.item_count} potong</dd></div>
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Jatah gratis</dt><dd className="font-medium"><Weight value={data.free_quantity} /> <span className="text-xs text-slate-400">({data.quota_type ?? '—'})</span></dd></div>
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Porsi bayar</dt><dd className="font-medium"><Weight value={data.paid_quantity} /></dd></div>
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Tagihan</dt><dd className="font-semibold text-brand-700"><Money value={data.total_amount} /></dd></div>
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Wallet ref</dt><dd className="font-mono text-xs">{data.wallet_transaction_number ?? '—'}</dd></div>
            <div className="flex justify-between py-2"><dt className="text-slate-500">Tas</dt><dd className="font-mono text-xs">{data.bag_code ?? '—'}</dd></div>
          </dl>
          {data.allocations.length > 0 ? (
            <div className="mt-2 rounded-xl border border-slate-200 bg-slate-50/60 p-3">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Alokasi free / paid</p>
              <ul className="mt-2 space-y-1.5 text-sm">
                {data.allocations.map((a, i) => (
                  <li key={i} className="flex items-center justify-between">
                    <span className="flex items-center gap-1.5"><StatusPill status={a.type} /> <Weight value={a.quantity} /></span>
                    <Money value={a.amount} />
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </Panel>

        <Panel
          title="Pembayaran wallet"
          description="Idempoten — retry aman, tidak double charge (§22)"
          action={<CircleDollarSign className="h-4 w-4 text-slate-400" />}
        >
          {paymentPreview.data ? (
            <dl className="space-y-0 text-sm">
              <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Harus dibayar</dt><dd className="font-semibold text-brand-700"><Money value={paymentPreview.data.payable} /></dd></div>
              <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Saldo wallet</dt><dd className="font-medium"><Money value={paymentPreview.data.wallet?.balance} /></dd></div>
              <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Sisa setelah bayar</dt><dd className="font-medium"><Money value={paymentPreview.data.wallet?.after_payment} /></dd></div>
              <div className="flex justify-between py-2">
                <dt className="text-slate-500">Cukup?</dt>
                <dd className={cn('font-medium', paymentPreview.data.wallet?.sufficient ? 'text-emerald-600' : 'text-rose-600')}>
                  {paymentPreview.data.wallet ? (paymentPreview.data.wallet.sufficient ? 'Ya' : 'Tidak — beri tahu wali') : 'Tanpa wallet'}
                </dd>
              </div>
            </dl>
          ) : <p className="text-sm text-slate-500">Memuat pratinjau…</p>}
          {can('laundry.payment.view') && data.payment_status === 'PAYMENT_REQUIRED' ? (
            <div className="mt-3 space-y-2 rounded-xl border border-brand-200 bg-brand-50/50 p-3">
              <Field label="RFID santri (opsional — tap kartu)">
                <Input value={rfidUid} onChange={(e) => setRfidUid(e.target.value)} placeholder="UID kartu…" className="font-mono" />
              </Field>
              <Button className="w-full" onClick={() => pay.mutate()} loading={pay.isPending}>
                <Wallet className="h-4 w-4" /> Bayar via Wallet
              </Button>
            </div>
          ) : null}
          {can('laundry.refund') ? (
            <div className="mt-3 space-y-2 border-t border-slate-100 pt-3">
              <Field label="Alasan refund (wajib)">
                <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="cth. order batal…" />
              </Field>
              <Button variant="outline" className="w-full" onClick={() => refund.mutate()} loading={refund.isPending} disabled={!reason.trim()}>
                Refund ke wallet
              </Button>
            </div>
          ) : null}
        </Panel>

        <Panel
          title="Antrean & override"
          description="No-payment-no-processing, kecuali override beralasan (§56/§57)"
          action={<ShieldAlert className="h-4 w-4 text-slate-400" />}
        >
          <div className="space-y-3">
            {can('laundry.process') ? (
              <Button variant="secondary" className="w-full" onClick={() => queue.mutate()} loading={queue.isPending}>
                <ArrowRightLeft className="h-4 w-4" /> Masukkan antrean proses
              </Button>
            ) : null}
            {can('laundry.payment.override') ? (
              <div className="space-y-3 rounded-xl border border-amber-200 bg-amber-50/50 p-3">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-amber-700">Override beralasan (teraudit)</p>
                <Field label="Jenis override">
                  <Select value={overrideType} onChange={(e) => setOverrideType(e.target.value as 'WAIVE_FEE' | 'FORCE_PROCESS')}>
                    <option value="WAIVE_FEE">WAIVE_FEE — bebaskan tagihan</option>
                    <option value="FORCE_PROCESS">FORCE_PROCESS — proses tanpa bayar</option>
                  </Select>
                </Field>
                <Field label="Alasan (wajib)">
                  <Textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="cth. Emergency seragam upacara…" rows={2} />
                </Field>
                <Button variant="outline" className="w-full" onClick={() => override.mutate()} loading={override.isPending} disabled={!reason.trim()}>
                  Simpan override
                </Button>
              </div>
            ) : null}
            {can('laundry.cancel') ? (
              <div className="space-y-2 rounded-xl border border-rose-200 bg-rose-50/50 p-3">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-rose-700">Pembatalan</p>
                <Button variant="danger" className="w-full" onClick={() => cancel.mutate()} loading={cancel.isPending} disabled={!reason.trim()}>
                  Batalkan order
                </Button>
                <p className="text-xs leading-relaxed text-rose-600/80">Gunakan kolom alasan di atas. Jatah kembali & dana direfund otomatis.</p>
              </div>
            ) : null}
          </div>
        </Panel>
      </div>

      <Panel title="Item pakaian" description={`${data.items.length} baris`}>
        <DataTable
          rows={data.items}
          empty="Belum ada item tercatat."
          columns={[
            { key: 'type', header: 'Jenis', render: (r) => r.item_type },
            { key: 'qty', header: 'Qty', render: (r) => r.quantity },
            { key: 'color', header: 'Warna', render: (r) => r.color ?? '—' },
            { key: 'cond', header: 'Kondisi', render: (r) => r.condition ?? '—' },
            { key: 'price', header: 'Harga', render: (r) => r.unit_price != null ? <Money value={r.unit_price} /> : '—' },
            { key: 'tag', header: 'Tag', render: (r) => <span className="font-mono text-xs">{r.tag_code ?? '—'}</span> },
          ]}
        />
      </Panel>

      <Panel title="Timeline traceability" description="Status → proses → bayar → QC → insiden → serah terima">
        {timeline.isLoading || !tl ? (
          <p className="text-sm text-slate-500">Memuat timeline…</p>
        ) : (
          <div className="space-y-5">
            <div>
              <p className="mb-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Jejak status</p>
              <ol className="relative ml-3 space-y-4 border-l-2 border-slate-200 pl-6">
                {tl.status_logs.map((log, i) => (
                  <li key={i} className="relative">
                    <span className="absolute -left-[31px] flex h-5 w-5 items-center justify-center rounded-full border-2 border-brand-500 bg-white">
                      <span className="h-1.5 w-1.5 rounded-full bg-brand-500" />
                    </span>
                    <div className="flex flex-wrap items-center gap-2">
                      <StatusPill status={log.from ?? '—'} />
                      <span className="text-slate-400">→</span>
                      <StatusPill status={log.to} />
                      <span className="text-xs text-slate-400"><DateTime value={log.at} /></span>
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">{log.actor ?? 'sistem'}{log.reason ? ` · ${log.reason}` : ''}</p>
                  </li>
                ))}
                {tl.status_logs.length === 0 ? <li className="text-sm text-slate-500">Belum ada jejak status.</li> : null}
              </ol>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Pembayaran</p>
                {tl.payments.length === 0 ? <p className="text-sm text-slate-500">Belum ada pembayaran.</p> : (
                  <ul className="space-y-2 text-sm">
                    {tl.payments.map((p, i) => (
                      <li key={i} className="flex flex-wrap items-center gap-2">
                        <span className="font-medium"><Money value={p.amount} /></span>
                        <span className="text-slate-500">· {p.method}</span>
                        <StatusPill status={p.status} />
                        <span className="font-mono text-xs text-slate-400">{p.wallet_transaction ?? '—'}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                  <BadgeCheck className="h-3.5 w-3.5" /> Quality control
                </p>
                {tl.quality_checks.length === 0 ? <p className="text-sm text-slate-500">Belum ada QC.</p> : (
                  <ul className="space-y-2 text-sm">
                    {tl.quality_checks.map((q, i) => (
                      <li key={i} className="flex items-center gap-2"><StatusPill status={q.result} /> {q.notes ?? ''}</li>
                    ))}
                  </ul>
                )}
              </div>
              <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                  <Layers className="h-3.5 w-3.5" /> Langkah proses
                </p>
                {tl.processing_steps.length === 0 ? <p className="text-sm text-slate-500">Belum ada langkah.</p> : (
                  <ul className="space-y-1.5 text-sm">
                    {tl.processing_steps.map((s, i) => (
                      <li key={i} className="flex items-center gap-2">{s.step} <StatusPill status={s.status} /></li>
                    ))}
                  </ul>
                )}
              </div>
              <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                  <Scale className="h-3.5 w-3.5" /> Serah terima
                </p>
                {tl.handover.pickups.length === 0 && tl.handover.deliveries.length === 0 ? (
                  <p className="text-sm text-slate-500">Belum diserahterimakan.</p>
                ) : (
                  <ul className="space-y-1.5 text-sm">
                    {tl.handover.pickups.map((h, i) => <li key={`p${i}`}>Diambil: <span className="font-medium">{h.receiver}</span> <span className="text-xs text-slate-500">({h.method})</span></li>)}
                    {tl.handover.deliveries.map((h, i) => <li key={`d${i}`}>Diantar: <span className="font-medium">{h.receiver}</span> <span className="text-xs text-slate-500">({h.method})</span></li>)}
                  </ul>
                )}
              </div>
            </div>
          </div>
        )}
      </Panel>
    </div>
  );
}
