import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateTime, Field, Money, Panel, StatusPill, Weight, newIdempotencyKey, readError } from '../components/LaundryUi';

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
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Order {data.order_number}</h1>
          <p className="text-sm text-slate-500">
            {data.student_name ?? '—'} {data.student_nis ? `· NIS ${data.student_nis}` : ''} · {data.service ?? '—'}
          </p>
        </div>
        <div className="flex gap-2">
          <StatusPill status={data.status} />
          <StatusPill status={data.payment_status} />
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title="Timbangan & split">
          <dl className="space-y-1 text-sm">
            <div className="flex justify-between"><dt className="text-slate-500">Bruto / tas / netto</dt><dd><Weight value={data.gross_weight} /> / <Weight value={data.bag_weight} /> / <Weight value={data.net_weight} /></dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Item</dt><dd>{data.item_count} potong</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Jatah gratis</dt><dd><Weight value={data.free_quantity} /> ({data.quota_type ?? '—'})</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Porsi bayar</dt><dd><Weight value={data.paid_quantity} /></dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Tagihan</dt><dd className="font-semibold"><Money value={data.total_amount} /></dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Wallet ref</dt><dd className="font-mono text-xs">{data.wallet_transaction_number ?? '—'}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Tas</dt><dd>{data.bag_code ?? '—'}</dd></div>
          </dl>
          {data.allocations.length > 0 ? (
            <div className="mt-3">
              <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Alokasi free/paid (§12)</p>
              <ul className="mt-1 space-y-1 text-sm">
                {data.allocations.map((a, i) => (
                  <li key={i} className="flex justify-between">
                    <span><StatusPill status={a.type} /> <Weight value={a.quantity} /></span>
                    <Money value={a.amount} />
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </Panel>

        <Panel title="Pembayaran wallet" description="Idempoten — retry aman, tidak double charge (§22)">
          {paymentPreview.data ? (
            <dl className="space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-slate-500">Harus dibayar</dt><dd className="font-semibold"><Money value={paymentPreview.data.payable} /></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">Saldo wallet</dt><dd><Money value={paymentPreview.data.wallet?.balance} /></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">Sisa setelah bayar</dt><dd><Money value={paymentPreview.data.wallet?.after_payment} /></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">Cukup?</dt><dd>{paymentPreview.data.wallet ? (paymentPreview.data.wallet.sufficient ? 'Ya' : 'Tidak — beri tahu wali') : 'Tanpa wallet'}</dd></div>
            </dl>
          ) : <p className="text-sm text-slate-500">Memuat pratinjau…</p>}
          {can('laundry.payment.view') && data.payment_status === 'PAYMENT_REQUIRED' ? (
            <div className="mt-3 space-y-2">
              <Field label="RFID santri (opsional — tap kartu)">
                <Input value={rfidUid} onChange={(e) => setRfidUid(e.target.value)} placeholder="UID kartu…" />
              </Field>
              <Button onClick={() => pay.mutate()} loading={pay.isPending}>Bayar via Wallet</Button>
            </div>
          ) : null}
          {can('laundry.refund') ? (
            <div className="mt-3 space-y-2 border-t border-slate-100 pt-3">
              <Field label="Alasan refund (wajib)">
                <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="cth. order batal…" />
              </Field>
              <Button variant="outline" onClick={() => refund.mutate()} loading={refund.isPending} disabled={!reason.trim()}>
                Refund ke wallet
              </Button>
            </div>
          ) : null}
        </Panel>

        <Panel title="Antrean & override" description="No-payment-no-processing, kecuali override beralasan (§56/§57)">
          <div className="space-y-2">
            {can('laundry.process') ? (
              <Button variant="secondary" onClick={() => queue.mutate()} loading={queue.isPending}>Masukkan antrean</Button>
            ) : null}
            {can('laundry.payment.override') ? (
              <>
                <Field label="Jenis override">
                  <Select value={overrideType} onChange={(e) => setOverrideType(e.target.value as 'WAIVE_FEE' | 'FORCE_PROCESS')}>
                    <option value="WAIVE_FEE">WAIVE_FEE — bebaskan tagihan</option>
                    <option value="FORCE_PROCESS">FORCE_PROCESS — proses tanpa bayar</option>
                  </Select>
                </Field>
                <Field label="Alasan (wajib, teraudit)">
                  <Textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="cth. Emergency seragam upacara…" />
                </Field>
                <Button variant="outline" onClick={() => override.mutate()} loading={override.isPending} disabled={!reason.trim()}>
                  Simpan override
                </Button>
              </>
            ) : null}
            {can('laundry.cancel') ? (
              <div className="border-t border-slate-100 pt-2">
                <Button variant="danger" onClick={() => cancel.mutate()} loading={cancel.isPending} disabled={!reason.trim()}>
                  Batalkan order
                </Button>
                <p className="mt-1 text-xs text-slate-500">Alasan pakai kolom yang sama — jatah kembali & dana direfund.</p>
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
          <div className="space-y-4">
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Jejak status</p>
              <ol className="mt-1 space-y-1 text-sm">
                {tl.status_logs.map((log, i) => (
                  <li key={i} className="flex flex-wrap gap-2">
                    <span className="text-slate-400">{i + 1}.</span>
                    <StatusPill status={log.from ?? '—'} />→<StatusPill status={log.to} />
                    <span className="text-slate-500">{log.actor ?? 'sistem'}{log.reason ? ` · ${log.reason}` : ''}</span>
                    <span className="text-xs text-slate-400"><DateTime value={log.at} /></span>
                  </li>
                ))}
              </ol>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Pembayaran</p>
                {tl.payments.length === 0 ? <p className="text-sm text-slate-500">Belum ada pembayaran.</p> : (
                  <ul className="mt-1 space-y-1 text-sm">
                    {tl.payments.map((p, i) => (
                      <li key={i}><Money value={p.amount} /> · {p.method} · <StatusPill status={p.status} /> · <span className="font-mono text-xs">{p.wallet_transaction ?? '—'}</span></li>
                    ))}
                  </ul>
                )}
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">QC</p>
                {tl.quality_checks.length === 0 ? <p className="text-sm text-slate-500">Belum ada QC.</p> : (
                  <ul className="mt-1 space-y-1 text-sm">
                    {tl.quality_checks.map((q, i) => (
                      <li key={i}><StatusPill status={q.result} /> {q.notes ?? ''}</li>
                    ))}
                  </ul>
                )}
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Langkah proses</p>
                {tl.processing_steps.length === 0 ? <p className="text-sm text-slate-500">Belum ada langkah.</p> : (
                  <ul className="mt-1 space-y-1 text-sm">
                    {tl.processing_steps.map((s, i) => (
                      <li key={i}>{s.step} · <StatusPill status={s.status} /></li>
                    ))}
                  </ul>
                )}
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Serah terima</p>
                {tl.handover.pickups.length === 0 && tl.handover.deliveries.length === 0 ? (
                  <p className="text-sm text-slate-500">Belum diserahterimakan.</p>
                ) : (
                  <ul className="mt-1 space-y-1 text-sm">
                    {tl.handover.pickups.map((h, i) => <li key={`p${i}`}>Diambil: {h.receiver} ({h.method})</li>)}
                    {tl.handover.deliveries.map((h, i) => <li key={`d${i}`}>Diantar: {h.receiver} ({h.method})</li>)}
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
